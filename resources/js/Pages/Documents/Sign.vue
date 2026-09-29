<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ref, shallowRef, onMounted, onBeforeUnmount, computed, nextTick } from 'vue';
// pdfjs-dist v6 uses ES private class fields. If Vite bundles the main library
// into its own chunk AND the worker loads from a separate file, they each define
// PDFDocumentProxy independently — accessing private fields across two different
// class definitions always throws. The only reliable fix is to NOT let Vite bundle
// pdf.js at all: load it dynamically from the same static public/ file as the worker
// so both use the exact same class definitions. Both files are copied from
// node_modules by the postinstall script in package.json.
let _pdfjs = null; // set in onMounted after dynamic import

const props = defineProps({
    document:    Object,
    documentUrl: String,
    esigUrl:     String,   // null when no e-sig image uploaded
    credential:  Object,
});

// ── PDF state ────────────────────────────────────────────────────────────────
const canvasRef    = ref(null);
const currentPage  = ref(1);
const totalPages   = ref(0);
const pageWidth    = ref(0);   // rendered canvas width in px
const pageHeight   = ref(0);   // rendered canvas height in px
const pdfDoc       = shallowRef(null); // shallowRef prevents Vue's Proxy from wrapping PDFDocumentProxy
const renderTask   = shallowRef(null); // same — PDFRenderTask also uses private fields
const loading      = ref(true);
const pdfError     = ref(null);

// ── Signature overlay state ──────────────────────────────────────────────────
// sigBox is the *draft* placement currently being positioned — it isn't part
// of the submission until "Add Signature Here" pushes it into form.placements.
// This lets the secretary place the RD's signature more than once — on the
// same page, or across several pages — for documents that need it.
const sigBox = ref({ left: 30, top: 70, width: 20 }); // percent values, relative to canvas
const isDragging   = ref(false);
const isResizing   = ref(false);
const dragStart    = ref({ mx: 0, my: 0, bLeft: 0, bTop: 0 });
const resizeStart  = ref({ mx: 0, bWidth: 0 });

// null while positioning a new placement; the index into form.placements
// while adjusting one that was already added.
const editingIndex = ref(null);

// ── Sign form ────────────────────────────────────────────────────────────────
const form = useForm({
    p12_password: '',
    remarks:      '',
    include_signature_details: false,
    placements:   [],
});

const submitSign = () => form.post(route('documents.sign.store', props.document.id));

const draftPlacement = () => ({
    page:          currentPage.value,
    x_percent:     parseFloat(sigBox.value.left.toFixed(3)),
    y_percent:     parseFloat(sigBox.value.top.toFixed(3)),
    width_percent: parseFloat(sigBox.value.width.toFixed(3)),
});

const addOrUpdatePlacement = () => {
    if (editingIndex.value !== null) {
        form.placements.splice(editingIndex.value, 1, draftPlacement());
        editingIndex.value = null;
    } else {
        form.placements.push(draftPlacement());
    }
};

const editPlacement = async (index) => {
    const p = form.placements[index];
    editingIndex.value = index;
    sigBox.value = { left: p.x_percent, top: p.y_percent, width: p.width_percent };
    if (currentPage.value !== p.page) {
        await goToPage(p.page);
    }
};

const cancelEdit = () => { editingIndex.value = null; };

const removePlacement = (index) => {
    form.placements.splice(index, 1);
    if (editingIndex.value === index) {
        editingIndex.value = null;
    } else if (editingIndex.value !== null && editingIndex.value > index) {
        editingIndex.value -= 1;
    }
};

// Already-added placements on the page currently being viewed, shown as
// read-only markers so the secretary can see what's already placed here.
const placementsOnCurrentPage = computed(() =>
    form.placements
        .map((p, index) => ({ ...p, index }))
        .filter(p => p.page === currentPage.value && p.index !== editingIndex.value)
);

