<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ref, shallowRef, onMounted, onBeforeUnmount, computed, nextTick } from 'vue';
// See Documents/Sign.vue for why pdf.js is loaded dynamically from public/
// rather than bundled — this page mirrors that mechanism exactly.
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

// ── Stamp overlay state ──────────────────────────────────────────────────────
const stampBox = ref({ left: 30, top: 70, width: 20 }); // percent values, relative to canvas
const isDragging   = ref(false);
const isResizing   = ref(false);
const dragStart    = ref({ mx: 0, my: 0, bLeft: 0, bTop: 0 });
const resizeStart  = ref({ mx: 0, bWidth: 0 });

// ── Release form ─────────────────────────────────────────────────────────────
const form = useForm({
    p12_password:         '',
    remarks:              '',
    stamp_page:           1,
    stamp_x_percent:      30,
    stamp_y_percent:      70,
    stamp_width_percent:  20,
});

const submitRelease = () => form.post(route('documents.release.store', props.document.id));

// Sync form values from stampBox whenever it changes
const syncForm = () => {
    form.stamp_page          = currentPage.value;
    form.stamp_x_percent     = parseFloat(stampBox.value.left.toFixed(3));
    form.stamp_y_percent     = parseFloat(stampBox.value.top.toFixed(3));
    form.stamp_width_percent = parseFloat(stampBox.value.width.toFixed(3));
};

// ── PDF rendering ────────────────────────────────────────────────────────────
const renderPage = async (num) => {
    if (! pdfDoc.value || ! canvasRef.value) return;

    if (renderTask.value) {
        renderTask.value.cancel();
    }

    const page = await pdfDoc.value.getPage(num);

    const outerWrapper = pdfContainerRef.value?.parentElement;
    const containerW   = (outerWrapper?.clientWidth || 800) - 16; // subtract p-2 padding
    const viewport     = page.getViewport({ scale: 1 });
    const cssScale     = containerW / viewport.width;

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

    syncForm();
};

const goToPage = async (n) => {
    const clamped = Math.max(1, Math.min(n, totalPages.value));
    currentPage.value = clamped;
    await renderPage(clamped);
};

