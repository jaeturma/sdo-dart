#!/usr/bin/env python3
"""
Signs a PDF with one or more independent, incremental-update digital
signatures from a single PKCS#12 credential.

Each entry in --placements becomes its own real, independently-verifiable
signature (not one signature with cosmetic echoes, which is what the old
TCPDF-based pipeline did) - the whole point of using pyhanko instead of
FPDI/TCPDF is that each addsig-equivalent call appends a new PDF revision
without disturbing anything written before it, so N placements from the
same signer, or signatures from N different signers chained one after
another, all remain independently valid.

Called from PHP via App\\Services\\PyhankoPdfSigner - see that class for the
exact argv shape. This script owns the internal placement loop; PHP always
makes exactly one Process::run() call per document per signer, regardless
of how many placements that signer wants.

Exit codes (PyhankoPdfSigner::apply() translates these into
\\RuntimeException messages - see that class for the exact mapping):
  0  success
  10 bad P12 password / corrupt certificate file
  12 could not write the output file
  1  any other unexpected failure (traceback on stderr)
"""

import argparse
import json
import os
import sys
import tempfile
import traceback
from datetime import datetime, timezone

from PIL import Image, ImageDraw, ImageFont
from pyhanko.pdf_utils import generic
from pyhanko.pdf_utils import writer as pyhanko_writer
from pyhanko.pdf_utils.incremental_writer import IncrementalPdfFileWriter
from pyhanko.pdf_utils.images import PdfImage
from pyhanko.pdf_utils.reader import PdfFileReader
from pyhanko.pdf_utils.text import TextBoxStyle
from pyhanko.sign.fields import SigFieldSpec, SigSeedSubFilter
from pyhanko.sign.signers import PdfSignatureMetadata, SimpleSigner
from pyhanko.sign.signers.pdf_signer import PdfSigner
from pyhanko.stamp import TextStampStyle


def _patch_pyhanko_bbox_bug():
    """
    Works around a confirmed bug in pyhanko 0.37.0 (the latest release as of
    this writing - checked via `pip index versions pyHanko`):
    `pyhanko.pdf_utils.writer.init_xobject_dictionary()` builds a Form
    XObject's /BBox as `(0, height, width, 0)` instead of the PDF spec's
    (llx, lly, urx, ury) = `(0, 0, width, height)` - i.e. the two Y corners
    are swapped, so /BBox claims the bottom edge (44, say) is *above* the
    top edge (0). Verified directly: `AppearanceContent.as_form_xobject()`
    (pdf_utils/content.py) re-imports this function fresh on every call
    (not a module-level import cached elsewhere), so patching the attribute
    on its defining module, before any signing happens, reliably affects
    every stamp/signature appearance this script generates - confirmed via
    a real generated PDF's /AcroForm before and after this patch.

    A lenient "just paint the page" renderer tolerates the inverted range
    and displays the content anyway (matches the "signature visible while
    the PDF is still loading" half of the reported symptom); a stricter
    forms-processing pass - which Chrome/PDFium runs once the AcroForm is
    fully parsed - treats an inverted Y range as a degenerate/empty clip
    region and hides the content entirely (matches "disappears once
    loading completes"). Safe to remove once upstream pyhanko ships a fix.
    """
    original = pyhanko_writer.init_xobject_dictionary

    def patched(command_stream, box_width, box_height, resources=None):
        xobj = original(command_stream, box_width, box_height, resources)
        xobj['/BBox'] = generic.ArrayObject(
            list(map(generic.FloatObject, (0.0, 0.0, box_width, box_height)))
        )
        return xobj

    pyhanko_writer.init_xobject_dictionary = patched


_patch_pyhanko_bbox_bug()

EXIT_SUCCESS = 0
EXIT_BAD_PASSWORD = 10
EXIT_WRITE_FAILED = 12
EXIT_UNEXPECTED = 1


class SigningError(Exception):
    def __init__(self, exit_code, message):
        super().__init__(message)
        self.exit_code = exit_code


def parse_args():
    p = argparse.ArgumentParser(description=__doc__)
    p.add_argument('--input', required=True)
    p.add_argument('--output', required=True)
    p.add_argument('--p12', required=True)
    p.add_argument('--passfile', required=True)
    p.add_argument('--placements', required=True,
                    help='JSON array of {page, x_percent, y_percent, width_percent}')
    p.add_argument('--esig-image', default=None)
    p.add_argument('--signer-name', required=True)
    p.add_argument('--reason', default='')
    p.add_argument('--location', default='')
    p.add_argument('--signed-at', required=True, help='ISO 8601 timestamp')
    p.add_argument('--include-details', action='store_true')
    return p.parse_args()