// ── PDF rendering ────────────────────────────────────────────────────────────
const renderPage = async (num) => {
    if (! pdfDoc.value || ! canvasRef.value) return;

    if (renderTask.value) {
        renderTask.value.cancel();
    }

    const page = await pdfDoc.value.getPage(num);

    // Fit the canvas to the outer wrapper width (pdfContainerRef's parent),
    // not to pdfContainerRef itself which starts at 0×0.
    // Fall back to 800 BEFORE subtracting padding — "(0 - 16) || 800"
    // evaluates to -16 (truthy), not the fallback, since only a literal 0
    // triggers ||. A negative width produces an invalid/blank canvas.
    const outerWrapper = pdfContainerRef.value?.parentElement;
    const containerW   = (outerWrapper?.clientWidth || 800) - 16; // subtract p-2 padding
    const viewport     = page.getViewport({ scale: 1 });
    const cssScale     = containerW / viewport.width;

    // Render at device-pixel resolution (not just CSS-pixel), otherwise the
    // canvas bitmap is upscaled by the browser on high-DPI/Retina screens and
    // the text comes out visibly blurry. pageWidth/pageHeight stay in CSS
    // pixels since they size the container and position the signature overlay.
    const dpr        = window.devicePixelRatio || 1;
    const scaledView = page.getViewport({ scale: cssScale * dpr });

    const canvas  = canvasRef.value;
    canvas.width  = Math.floor(scaledView.width);
    canvas.height = Math.floor(scaledView.height);
    pageWidth.value  = Math.floor(scaledView.width / dpr);
    pageHeight.value = Math.floor(scaledView.height / dpr);
    canvas.style.width  = pageWidth.value + 'px';
    canvas.style.height = pageHeight.value + 'px';

    renderTask.value = page.render({
        canvasContext: canvas.getContext('2d'),
        viewport:      scaledView,
    });

    try {
        await renderTask.value.promise;
    } catch (e) {
        if (e?.name !== 'RenderingCancelledException') {
            pdfError.value = 'Failed to render page.';
        }
    }
};

const goToPage = async (n) => {
    const clamped = Math.max(1, Math.min(n, totalPages.value));
    currentPage.value = clamped;
    await renderPage(clamped);
};

onMounted(async () => {
    try {
        // Load the main pdf.js bundle from the same static public/ directory as
        // the worker. Both files come from the same build so their class definitions
        // are identical — private-field access on PDFDocumentProxy works correctly.
        // Build URLs at runtime so Vite's import-analysis does not try to resolve
        // them as module paths. Both files are in public/ and served as static assets.
        // Use workerSrc (not workerPort) — workerPort expects a MessagePort, not a
        // Worker object. Passing a Worker causes a FakeWorker fallback that imports
        // pdf.worker.min.mjs in the main thread, creating a second class definition
        // for PDFDocumentProxy which breaks private-field access (#Ns etc.).
        // workerSrc lets pdf.js v6 create the ES-module worker itself correctly.
        const root = window.location.origin;
        _pdfjs = await import(/* @vite-ignore */ root + '/pdf.min.mjs');
        _pdfjs.GlobalWorkerOptions.workerSrc = root + '/pdf.worker.min.mjs';

        // wasmUrl points at pdf.js's separate WASM image decoders (jbig2.wasm
        // etc., copied to public/wasm/ alongside pdf.min.mjs/pdf.worker.min.mjs
        // by the postinstall script) — without it, scanned/faxed PDFs using
        // JBIG2-encoded images fail to decode those images silently.
        const loadTask   = _pdfjs.getDocument({ url: props.documentUrl, withCredentials: true, wasmUrl: root + '/wasm/' });
        pdfDoc.value     = await loadTask.promise;
        totalPages.value = pdfDoc.value.numPages;
        loading.value    = false;
        await nextTick();
        await renderPage(1);
    } catch (e) {
        pdfError.value = 'Failed to load PDF: ' + (e.message ?? e);
        loading.value  = false;
    }
});

// ── Drag to position signature ────────────────────────────────────────────────
const pdfContainerRef = ref(null);