onMounted(async () => {
    try {
        const root = window.location.origin;
        _pdfjs = await import(/* @vite-ignore */ root + '/pdf.min.mjs');
        _pdfjs.GlobalWorkerOptions.workerSrc = root + '/pdf.worker.min.mjs';

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

// ── Drag to position the release stamp ───────────────────────────────────────
const pdfContainerRef = ref(null);

// Mouse and touch both funnel through these three helpers so drag/resize
// behave identically regardless of input device.
const startDrag = (clientX, clientY) => {
    isDragging.value = true;
    dragStart.value  = {
        mx:    clientX,
        my:    clientY,
        bLeft: stampBox.value.left,
        bTop:  stampBox.value.top,
    };
};

const startResize = (clientX) => {
    isResizing.value  = true;
    resizeStart.value = { mx: clientX, bWidth: stampBox.value.width };
};

const moveTo = (clientX, clientY) => {
    if (isDragging.value) {
        const rect  = pdfContainerRef.value.getBoundingClientRect();
        const dxPct = ((clientX - dragStart.value.mx) / rect.width)  * 100;
        const dyPct = ((clientY - dragStart.value.my) / rect.height) * 100;

        stampBox.value.left = Math.max(0, Math.min(95 - stampBox.value.width, dragStart.value.bLeft + dxPct));
        stampBox.value.top  = Math.max(0, Math.min(95, dragStart.value.bTop + dyPct));
        syncForm();
    } else if (isResizing.value) {
        const rect  = pdfContainerRef.value.getBoundingClientRect();
        const dxPct = ((clientX - resizeStart.value.mx) / rect.width) * 100;
        stampBox.value.width = Math.max(8, Math.min(80, resizeStart.value.bWidth + dxPct));
        syncForm();
    }
};

const endDrag = () => {
    isDragging.value = false;
    isResizing.value = false;
};

const onStampMouseDown = (e) => {
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

// Touch equivalents — see Documents/Sign.vue for why these are needed.
const onStampTouchStart = (e) => {
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

// Mirrors Documents/Sign.vue's detailsFontSizePx (itself mirroring
// sign_pdf.py's build_composite_stamp_image() font formula) — the release
// stamp always includes the "Digitally signed by / Date" text
// (DocumentReleaseStamper::stamp() hardcodes includeDetails: true), so this
// preview must always account for it too, not just when a toggle is on.
const detailsFontSizePx = computed(() => {
    const boxWidthPx = (stampBox.value.width / 100) * pageWidth.value;

    return Math.max(4, Math.min(16, boxWidthPx * 0.05));
});

const formatDate = d => d
    ? new Date(d).toLocaleDateString('en-PH', { year: 'numeric', month: 'short', day: 'numeric' })
    : '—';
</script>

<template>
    <Head :title="`Release: ${document.title}`" />

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
                    <p class="text-sm text-gray-500">Release</p>
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

                    <!-- ── Left: PDF viewer + draggable stamp ────────────── -->
                    <div class="lg:col-span-2 space-y-3">
                        <div class="overflow-hidden rounded-lg bg-white shadow-sm">
                            <div class="flex items-center justify-between border-b border-gray-100 px-4 py-3">
                                <h3 class="text-sm font-semibold text-gray-700">
                                    Drag your release stamp to the correct position on the page
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

                            <!-- PDF canvas + stamp overlay -->
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

                                    <!-- Draggable stamp box -->
                                    <div
                                        class="absolute border-2 border-dashed border-emerald-500 bg-emerald-50/30 cursor-move touch-none"
                                        :style="{
                                            left:   stampBox.left  + '%',
                                            top:    stampBox.top   + '%',
                                            width:  stampBox.width + '%',
                                        }"
                                        @mousedown="onStampMouseDown"
                                        @touchstart="onStampTouchStart"
                                    >
                                        <!-- Mark on the left ~40%, "Digitally signed by..." text filling the rest —
                                             mirrors the actual printed layout, since the release stamp always
                                             includes these details (see detailsFontSizePx above). -->
                                        <div class="flex items-start gap-1 pointer-events-none select-none">
                                            <img
                                                v-if="esigUrl"
                                                :src="esigUrl"
                                                class="h-auto shrink-0 block"
                                                style="width: 40%"
                                                alt="Release stamp"
                                            />
                                            <div v-else class="flex h-10 shrink-0 items-center justify-center text-[10px] text-emerald-600 font-medium" style="width: 40%">
                                                [Release Stamp]
                                            </div>
                                            <div class="min-w-0 leading-tight text-gray-800" :style="{ fontSize: detailsFontSizePx + 'px' }">
                                                <p class="truncate">Digitally signed by</p>
                                                <p class="truncate font-medium">{{ credential.signer_name }}</p>
                                                <p class="truncate">Date: (at release time)</p>
                                            </div>
                                        </div>

                                        <!-- Resize handle (right edge) — widened beyond the visible bar so it's a workable touch target on phones -->
                                        <div
                                            class="absolute right-0 top-0 -mr-2 h-full w-6 cursor-ew-resize touch-none"
                                            @mousedown="onResizeMouseDown"
                                            @touchstart="onResizeTouchStart"
                                        >
                                            <div class="absolute right-2 top-1/2 -translate-y-1/2 h-6 w-1.5 rounded-l bg-emerald-500 opacity-70"></div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <p class="px-4 py-2 text-xs text-gray-500">
                                Drag the green box to reposition. Pull the right edge to resize.
                                The current position will be used on page <strong>{{ currentPage }}</strong>.
                                A "Digitally signed by / Date" line will be added automatically beside it.
                            </p>
                        </div>
                    </div>

                    <!-- ── Right: release form ─────────────────────────────── -->
                    <div class="space-y-4">

                        <!-- Certificate info card -->
                        <div class="overflow-hidden rounded-lg bg-white shadow-sm">
                            <div class="border-b border-gray-100 px-4 py-3">
                                <h3 class="text-sm font-semibold text-gray-700">Your Signing Certificate</h3>
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

                        <!-- Release form -->
                        <div class="overflow-hidden rounded-lg border-2 border-emerald-200 bg-white shadow-sm">
                            <div class="border-b border-emerald-100 bg-emerald-50 px-4 py-3">
                                <h3 class="text-sm font-semibold text-emerald-800">Release &amp; Stamp</h3>
                                <p class="mt-0.5 text-xs text-emerald-700">
                                    Enter your P12 password to attach your release stamp and digitally sign this document.
                                    This confirms it has been physically handed out.
                                </p>
                            </div>

                            <form @submit.prevent="submitRelease" class="p-4 space-y-4">

                                <!-- Hidden position fields (synced from stampBox via syncForm) -->
                                <input type="hidden" :value="form.stamp_page"          name="stamp_page"/>
                                <input type="hidden" :value="form.stamp_x_percent"     name="stamp_x_percent"/>
                                <input type="hidden" :value="form.stamp_y_percent"     name="stamp_y_percent"/>
                                <input type="hidden" :value="form.stamp_width_percent" name="stamp_width_percent"/>

                                <!-- Stamp position summary -->
                                <div class="rounded-md bg-gray-50 p-3 text-xs text-gray-600 space-y-0.5">
                                    <p><span class="font-medium">Page:</span> {{ form.stamp_page }} of {{ totalPages || '?' }}</p>
                                    <p><span class="font-medium">Position:</span> {{ form.stamp_x_percent.toFixed(1) }}% from left, {{ form.stamp_y_percent.toFixed(1) }}% from top</p>
                                    <p><span class="font-medium">Width:</span> {{ form.stamp_width_percent.toFixed(1) }}% of page width</p>
                                </div>

                                <!-- P12 password -->
                                <div>
                                    <label for="p12_password" class="mb-1.5 block text-sm font-medium text-gray-900">
                                        Your P12 Certificate Password <span class="text-red-500">*</span>
                                    </label>
                                    <input
                                        id="p12_password"
                                        v-model="form.p12_password"
                                        type="password"
                                        autocomplete="current-password"
                                        class="block w-full rounded-lg border border-gray-300 bg-gray-50 p-2.5 text-sm text-gray-900 focus:border-emerald-500 focus:ring-emerald-500"
                                        placeholder="Enter your certificate password"
                                        required
                                    />
                                    <p v-if="form.errors.p12_password" class="mt-1 text-sm text-red-600">{{ form.errors.p12_password }}</p>
                                </div>

                                <!-- Remarks -->
                                <div>
                                    <label for="release_remarks" class="mb-1.5 block text-sm font-medium text-gray-900">
                                        Remarks <span class="text-gray-400 font-normal text-xs">(optional)</span>
                                    </label>
                                    <textarea
                                        id="release_remarks"
                                        v-model="form.remarks"
                                        rows="3"
                                        class="block w-full rounded-lg border border-gray-300 bg-gray-50 p-2.5 text-sm text-gray-900 focus:border-emerald-500 focus:ring-emerald-500"
                                        placeholder="Optional notes on this release…"
                                    />
                                    <p v-if="form.errors.remarks" class="mt-1 text-sm text-red-600">{{ form.errors.remarks }}</p>
                                </div>

                                <p v-if="form.errors.stamp_page" class="text-sm text-red-600">{{ form.errors.stamp_page }}</p>

                                <!-- Actions -->
                                <div class="flex flex-col gap-2 pt-1">
                                    <button
                                        type="submit"
                                        :disabled="form.processing || loading || !!pdfError"
                                        class="inline-flex w-full items-center justify-center gap-2 rounded-lg bg-emerald-700 px-4 py-2.5 text-sm font-semibold text-white hover:bg-emerald-800 focus:ring-4 focus:ring-emerald-300 disabled:opacity-60"
                                    >
                                        <svg v-if="form.processing" class="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24">
                                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"/>
                                        </svg>
                                        <svg v-else class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
                                        </svg>
                                        {{ form.processing ? 'Releasing…' : 'Release Document' }}
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