def load_signer(p12_path, passfile_path):
    with open(passfile_path, 'rb') as f:
        password = f.read()

    # load_pkcs12's `pfx_file` param is a path, not raw bytes - it opens the
    # file itself internally. It also swallows most failures and returns
    # None rather than raising, so both paths need to map to exit 10.
    try:
        signer = SimpleSigner.load_pkcs12(pfx_file=p12_path, passphrase=password)
    except Exception as e:
        raise SigningError(
            EXIT_BAD_PASSWORD,
            f'Invalid P12 password or corrupted certificate file: {e}',
        )

    if signer is None:
        raise SigningError(
            EXIT_BAD_PASSWORD,
            'Invalid P12 password or corrupted certificate file.',
        )

    return signer


def get_page_boxes(pdf_path):
    """
    Returns an ordered list of (width, height) in PDF points, one per page,
    walking the page tree and inheriting /MediaBox from ancestor /Pages
    nodes when a leaf page doesn't define its own - a plain
    root['/Pages']['/Kids'] walk isn't enough for PDFs with a nested page
    tree or an inherited (rather than per-page) MediaBox.

    strict=False (see also sign_one_placement()'s IncrementalPdfFileWriter):
    a real production PDF was found to have a malformed xref entry for
    object ID 0 (the free-list head, never real content) with generation
    65536 - one past the valid 16-bit maximum of 65535 - which pyhanko's
    default strict reader rejects outright via PdfStrictReadError. Passing
    strict=False makes pyhanko skip that single out-of-range entry instead
    of refusing to open the file at all (pdf_utils/xref.py's
    XRefSectionData.process_entries(): `if generation > 0xFFFF: if strict:
    raise ... else: continue`) - safe here since object 0 carries no real
    content to lose. This is a different, narrower class of non-compliant
    PDF than PdfNormalizer/mutool's compressed-xref-stream problem.
    """
    boxes = []

    def walk(node, inherited_box):
        box = node.get('/MediaBox', inherited_box)
        node_type = node.get('/Type')
        if node_type == '/Pages' or '/Kids' in node:
            for kid_ref in node['/Kids']:
                walk(kid_ref.get_object(), box)
        else:
            boxes.append(box)

    with open(pdf_path, 'rb') as f:
        reader = PdfFileReader(f, strict=False)
        walk(reader.root['/Pages'], None)

    return [(float(b[2]) - float(b[0]), float(b[3]) - float(b[1])) for b in boxes]


def placement_box(page_width, page_height, x_percent, y_percent, width_percent, height_ratio):
    """
    Converts the percent-based, top-left-origin placement Sign.vue collects
    into pyhanko's absolute-point, bottom-left-origin box. `height_ratio`
    (height/width) must match the *actual* aspect ratio of whatever will be
    drawn in this box (see build_composite_stamp_image()) - a fixed ratio
    here (an earlier version used a flat 0.35, copied from the old TCPDF
    code's *invisible signature-widget hitbox* sizing, not its real visual
    content) would size the box independently of the real e-signature
    image's own proportions, which is exactly what made the box Sign.vue
    previews not match what actually got drawn.
    """
    sig_w = (width_percent / 100.0) * page_width
    sig_h = sig_w * height_ratio
    x1 = (x_percent / 100.0) * page_width
    x2 = x1 + sig_w
    y2 = page_height - (y_percent / 100.0) * page_height
    y1 = y2 - sig_h
    return x1, y1, x2, y2


def format_signed_at(iso_timestamp):
    """
    Mirrors the exact format DocumentSigner.php used to print:
    "Y.m.d H:i:s +08'00'" (quote-delimited offset, not +08:00) - matches the
    classic Adobe-style stamp convention this app has always used.
    """
    dt = datetime.fromisoformat(iso_timestamp)
    offset = dt.strftime('%z')  # e.g. '+0800'
    offset = f"{offset[:3]}'{offset[3:]}'" if offset else ''
    return f"{dt.strftime('%Y.%m.%d %H:%M:%S')} {offset}".strip()


def to_rgba(image):
    """
    The real e-signature images stored by this app include at least one
    palette-mode PNG with a transparency table (not RGBA) - pyhanko's
    PdfImage does not resolve that correctly on its own and renders the
    "transparent" area as a solid gray fill instead. Explicitly converting
    to RGBA forces PIL to resolve the palette + transparency table into
    real per-pixel alpha, which then composites/embeds correctly.
    """
    return image.convert('RGBA')


