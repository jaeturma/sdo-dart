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
from dataclasses import dataclass
from datetime import datetime, timezone

from PIL import Image, ImageDraw, ImageFont
from pyhanko.pdf_utils import generic, layout
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


@dataclass(frozen=True)
class PageGeometry:
    """
    A page's visible area in default user space (`llx`/`lly`/`urx`/`ury`,
    PDF points, bottom-left origin) plus its normalized /Rotate (0, 90, 180
    or 270, clockwise when displayed).

    Sign.vue/ReleaseStamp.vue position placements on the page *as pdf.js
    displays it* - i.e. already rotated by /Rotate and clipped to the
    CropBox - so every percent coming in from PHP is relative to that
    displayed page, not to the raw MediaBox. Scanned documents commonly
    store an upright portrait page as a landscape MediaBox plus
    `/Rotate 270` (a real production document did exactly this): treating
    the percentages as raw-MediaBox-relative put the signature on the wrong
    part of the page, drawn sideways.
    """

    llx: float
    lly: float
    urx: float
    ury: float
    rotation: int

    @property
    def display_width(self):
        w = self.urx - self.llx
        return (self.ury - self.lly) if self.rotation in (90, 270) else w

    @property
    def display_height(self):
        h = self.ury - self.lly
        return (self.urx - self.llx) if self.rotation in (90, 270) else h

    def display_to_user(self, u, v):
        """
        Maps a point on the displayed page (`u` points from its left edge,
        `v` points from its top edge) back into default user space - the
        inverse of the viewport transform pdf.js applies when rendering
        (see its PageViewport), so it matches exactly what the secretary saw.
        """
        if self.rotation == 90:
            return self.llx + v, self.lly + u
        if self.rotation == 180:
            return self.urx - u, self.lly + v
        if self.rotation == 270:
            return self.urx - v, self.ury - u
        return self.llx + u, self.ury - v


def normalize_rotation(value):
    try:
        rotation = int(value) % 360
    except (TypeError, ValueError):
        return 0
    return rotation if rotation in (0, 90, 180, 270) else 0


def normalize_box(box):
    x1, y1, x2, y2 = (float(c) for c in box)
    return min(x1, x2), min(y1, y2), max(x1, x2), max(y1, y2)