// Mouse and touch both funnel through these three helpers so drag/resize
// behave identically regardless of input device.
const startDrag = (clientX, clientY) => {
    isDragging.value = true;
    dragStart.value  = {
        mx:    clientX,
        my:    clientY,
        bLeft: sigBox.value.left,
        bTop:  sigBox.value.top,
    };
};

const startResize = (clientX) => {
    isResizing.value  = true;
    resizeStart.value = { mx: clientX, bWidth: sigBox.value.width };
};

const moveTo = (clientX, clientY) => {
    if (isDragging.value) {
        const rect  = pdfContainerRef.value.getBoundingClientRect();
        const dxPct = ((clientX - dragStart.value.mx) / rect.width)  * 100;
        const dyPct = ((clientY - dragStart.value.my) / rect.height) * 100;

        sigBox.value.left = Math.max(0, Math.min(95 - sigBox.value.width, dragStart.value.bLeft + dxPct));
        sigBox.value.top  = Math.max(0, Math.min(95, dragStart.value.bTop + dyPct));
    } else if (isResizing.value) {
        const rect  = pdfContainerRef.value.getBoundingClientRect();
        const dxPct = ((clientX - resizeStart.value.mx) / rect.width) * 100;
        sigBox.value.width = Math.max(8, Math.min(80, resizeStart.value.bWidth + dxPct));
    }
};

const endDrag = () => {
    isDragging.value = false;
    isResizing.value = false;
};

const onSigMouseDown = (e) => {
    e.preventDefault();
    startDrag(e.clientX, e.clientY);
};

const onResizeMouseDown = (e) => {
    e.preventDefault();
    e.stopPropagation();
    startResize(e.clientX);
};

const onMouseMove = (e) => moveTo(e.clientX, e.clientY);
const onMouseUp   = () => endDrag();

// Touch equivalents — mobile browsers don't fire mousedown/mousemove for
// touch drags, so the box was completely unmovable on phones/tablets without
// these. preventDefault() on touchstart/touchmove also stops the page from
// scrolling while the secretary is repositioning the signature.
const onSigTouchStart = (e) => {
    if (e.touches.length !== 1) return;
    e.preventDefault();
    startDrag(e.touches[0].clientX, e.touches[0].clientY);
};

const onResizeTouchStart = (e) => {
    if (e.touches.length !== 1) return;
    e.preventDefault();
    e.stopPropagation();
    startResize(e.touches[0].clientX);
};

const onTouchMove = (e) => {
    if (!isDragging.value && !isResizing.value) return;
    if (e.touches.length !== 1) return;
    e.preventDefault();
    moveTo(e.touches[0].clientX, e.touches[0].clientY);
};

const onTouchEnd = () => endDrag();

onMounted(() => {
    window.addEventListener('mousemove', onMouseMove);
    window.addEventListener('mouseup',   onMouseUp);
    // Must be non-passive so preventDefault() (blocking page scroll) works.
    window.addEventListener('touchmove',  onTouchMove, { passive: false });
    window.addEventListener('touchend',   onTouchEnd);
    window.addEventListener('touchcancel', onTouchEnd);
});

onBeforeUnmount(() => {
    window.removeEventListener('mousemove', onMouseMove);
    window.removeEventListener('mouseup',   onMouseUp);
    window.removeEventListener('touchmove',  onTouchMove);
    window.removeEventListener('touchend',   onTouchEnd);
    window.removeEventListener('touchcancel', onTouchEnd);
    _pdfjs = null;
});

// ── Computed helpers ──────────────────────────────────────────────────────────
const certExpired = computed(() => {
    if (! props.credential.certificate_valid_until) return false;
    return new Date(props.credential.certificate_valid_until) < new Date();
});