def wrap_text_lines(draw, logical_lines, font, max_width_px):
    """
    Greedy word-wrap, mirroring TCPDF's MultiCell() auto-wrap behavior -
    a line that doesn't fit the available width flows onto additional
    lines rather than being clipped at the canvas edge (which is what
    drawing pre-'\\n'-joined text with no wrapping did before: it just drew
    past the canvas boundary and those pixels were silently lost). Each
    logical line (e.g. one of "Digitally signed by" / the name / the date)
    is wrapped independently, so they never merge into each other. This is
    the only wrapping mechanism - font size is chosen from the placement's
    own width (see build_composite_stamp_image()), not shrunk to dodge
    wrapping, so an unusually long name or timestamp simply flows onto an
    extra line, exactly like TCPDF's MultiCell() used to.
    """
    wrapped = []
    for logical_line in logical_lines:
        words = logical_line.split(' ')
        current = ''
        for word in words:
            candidate = f'{current} {word}'.strip()
            if not current or draw.textlength(candidate, font=font) <= max_width_px:
                current = candidate
            else:
                wrapped.append(current)
                current = word
        wrapped.append(current)
    return wrapped


def build_composite_stamp_image(esig_image_path, signer_name, signed_at_text, include_details, sig_width_points):
    """
    Builds a single transparent-background RGBA image for the stamp
    appearance - image on the left ~40% of the box, "Digitally signed by /
    Date:" text filling the rest, mirroring DocumentSigner.php's original
    TCPDF layout (image-left, details-text-right) exactly, rather than
    relying on pyhanko's own auto-centered stamp-text-over-background
    layout, which draws the text on top of the whole box instead of beside
    the image. Built on a fully transparent canvas (not flattened onto
    white) so only the signature strokes and text are visible, the same
    way the old TCPDF Image()/MultiCell() calls drew directly onto the
    existing page content without covering anything.

    The canvas is rendered at a fixed PIXELS_PER_POINT density matching
    `sig_width_points` (this placement's real width in the final PDF, in
    points) - not an arbitrary fixed pixel resolution - specifically so the
    font size can be chosen in real, absolute PDF points rather than a
    fraction of an arbitrary canvas resolution.

    Font size itself scales with `sig_width_points`, clamped to [4, 14]pt -
    this restores the original pre-pyhanko TCPDF formula
    (`max(4, min(14, $sigWidth_mm * 0.15))`, see DocumentSigner.php's git
    history before the pyhanko migration) that Sign.vue's/ReleaseStamp.vue's
    own `detailsFontSizePx` preview computed properties already assume -
    enlarging/shrinking a placement enlarges/shrinks its details text with
    it, exactly as previewed. An earlier version of this function pinned
    the font to a fixed 10pt regardless of box width on the theory that a
    signature stamp's text should read like a rubber stamp - but a real
    stamp resized bigger prints everything bigger, image and text together;
    pinning only the text meant any placement much wider than the ~20% a
    secretary draws by default left the details looking tiny next to a
    now-oversized signature mark, and any placement wanting compact text
    had it shrunk anyway once the fixed size didn't fit the box's fixed 60%
    text column. Wrapping (wrap_text_lines()) handles whatever doesn't fit
    on one line at this size, same as TCPDF's MultiCell() always did.
    """
    esig_image = to_rgba(Image.open(esig_image_path)) if esig_image_path else None

    if not include_details:
        return esig_image  # natural aspect ratio, full box width - matches Sign.vue's "Plain" style

    pixels_per_point = 4
    canvas_width = max(1, int(sig_width_points * pixels_per_point))

    mm_per_point = 1 / 2.834645669291339  # 25.4mm / 72pt
    font_size_points = max(4.0, min(14.0, (sig_width_points * mm_per_point) * 0.15))
    font_size_px = max(1, int(font_size_points * pixels_per_point))

    image_width = int(canvas_width * 0.4)
    gap = int(canvas_width * 0.02)
    text_x = image_width + gap
    text_width = max(1, canvas_width - text_x)

    resized = None
    image_height = 0
    if esig_image is not None:
        scale = image_width / esig_image.width
        resized = esig_image.resize((image_width, max(1, int(esig_image.height * scale))))
        image_height = resized.height

    logical_lines = ['Digitally signed by', signer_name, f'Date: {signed_at_text}']

    font = find_font(font_size_px)
    line_spacing = int(font_size_px * 0.4)
    line_height = font_size_px + line_spacing

    probe_draw = ImageDraw.Draw(Image.new('RGBA', (1, 1)))
    wrapped_lines = wrap_text_lines(probe_draw, logical_lines, font, text_width)
    text_block_height = line_height * len(wrapped_lines)

    canvas_height = max(image_height, text_block_height, 1)
    canvas = Image.new('RGBA', (canvas_width, canvas_height), (0, 0, 0, 0))

    if resized is not None:
        canvas.alpha_composite(resized, (0, (canvas_height - image_height) // 2))

    draw = ImageDraw.Draw(canvas)
    text_y = (canvas_height - text_block_height) // 2
    for i, line in enumerate(wrapped_lines):
        draw.text((text_x, text_y + i * line_height), line, fill=(0, 0, 0, 255), font=font)

    return canvas


def find_font(size):
    candidates = [
        'C:/Windows/Fonts/arial.ttf',
        '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
        '/usr/share/fonts/truetype/liberation/LiberationSans-Regular.ttf',
        '/System/Library/Fonts/Helvetica.ttc',
    ]

    for path in candidates:
        if os.path.isfile(path):
            try:
                return ImageFont.truetype(path, size)
            except OSError:
                continue

    return ImageFont.load_default()


def build_stamp_style(composite):
    background = None
    if composite is not None:
        background = PdfImage(composite)

    return TextStampStyle(
        background=background,
        background_opacity=1.0,
        border_width=0,
        stamp_text='',
        text_box_style=TextBoxStyle(font_size=8),
    )


def sign_one_placement(source_path, output_path, signer, stamp_style,
                        field_name, box, page_index, reason, location, signer_name):
    meta = PdfSignatureMetadata(
        field_name=field_name,
        md_algorithm='sha256',
        subfilter=SigSeedSubFilter.PADES,
        reason=reason or None,
        location=location or None,
        name=signer_name or None,
    )
    field_spec = SigFieldSpec(sig_field_name=field_name, on_page=page_index, box=box)
    pdf_signer = PdfSigner(meta, signer, stamp_style=stamp_style, new_field_spec=field_spec)

    with open(source_path, 'rb') as inf:
        # strict=False: see get_page_boxes()'s docstring - a source file
        # that reader could only open leniently would fail identically here
        # otherwise, since this is the same underlying xref parser.
        writer = IncrementalPdfFileWriter(inf, strict=False)
        with open(output_path, 'wb') as outf:
            pdf_signer.sign_pdf(writer, output=outf)


def main():
    args = parse_args()

    if not os.path.isfile(args.input):
        raise SigningError(EXIT_UNEXPECTED, f'Input file not found: {args.input}')

    placements = json.loads(args.placements)
    if not placements:
        raise SigningError(EXIT_UNEXPECTED, 'At least one placement is required.')

    signer = load_signer(args.p12, args.passfile)

    page_boxes = get_page_boxes(args.input)
    signed_at_text = format_signed_at(args.signed_at)

    current_input = args.input
    chain_temp_files = []

    try:
        for i, placement in enumerate(placements):
            page_number = int(placement['page'])
            page_index = max(0, min(page_number - 1, len(page_boxes) - 1))
            page_width, page_height = page_boxes[page_index]

            sig_width_points = (float(placement['width_percent']) / 100.0) * page_width

            # Built per-placement, not once for the whole call: font sizing
            # is anchored to this specific placement's real width in points
            # (see build_composite_stamp_image()'s docstring) so a narrow
            # placement still gets legible text instead of everything
            # shrinking in lockstep with the box. The resulting image's own
            # width:height ratio then sizes this placement's box, so what
            # Sign.vue previewed while positioning it matches what actually
            # gets drawn.
            composite = build_composite_stamp_image(
                args.esig_image, args.signer_name, signed_at_text, args.include_details, sig_width_points,
            )
            height_ratio = (composite.height / composite.width) if composite is not None else 0.35
            stamp_style = build_stamp_style(composite)

            box = placement_box(
                page_width, page_height,
                float(placement['x_percent']), float(placement['y_percent']),
                float(placement['width_percent']), height_ratio,
            )

            is_last = (i == len(placements) - 1)
            if is_last:
                step_output = args.output
            else:
                fd, step_output = tempfile.mkstemp(suffix='.pdf', prefix='pyhanko_chain_')
                os.close(fd)
                chain_temp_files.append(step_output)

            field_name = f'Signature{i + 1}_{os.urandom(4).hex()}'

            try:
                sign_one_placement(
                    current_input, step_output, signer, stamp_style,
                    field_name, box, page_index, args.reason, args.location, args.signer_name,
                )
            except Exception as e:
                raise SigningError(EXIT_WRITE_FAILED, f'Failed to write signed output: {e}')

            current_input = step_output

        if not os.path.isfile(args.output) or os.path.getsize(args.output) == 0:
            raise SigningError(EXIT_WRITE_FAILED, 'Signed output file was not produced.')

    finally:
        for temp_path in chain_temp_files:
            try:
                os.unlink(temp_path)
            except OSError:
                pass


if __name__ == '__main__':
    try:
        main()
    except SigningError as e:
        print(str(e), file=sys.stderr)
        sys.exit(e.exit_code)
    except Exception:
        traceback.print_exc(file=sys.stderr)
        sys.exit(EXIT_UNEXPECTED)

    sys.exit(EXIT_SUCCESS)