def get_page_geometries(pdf_path):
    """
    Returns an ordered list of PageGeometry, one per page, walking the page
    tree and inheriting /MediaBox, /CropBox and /Rotate from ancestor
    /Pages nodes when a leaf page doesn't define its own - a plain
    root['/Pages']['/Kids'] walk isn't enough for PDFs with a nested page
    tree or inherited (rather than per-page) attributes. The visible area is
    the CropBox clipped to the MediaBox, the same region pdf.js renders
    (its `page.view`).

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
    pages = []
    inheritable = ('/MediaBox', '/CropBox', '/Rotate')

    def walk(node, inherited):
        # `node[key]`, not `node.get(key)`: pyhanko's DictionaryObject only
        # dereferences indirect objects in __getitem__, and dict.get()
        # bypasses it.
        inherited = {key: (node[key] if key in node else inherited[key]) for key in inheritable}
        node_type = node.get('/Type')
        if node_type == '/Pages' or '/Kids' in node:
            for kid_ref in node['/Kids']:
                walk(kid_ref.get_object(), inherited)
        else:
            pages.append(inherited)

    with open(pdf_path, 'rb') as f:
        reader = PdfFileReader(f, strict=False)
        walk(reader.root['/Pages'], {'/MediaBox': None, '/CropBox': None, '/Rotate': 0})

        geometries = []
        for attrs in pages:
            # US Letter is the spec's fallback when a (non-compliant) page
            # has no MediaBox anywhere in its ancestry.
            media = normalize_box(attrs['/MediaBox']) if attrs['/MediaBox'] is not None else (0.0, 0.0, 612.0, 792.0)
            visible = media
            if attrs['/CropBox'] is not None:
                crop = normalize_box(attrs['/CropBox'])
                clipped = (max(media[0], crop[0]), max(media[1], crop[1]),
                           min(media[2], crop[2]), min(media[3], crop[3]))
                if clipped[2] > clipped[0] and clipped[3] > clipped[1]:
                    visible = clipped
            geometries.append(PageGeometry(*visible, rotation=normalize_rotation(attrs['/Rotate'])))

    return geometries


def placement_box(geometry, x_percent, y_percent, width_percent, height_ratio):
    """
    Converts the percent-based, top-left-origin placement Sign.vue collects
    (relative to the page as displayed - see PageGeometry) into pyhanko's
    absolute-point, bottom-left-origin box in default user space. On a
    rotated page the returned box's width/height are swapped relative to
    what's displayed; RotationAwareStampStyle compensates for that when
    drawing the appearance. `height_ratio`
    (height/width) must match the *actual* aspect ratio of whatever will be
    drawn in this box (see build_composite_stamp_image()) - a fixed ratio
    here (an earlier version used a flat 0.35, copied from the old TCPDF
    code's *invisible signature-widget hitbox* sizing, not its real visual
    content) would size the box independently of the real e-signature
    image's own proportions, which is exactly what made the box Sign.vue
    previews not match what actually got drawn.
    """
    sig_w = (width_percent / 100.0) * geometry.display_width
    sig_h = sig_w * height_ratio
    u1 = (x_percent / 100.0) * geometry.display_width
    v1 = (y_percent / 100.0) * geometry.display_height

    xa, ya = geometry.display_to_user(u1, v1)
    xb, yb = geometry.display_to_user(u1 + sig_w, v1 + sig_h)
    return min(xa, xb), min(ya, yb), max(xa, xb), max(ya, yb)


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

    # 8, not 4: the embedded PDF data at 4px/pt is byte-perfect (verified by
    # extracting the actual embedded image object and comparing pixel
    # values directly against the source), but a typical PDF viewer
    # rendering a small placement (e.g. an 8-20%-wide release stamp) at
    # ordinary screen resolution (~150dpi-equivalent) anti-aliases thin
    # e-sig/stamp detail down toward the page background more than the old
    # TCPDF pipeline's embedding did - confirmed by rendering the identical
    # output at 600dpi, where the color comes back almost exactly to the
    # source. Doubling the internal resolution here gives the viewer more
    # source detail to anti-alias from, measurably narrowing (not fully
    # closing) that gap, for a modest embedded-image size increase.
    pixels_per_point = 8
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


@dataclass(frozen=True)
class RotationAwareStampStyle(TextStampStyle):
    """
    A widget's appearance stream is drawn in the page's *unrotated* user
    space, so on a page with /Rotate it gets rotated right along with the
    page when displayed - the signature showed up sideways on a
    `/Rotate 270` scan. pyhanko has no handling for this (its only knob,
    VisibleSigSettings.rotate_with_page=False, sets the NoRotate flag, which
    pivots the widget around its corner and is inconsistently honored by
    viewers). Instead, lay the stamp out in the *displayed* orientation
    (width/height swapped back for 90/270) and give the form XObject a
    /Matrix that counter-rotates it by the page's rotation; per ISO 32000-1
    § 12.5.5 the viewer then fits the rotated BBox into the widget's /Rect,
    so after the page itself is rotated the stamp reads upright.
    """

    page_rotation: int = 0

    def create_stamp(self, writer, box, text_params):
        if self.page_rotation in (90, 270):
            box = layout.BoxConstraints(width=box.height, height=box.width)

        stamp = super().create_stamp(writer, box, text_params)

        if self.page_rotation:
            build_form_xobject = stamp.as_form_xobject
            rotation = self.page_rotation

            def rotated_form_xobject():
                xobj = build_form_xobject()
                w, h = stamp.box.width, stamp.box.height
                # Counter-clockwise rotation by `rotation` degrees, translated
                # so the rotated BBox sits back at the origin.
                matrix = {
                    90: (0, 1, -1, 0, h, 0),
                    180: (-1, 0, 0, -1, w, h),
                    270: (0, -1, 1, 0, 0, w),
                }[rotation]
                xobj['/Matrix'] = generic.ArrayObject(list(map(generic.FloatObject, matrix)))
                return xobj

            stamp.as_form_xobject = rotated_form_xobject

        return stamp


def build_stamp_style(composite, page_rotation):
    background = None
    if composite is not None:
        background = PdfImage(composite)

    return RotationAwareStampStyle(
        background=background,
        background_opacity=1.0,
        border_width=0,
        stamp_text='',
        text_box_style=TextBoxStyle(font_size=8),
        page_rotation=page_rotation,
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
        # strict=False: see get_page_geometries()'s docstring - a source file
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

    page_geometries = get_page_geometries(args.input)
    signed_at_text = format_signed_at(args.signed_at)

    current_input = args.input
    chain_temp_files = []

    try:
        for i, placement in enumerate(placements):
            page_number = int(placement['page'])
            page_index = max(0, min(page_number - 1, len(page_geometries) - 1))
            geometry = page_geometries[page_index]

            sig_width_points = (float(placement['width_percent']) / 100.0) * geometry.display_width

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
            stamp_style = build_stamp_style(composite, geometry.rotation)

            box = placement_box(
                geometry,
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