// Mirrors DocumentSigner::sign()'s font-size formula (scaled off the
// placement's own width, clamped to the same bounds) so the "With Details"
// preview grows/shrinks the text along with the box exactly the way the
// final signed PDF will, instead of staying a fixed size while only the
// signature mark resizes.
const detailsFontSizePx = computed(() => {
    const boxWidthPx = (sigBox.value.width / 100) * pageWidth.value;

    return Math.max(4, Math.min(16, boxWidthPx * 0.05));
});

const formatDate = d => d
    ? new Date(d).toLocaleDateString('en-PH', { year: 'numeric', month: 'short', day: 'numeric' })
    : '—';
</script>

<template>
    <Head :title="`Sign: ${document.title}`" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center gap-3">
                <Link
                    :href="route('documents.show', document.id)"
                    class="text-gray-400 hover:text-gray-600"
                >
                    <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/>
                    </svg>
                </Link>
                <div>
                    <p class="text-sm text-gray-500">Sign</p>
                    <h2 class="text-xl font-semibold leading-tight text-gray-800">{{ document.title }}</h2>
                </div>
            </div>
        </template>

        <div class="py-8">
            <div class="mx-auto max-w-7xl sm:px-6 lg:px-8">

                <!-- Certificate warning -->
                <div v-if="certExpired" class="mb-4 flex items-start rounded-lg border border-red-300 bg-red-50 p-4 text-sm text-red-800">
                    <svg class="me-3 mt-0.5 h-5 w-5 shrink-0" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/></svg>
                    <div>
                        <strong>Certificate expired.</strong> Your signing certificate expired on {{ formatDate(credential.certificate_valid_until) }}.
                        PDF viewers will show this signature as invalid. Contact your IT officer to renew the certificate.
                    </div>
                </div>

                <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">

                    <!-- ── Left: PDF viewer + draggable sig ──────────────── -->
                    <div class="lg:col-span-2 space-y-3">
                        <div class="overflow-hidden rounded-lg bg-white shadow-sm">
                            <div class="flex items-center justify-between border-b border-gray-100 px-4 py-3">
                                <h3 class="text-sm font-semibold text-gray-700">
                                    Drag the RD's signature to the correct position on the page
                                </h3>
                                <!-- Page navigation -->
                                <div v-if="totalPages > 1" class="flex items-center gap-2 text-sm">
                                    <button
                                        type="button"
                                        :disabled="currentPage <= 1"
                                        class="rounded border border-gray-300 px-2 py-1 text-xs hover:bg-gray-50 disabled:opacity-40"
                                        @click="goToPage(currentPage - 1)"
                                    >‹ Prev</button>
                                    <span class="text-gray-500">Page {{ currentPage }} / {{ totalPages }}</span>
                                    <button
                                        type="button"
                                        :disabled="currentPage >= totalPages"
                                        class="rounded border border-gray-300 px-2 py-1 text-xs hover:bg-gray-50 disabled:opacity-40"
                                        @click="goToPage(currentPage + 1)"
                                    >Next ›</button>
                                </div>
                            </div>

                            <!-- PDF canvas + signature overlay -->
                            <div class="relative select-none overflow-hidden bg-gray-200 p-2">

                                <!-- Loading state -->
                                <div v-if="loading" class="flex h-64 items-center justify-center text-gray-500">
                                    <svg class="mr-2 h-5 w-5 animate-spin" fill="none" viewBox="0 0 24 24">
                                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"/>
                                    </svg>
                                    Loading document…
                                </div>

                                <!-- Error state -->
                                <div v-else-if="pdfError" class="flex h-64 items-center justify-center text-red-600 text-sm">
                                    {{ pdfError }}
                                </div>

                                <!-- The PDF canvas + draggable overlay container -->
                                <div
                                    v-else
                                    ref="pdfContainerRef"
                                    class="relative mx-auto shadow-md"
                                    :style="{ width: pageWidth + 'px', height: pageHeight + 'px' }"
                                >
                                    <canvas ref="canvasRef" class="block" />

                                    <!-- Read-only markers for placements already added on this page -->
                                    <div
                                        v-for="p in placementsOnCurrentPage"
                                        :key="p.index"
                                        class="absolute border-2 border-green-500 bg-green-50/40 pointer-events-none"
                                        :style="{ left: p.x_percent + '%', top: p.y_percent + '%', width: p.width_percent + '%' }"
                                    >
                                        <span class="absolute -top-5 left-0 rounded bg-green-600 px-1.5 py-0.5 text-[10px] font-medium text-white">
                                            ✓ Added #{{ p.index + 1 }}
                                        </span>
                                    </div>

                                    <!-- Draggable signature box (the placement currently being positioned) -->
                                    <div
                                        class="absolute border-2 border-dashed border-blue-500 bg-blue-50/30 cursor-move touch-none"
                                        :style="{
                                            left:   sigBox.left  + '%',
                                            top:    sigBox.top   + '%',
                                            width:  sigBox.width + '%',
                                        }"
                                        @mousedown="onSigMouseDown"
                                        @touchstart="onSigTouchStart"
                                    >
                                        <!-- Plain: e-sig image fills the whole box -->
                                        <template v-if="!form.include_signature_details">
                                            <img
                                                v-if="esigUrl"
                                                :src="esigUrl"
                                                class="w-full h-auto pointer-events-none select-none block"
                                                alt="RD's e-signature"
                                            />
                                            <!-- Placeholder when no e-sig image -->
                                            <div
                                                v-else
                                                class="flex h-10 items-center justify-center text-xs text-blue-500 font-medium"
                                            >
                                                [Digital Signature]
                                            </div>
                                        </template>

                                        <!-- With details: mark on the left ~40%, "Digitally signed by..." text filling the rest — mirrors the actual printed layout -->
                                        <div v-else class="flex items-start gap-1 pointer-events-none select-none">
                                            <img
                                                v-if="esigUrl"
                                                :src="esigUrl"
                                                class="h-auto shrink-0 block"
                                                style="width: 40%"
                                                alt="RD's e-signature"
                                            />
                                            <div v-else class="flex h-10 shrink-0 items-center justify-center text-[10px] text-blue-500 font-medium" style="width: 40%">
                                                [Signature]
                                            </div>
                                            <div class="min-w-0 leading-tight text-gray-800" :style="{ fontSize: detailsFontSizePx + 'px' }">
                                                <p class="truncate">Digitally signed by</p>
                                                <p class="truncate font-medium">{{ credential.signer_name }}</p>
                                                <p class="truncate">Date: (at signing time)</p>
                                            </div>
                                        </div>

                                        <!-- Resize handle (right edge) — widened beyond the visible bar so it's a workable touch target on phones -->
                                        <div
                                            class="absolute right-0 top-0 -mr-2 h-full w-6 cursor-ew-resize touch-none"
                                            @mousedown="onResizeMouseDown"
                                            @touchstart="onResizeTouchStart"
                                        >
                                            <div class="absolute right-2 top-1/2 -translate-y-1/2 h-6 w-1.5 rounded-l bg-blue-500 opacity-70"></div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="flex flex-col gap-2 border-t border-gray-100 px-4 py-3 sm:flex-row sm:items-center sm:justify-between">
                                <p class="text-xs text-gray-500">
                                    Drag the blue box to reposition, pull the right edge to resize, then add it.
                                    You can add the RD's signature more than once — on this page or others.
                                </p>
                                <div class="flex shrink-0 gap-2">
                                    <button
                                        v-if="editingIndex !== null"
                                        type="button"
                                        class="rounded-lg border border-gray-300 bg-white px-3 py-2 text-xs font-medium text-gray-700 hover:bg-gray-50"
                                        @click="cancelEdit"
                                    >
                                        Cancel Edit
                                    </button>
                                    <button
                                        type="button"
                                        class="inline-flex items-center gap-1.5 rounded-lg bg-blue-700 px-3 py-2 text-xs font-medium text-white hover:bg-blue-800"
                                        @click="addOrUpdatePlacement"
                                    >
                                        {{ editingIndex !== null ? `Update Signature #${editingIndex + 1}` : 'Add Signature Here' }}
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- ── Right: sign form ───────────────────────────────── -->
                    <div class="space-y-4">

                        <!-- Certificate info card -->
                        <div class="overflow-hidden rounded-lg bg-white shadow-sm">
                            <div class="border-b border-gray-100 px-4 py-3">
                                <h3 class="text-sm font-semibold text-gray-700">Signing Certificate</h3>
                            </div>
                            <dl class="divide-y divide-gray-100 text-sm">
                                <div class="px-4 py-3">
                                    <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">Subject</dt>
                                    <dd class="mt-0.5 text-gray-900 font-mono text-xs break-all">
                                        {{ credential.certificate_subject ?? '—' }}
                                    </dd>
                                </div>
                                <div class="px-4 py-3">
                                    <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">Serial</dt>
                                    <dd class="mt-0.5 text-gray-900 font-mono text-xs break-all">
                                        {{ credential.certificate_serial ?? '—' }}
                                    </dd>
                                </div>
                                <div class="px-4 py-3">
                                    <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">Valid Until</dt>
                                    <dd class="mt-0.5" :class="certExpired ? 'text-red-600 font-semibold' : 'text-gray-900'">
                                        {{ formatDate(credential.certificate_valid_until) }}
                                        <span v-if="certExpired" class="ml-1 text-xs">(EXPIRED)</span>
                                    </dd>
                                </div>
                            </dl>
                        </div>

                        <!-- Signature style -->
                        <div class="overflow-hidden rounded-lg bg-white shadow-sm">
                            <div class="border-b border-gray-100 px-4 py-3">
                                <h3 class="text-sm font-semibold text-gray-700">Signature Style</h3>
                            </div>
                            <div class="p-4 space-y-2">
                                <label class="flex cursor-pointer items-start gap-2 rounded-lg border border-gray-300 bg-gray-50 p-3 text-sm has-[:checked]:border-purple-500 has-[:checked]:bg-purple-50">
                                    <input type="radio" :value="false" v-model="form.include_signature_details" class="mt-0.5" />
                                    <span>
                                        <span class="block font-medium text-gray-900">Plain</span>
                                        <span class="block text-gray-500">Just the signature mark, as it is now.</span>
                                    </span>
                                </label>
                                <label class="flex cursor-pointer items-start gap-2 rounded-lg border border-gray-300 bg-gray-50 p-3 text-sm has-[:checked]:border-purple-500 has-[:checked]:bg-purple-50">
                                    <input type="radio" :value="true" v-model="form.include_signature_details" class="mt-0.5" />
                                    <span>
                                        <span class="block font-medium text-gray-900">With Details</span>
                                        <span class="block text-gray-500">Adds "Digitally signed by {{ credential.signer_name }} / Date: …" next to the mark.</span>
                                    </span>
                                </label>
                            </div>
                        </div>

                        <!-- Signature placements list -->
                        <div class="overflow-hidden rounded-lg bg-white shadow-sm">
                            <div class="border-b border-gray-100 px-4 py-3">
                                <h3 class="text-sm font-semibold text-gray-700">
                                    Signature Placements
                                    <span class="ml-1 font-normal text-gray-400">({{ form.placements.length }})</span>
                                </h3>
                            </div>
                            <p v-if="form.placements.length === 0" class="px-4 py-3 text-xs text-gray-500">
                                None added yet — position the box on the left, then click "Add Signature Here."
                            </p>
                            <ul v-else class="divide-y divide-gray-100">
                                <li
                                    v-for="(p, index) in form.placements"
                                    :key="index"
                                    class="flex items-center justify-between gap-2 px-4 py-2.5 text-sm"
                                    :class="{ 'bg-blue-50': editingIndex === index }"
                                >
                                    <button type="button" class="text-left text-gray-700 hover:text-blue-700" @click="editPlacement(index)">
                                        <span class="font-medium">#{{ index + 1 }}</span> — Page {{ p.page }}
                                        <span class="text-xs text-gray-400">({{ p.x_percent.toFixed(0) }}%, {{ p.y_percent.toFixed(0) }}%)</span>
                                    </button>
                                    <button
                                        type="button"
                                        class="shrink-0 text-xs font-medium text-red-600 hover:text-red-800"
                                        @click="removePlacement(index)"
                                    >
                                        Remove
                                    </button>
                                </li>
                            </ul>
                            <p v-if="form.errors.placements" class="border-t border-gray-100 px-4 py-2 text-xs text-red-600">{{ form.errors.placements }}</p>
                        </div>

                        <!-- Sign form -->
                        <div class="overflow-hidden rounded-lg border-2 border-purple-200 bg-white shadow-sm">
                            <div class="border-b border-purple-100 bg-purple-50 px-4 py-3">
                                <h3 class="text-sm font-semibold text-purple-800">Sign</h3>
                                <p class="mt-0.5 text-xs text-purple-600">
                                    Enter the RD's P12 password to digitally sign this document.
                                    The resulting PDF will be tamper-evident.
                                </p>
                            </div>

                            <form @submit.prevent="submitSign" class="p-4 space-y-4">

                                <!-- P12 password -->
                                <div>
                                    <label for="p12_password" class="mb-1.5 block text-sm font-medium text-gray-900">
                                        RD's P12 Certificate Password <span class="text-red-500">*</span>
                                    </label>
                                    <input
                                        id="p12_password"
                                        v-model="form.p12_password"
                                        type="password"
                                        autocomplete="current-password"
                                        class="block w-full rounded-lg border border-gray-300 bg-gray-50 p-2.5 text-sm text-gray-900 focus:border-purple-500 focus:ring-purple-500"
                                        placeholder="Enter the RD's certificate password"
                                        required
                                    />
                                    <p v-if="form.errors.p12_password" class="mt-1 text-sm text-red-600">{{ form.errors.p12_password }}</p>
                                </div>

                                <!-- Remarks -->
                                <div>
                                    <label for="sign_remarks" class="mb-1.5 block text-sm font-medium text-gray-900">
                                        Remarks <span class="text-gray-400 font-normal text-xs">(optional)</span>
                                    </label>
                                    <textarea
                                        id="sign_remarks"
                                        v-model="form.remarks"
                                        rows="3"
                                        class="block w-full rounded-lg border border-gray-300 bg-gray-50 p-2.5 text-sm text-gray-900 focus:border-purple-500 focus:ring-purple-500"
                                        placeholder="Optional notes on this approval…"
                                    />
                                    <p v-if="form.errors.remarks" class="mt-1 text-sm text-red-600">{{ form.errors.remarks }}</p>
                                </div>

                                <!-- Actions -->
                                <div class="flex flex-col gap-2 pt-1">
                                    <p v-if="form.placements.length === 0" class="text-xs text-amber-600">
                                        Add at least one signature placement above before signing.
                                    </p>
                                    <button
                                        type="submit"
                                        :disabled="form.processing || loading || !!pdfError || form.placements.length === 0"
                                        class="inline-flex w-full items-center justify-center gap-2 rounded-lg bg-purple-700 px-4 py-2.5 text-sm font-semibold text-white hover:bg-purple-800 focus:ring-4 focus:ring-purple-300 disabled:opacity-60"
                                    >
                                        <svg v-if="form.processing" class="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24">
                                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"/>
                                        </svg>
                                        <svg v-else class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
                                        </svg>
                                        {{ form.processing ? 'Signing…' : 'Sign Document' }}
                                    </button>
                                    <Link
                                        :href="route('documents.show', document.id)"
                                        class="block w-full rounded-lg border border-gray-300 bg-white px-4 py-2.5 text-center text-sm font-medium text-gray-700 hover:bg-gray-50"
                                    >
                                        Cancel
                                    </Link>
                                </div>
                            </form>
                        </div>
                    </div>

                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
