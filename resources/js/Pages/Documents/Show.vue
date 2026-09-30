<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, ref, shallowRef, watch, nextTick, onMounted, onBeforeUnmount } from 'vue';

const props = defineProps({
    document:            Object,
    canReview:           Boolean,
    canRoute:            Boolean,
    canSign:             Boolean,
    canUndoSignature:    Boolean,
    canMarkEmailed:      Boolean,
    canDelete:           Boolean,
    canResubmit:         Boolean,
    canRelease:          Boolean,
    canReturnToOwner:    Boolean,
    canReturnForCorrection: Boolean,
    canArchive:          Boolean,
    recipientAssignment: Object,
    recipientMayDeferCompletion: Boolean,
    sdsSignatureMissing: Boolean,
});

// ── Status helpers ──────────────────────────────────────────────────────────
const STATUS_LABEL = {
    draft: 'Draft', submitted: 'Submitted',
    pending_unit_head_review: 'Pending Unit Head Review',
    pending_division_chief_review: 'Pending DC Review',
    pending_asds_review: 'Pending Recommending Approval',
    pending_sds_review: 'Pending SDS Review',
    approved: 'Approved', for_comment: 'For Comment',
    returned_for_revision: 'Returned', rejected: 'Rejected',
    pending_signature: 'Pending Signature', signed: 'Signed', released: 'Released',
    received: 'Received', pending_routing: 'Pending Routing',
    routed: 'Routed', completed: 'Completed', archived: 'Archived',
};
const STATUS_CLASS = {
    draft: 'bg-gray-100 text-gray-700',
    submitted: 'bg-yellow-100 text-yellow-800',
    pending_unit_head_review: 'bg-orange-100 text-orange-800',
    pending_division_chief_review: 'bg-yellow-100 text-yellow-800',
    pending_asds_review: 'bg-indigo-100 text-indigo-800',
    pending_sds_review: 'bg-indigo-100 text-indigo-800',
    approved: 'bg-green-100 text-green-800',
    for_comment: 'bg-amber-100 text-amber-800',
    returned_for_revision: 'bg-red-100 text-red-800',
    rejected: 'bg-red-100 text-red-800',
    pending_signature: 'bg-purple-100 text-purple-800',
    signed: 'bg-green-100 text-green-800',
    released: 'bg-emerald-200 text-emerald-900',
    received: 'bg-blue-100 text-blue-800',
    pending_routing: 'bg-yellow-100 text-yellow-800',
    routed: 'bg-indigo-100 text-indigo-800',
    completed: 'bg-green-100 text-green-800',
    archived: 'bg-gray-100 text-gray-700',
};

const RECIPIENT_STATUS_CLASS = {
    pending: 'bg-yellow-100 text-yellow-800',
    acknowledged: 'bg-blue-100 text-blue-800',
    completed: 'bg-green-100 text-green-800',
    cancelled: 'bg-gray-100 text-gray-500',
};

const ROUTING_ACTION_LABEL = {
    for_action: 'For Action', transmittal: 'Transmittal', attendance: 'Attendance',
    verification: 'Verification', draft_letter: 'Draft Letter', comment: 'Comment',
    information: 'Information', validation: 'Validation', draft_indorsement: 'Draft Indorsement',
    recommendation: 'Recommendation', others: 'Others',
};

const REVIEW_ACTION_LABEL = {
    approved_forwarded:    'Approved & Forwarded',
    approved_signed:       'Signed',
    for_comment:           'For Comment',
    returned_for_revision: 'Returned for Revision',
    rejected:              'Rejected',
    resubmitted:           'Resubmitted',
    released:              'Released',
    signature_reverted:    'Signature Undone',
    emailed:               'Emailed',
};

// The one 'approved_forwarded' enum value covers four different roles, each
// with its own correct terminology: a unit head or division chief reviews and
// forwards, the ASDS recommends approval, and only the SDS actually approves.
const APPROVED_FORWARDED_LABEL_BY_ROLE = {
    unit_head:      'Reviewed & Forwarded',
    division_chief: 'Reviewed & Forwarded',
    asds:            'Recommended for Approval',
    sds:             'Approved',
};

const reviewActionLabel = (review) => {
    if (review.action === 'approved_forwarded') {
        return APPROVED_FORWARDED_LABEL_BY_ROLE[review.role_at_review] ?? REVIEW_ACTION_LABEL.approved_forwarded;
    }
    return REVIEW_ACTION_LABEL[review.action] ?? review.action;
};

const REVIEW_ACTION_CLASS = {
    approved_forwarded:    'bg-green-100 text-green-800',
    approved_signed:       'bg-green-200 text-green-900',
    for_comment:           'bg-amber-100 text-amber-800',
    returned_for_revision: 'bg-red-100 text-red-800',
    rejected:              'bg-red-100 text-red-800',
    resubmitted:           'bg-blue-100 text-blue-800',
    released:              'bg-emerald-200 text-emerald-900',
    signature_reverted:    'bg-amber-100 text-amber-800',
    emailed:               'bg-sky-100 text-sky-800',
};

const ALL_REVIEW_STAGES = [
    { label: 'Submitted',      status: 'submitted' },
    { label: 'Unit Head',      status: 'pending_unit_head_review' },
    { label: 'Division Chief', status: 'pending_division_chief_review' },
    { label: 'ASDS',           status: 'pending_asds_review' },
    { label: 'SDS',            status: 'pending_sds_review' },
    { label: 'Signed',         status: 'approved' },
];

// Unit Head / Division Chief stages only appear in the chain if they were
// actually part of it — either the document is currently sitting at that
// stage, or a review was actually recorded for that role (e.g. uploads by a
// unit head or division chief skip straight to ASDS and never show either).
const showUnitHeadStage = computed(() => {
    const reviews = props.document.reviews ?? [];
    return props.document.status === 'pending_unit_head_review'
        || reviews.some(r => r.role_at_review === 'unit_head');
});

const showDivisionChiefStage = computed(() => {
    const reviews = props.document.reviews ?? [];
    return ['submitted', 'pending_division_chief_review'].includes(props.document.status)
        || reviews.some(r => r.role_at_review === 'division_chief');
});

// ASDS is skipped for a document entirely when the ASDS-availability toggle
// was off at the time it passed division-chief-level review (or on
// self-upload by a unit head/division chief) — see
// AsdsAvailabilitySetting::nextAfterDivisionChief(). Same detection pattern
// as Unit Head/Division Chief above: show it only if the document is
// currently there or a review was actually recorded for that role.
const showAsdsStage = computed(() => {
    const reviews = props.document.reviews ?? [];
    return props.document.status === 'pending_asds_review'
        || reviews.some(r => r.role_at_review === 'asds');
});

const REVIEW_STAGES = computed(() => ALL_REVIEW_STAGES.filter(stage => {
    if (stage.status === 'pending_unit_head_review') return showUnitHeadStage.value;
    if (stage.status === 'pending_division_chief_review') return showDivisionChiefStage.value;
    if (stage.status === 'pending_asds_review') return showAsdsStage.value;
    return true;
}));

const STAGE_ORDER = computed(() => REVIEW_STAGES.value.map(stage => stage.status));

const currentStageIndex = computed(() => {
    const s = props.document.status;
    if (['returned_for_revision', 'rejected'].includes(s)) return -1;
    // Treat 'signed'/'released' as equivalent to 'approved' in the stepper (final stage complete)
    if (['signed', 'released'].includes(s)) return STAGE_ORDER.value.length;
    return STAGE_ORDER.value.indexOf(s);
});

const formatDate = d => d
    ? new Date(d).toLocaleDateString('en-PH', { year: 'numeric', month: 'short', day: 'numeric' })
    : '—';
const formatDateTime = d => d
    ? new Date(d).toLocaleString('en-PH', { year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' })
    : '—';

// ── Review form ─────────────────────────────────────────────────────────────
const reviewForm = useForm({ action: '', remarks: '' });

// Guards against stale action/remarks lingering when moving from reviewing
// one document straight to another — Inertia only remounts this page's
// component (resetting local state like reviewForm fresh) when the visit
// that lands here doesn't preserve state; watching the document's own
// identity here is a stronger guarantee than relying on every navigation
// path in to this page happening to do that.
watch(() => props.document.id, () => {
    reviewForm.reset();
    reviewForm.clearErrors();
});

// 'for_comment' means there's a comment to make, same as returning/rejecting
// — an empty remarks field would leave the originator with nothing to act
// on. Mirrors ReviewDocumentRequest::rules()'s server-side requirement.
const remarksRequiredForAction = computed(() =>
    ['for_comment', 'returned_for_revision', 'rejected'].includes(reviewForm.action));

// The SDS may submit remarks alone with no action selected — her own remarks
// already imply "send it back" regardless (see DocumentReviewController::
// store()'s remarks-win rule), so requiring an explicit pick would just be
// friction. Every other stage/role still must pick an action, and if that
// action requires remarks, those must be filled in too.
const canSubmitReview = computed(() => {
    if (remarksRequiredForAction.value && reviewForm.remarks.trim() === '') return false;

    return reviewForm.action || (props.document.status === 'pending_sds_review' && reviewForm.remarks.trim() !== '');
});

// Same 'approved_forwarded' action, worded per the current stage: the chief
// reviews and forwards, the ASDS recommends approval, and only the SDS approves.
const approveActionLabel = computed(() => {
    switch (props.document.status) {
        case 'pending_sds_review':             return 'Approve';
        case 'pending_asds_review':            return 'Recommend for Approval';
        case 'submitted':
        case 'pending_division_chief_review': return 'Review & Forward';
        default:                              return 'Approve & Forward';
    }
});
// Safety net for quickly reviewing several documents in a row: if remarks
// look identical to what was just submitted on the PREVIOUS document, confirm
// before sending. Unlike Documents/Index.vue's quick-preview modal (which
// stays on the same mounted component across reviews), submitting here
// navigates away to the "For Approval" queue and back to a fresh Show.vue
// instance for the next document each time — a plain ref wouldn't survive
// that, so this uses sessionStorage instead, scoped to the browser tab.
const LAST_REVIEW_REMARKS_KEY = 'documents.lastReviewRemarks';
const submitReview = () => {
    const trimmedRemarks = reviewForm.remarks.trim();
    const lastRemarks = sessionStorage.getItem(LAST_REVIEW_REMARKS_KEY);

    if (trimmedRemarks && trimmedRemarks === lastRemarks
        && ! confirm('These remarks look identical to what you just submitted on the previous document. Submit anyway?')) {
        return;
    }

    reviewForm.post(route('documents.reviews.store', props.document.id), {
        onSuccess: () => {
            if (trimmedRemarks) sessionStorage.setItem(LAST_REVIEW_REMARKS_KEY, trimmedRemarks);
            else sessionStorage.removeItem(LAST_REVIEW_REMARKS_KEY);
            reviewForm.reset();
            reviewForm.clearErrors();
        },
    });
};

// ── Delete confirmation ──────────────────────────────────────────────────────
const showDeleteConfirm = ref(false);
const deleteForm = useForm({});
const confirmDelete = () => deleteForm.delete(route('documents.destroy', props.document.id));

// ── Undo signature confirmation (secretary correcting a placement mistake) ────
const showUndoSignatureConfirm = ref(false);
const undoSignatureForm = useForm({ remarks: '' });
const confirmUndoSignature = () => {
    undoSignatureForm.delete(route('documents.sign.destroy', props.document.id), {
        onSuccess: () => { showUndoSignatureConfirm.value = false; },
    });
};

// ── Return to Owner confirmation (secretary catching an overlooked SDS comment) ─
const showReturnToOwnerConfirm = ref(false);
const returnToOwnerForm = useForm({ remarks: '' });
const confirmReturnToOwner = () => {
    returnToOwnerForm.post(route('documents.return-to-owner', props.document.id), {
        onSuccess: () => { showReturnToOwnerConfirm.value = false; },
    });
};

// ── Return for Correction confirmation (secretary QC — missing pages, wrong format) ─
const showReturnForCorrectionConfirm = ref(false);
const returnForCorrectionForm = useForm({ remarks: '' });
const confirmReturnForCorrection = () => {
    returnForCorrectionForm.post(route('documents.return-for-correction', props.document.id), {
        onSuccess: () => { showReturnForCorrectionConfirm.value = false; },
    });
};

// ── File without routing confirmation (secretary, Records-Section uploads) ────
const showArchiveConfirm = ref(false);
const archiveForm = useForm({ remarks: '' });
const confirmArchive = () => {
    archiveForm.post(route('documents.archive', props.document.id), {
        onSuccess: () => { showArchiveConfirm.value = false; },
    });
};

// ── Mark as Emailed confirmation (Records Section, after releasing) ───────────
const showMarkEmailedConfirm = ref(false);
const markEmailedForm = useForm({ remarks: '' });
const confirmMarkEmailed = () => {
    markEmailedForm.post(route('documents.release.emailed', props.document.id), {
        onSuccess: () => { showMarkEmailedConfirm.value = false; },
    });
};

// ── Document preview modal ───────────────────────────────────────────────────
const showModal    = ref(false);
const isFullscreen = ref(false);

const fileExt = computed(() => {
    const path = props.document.file_path ?? '';
    return (path.split('.').pop() ?? '').toLowerCase();
});

// These types render inline in browsers; everything else gets a download fallback
const isImage = computed(() => ['png', 'jpg', 'jpeg'].includes(fileExt.value));
const isPdf   = computed(() => fileExt.value === 'pdf');

const closeModal = () => { showModal.value = false; isFullscreen.value = false; };
const toggleFullscreen = () => { isFullscreen.value = !isFullscreen.value; };

const onKeyDown = (e) => {
    if (e.key === 'Escape') {
        if (showRouteModal.value) closeRouteModal();
        else closeModal();
    }
    if (e.key === 'f' && showModal.value && !showRouteModal.value) toggleFullscreen();
};
onMounted(()         => window.addEventListener('keydown', onKeyDown));
onBeforeUnmount(()   => window.removeEventListener('keydown', onKeyDown));

// ── Route modal (stacked on top of the preview) ──────────────────────────────
const ROUTING_ACTIONS = [
    { value: 'for_action',        label: 'For Action' },
    { value: 'transmittal',       label: 'Transmittal' },
    { value: 'attendance',        label: 'Attendance' },
    { value: 'verification',      label: 'Verification' },
    { value: 'draft_letter',      label: 'Draft Letter' },
    { value: 'comment',           label: 'Comment' },
    { value: 'information',       label: 'Information' },
    { value: 'validation',        label: 'Validation' },
    { value: 'draft_indorsement', label: 'Draft Indorsement' },
    { value: 'recommendation',    label: 'Recommendation' },
    { value: 'others',            label: 'Others (specify)' },
];

const showRouteModal        = ref(false);
const routeCandidates       = ref([]);
const routeCandidatesLoading = ref(false);
const routeCandidatesError  = ref(null);

const routeForm = useForm({
    is_rush:      false,
    remarks:      '',
    action:       '',
    action_other: '',
    user_ids:     [],
});

const openRouteModal = async () => {
    showRouteModal.value = true;
    routeCandidatesError.value = null;
    routeCandidatesLoading.value = true;
    try {
        const { data } = await window.axios.get(route('documents.routings.candidates', props.document.id));
        routeCandidates.value = data.users;
    } catch (e) {
        routeCandidatesError.value = 'Failed to load eligible recipients.';
    } finally {
        routeCandidatesLoading.value = false;
    }
};

const closeRouteModal = () => {
    showRouteModal.value = false;
    routeForm.reset();
    routeForm.clearErrors();
};

const submitRoute = () => {
    routeForm.post(route('documents.routings.store', props.document.id), {
        onSuccess: () => {
            showRouteModal.value = false;
            routeForm.reset();
            routeForm.clearErrors();
            closeModal();
        },
    });
};

const allRouteCandidatesSelected  = computed(() => routeForm.user_ids.length === routeCandidates.value.length && routeCandidates.value.length > 0);
const toggleAllRouteCandidates = () => {
    routeForm.user_ids = allRouteCandidatesSelected.value ? [] : routeCandidates.value.map(u => u.id);
};

// ── Cancel a routing round issued by mistake (division chief only) ────────────
const cancelRoutingTarget = ref(null);
const cancelRoutingForm = useForm({});
const confirmCancelRouting = () => {
    cancelRoutingForm.delete(route('documents.routings.cancel', [props.document.id, cancelRoutingTarget.value]), {
        onSuccess: () => { cancelRoutingTarget.value = null; },
    });
};

// ── Cancel a single mistakenly-added recipient, leaving the rest of that
// same routing round (e.g. a correctly-routed chief) untouched ─────────────
const cancelRecipientTarget = ref(null);
const cancelRecipientForm = useForm({});
const confirmCancelRecipient = () => {
    cancelRecipientForm.delete(route('routing-recipients.cancel', cancelRecipientTarget.value), {
        onSuccess: () => { cancelRecipientTarget.value = null; },
    });
};

// ── PDF preview rendering ────────────────────────────────────────────────────
// Rendered via pdf.js onto a canvas sized to the container width, rather than an
// <iframe> — each mobile browser's built-in PDF viewer decides its own initial
// zoom level inside an iframe (often not "fit to width"), so this is the only
// way to guarantee the page actually fits the screen on every device.
const pdfViewportRef = ref(null);
const pdfCanvasRef   = ref(null);
const pdfCurrentPage = ref(1);
const pdfTotalPages  = ref(0);
const pdfLoading     = ref(true);
const pdfPreviewError = ref(null);
const pdfDocPreview  = shallowRef(null);
const pdfRenderTask  = shallowRef(null);
let _pdfjsPreview = null;

const renderPdfPreviewPage = async (num) => {
    if (!pdfDocPreview.value || !pdfCanvasRef.value) return;
    if (pdfRenderTask.value) pdfRenderTask.value.cancel();

    const page = await pdfDocPreview.value.getPage(num);
    // Fall back to a sane default width when the container hasn't been laid
    // out yet (e.g. still inside a modal-open transition) — clientWidth is 0
    // in that case, and computing "(0 - 16) || 800" incorrectly evaluates to
    // -16 (truthy), not the 800 fallback, since only a literal 0 triggers ||.
    // A negative width cascades into a negative/invalid canvas size, which
    // renders nothing — the blank preview this was causing.
    const containerW = (pdfViewportRef.value?.clientWidth || 800) - 16;
    const viewport = page.getViewport({ scale: 1 });
    const cssScale = containerW / viewport.width;

    // Render at device-pixel resolution (not just CSS-pixel), otherwise the
    // canvas bitmap is upscaled by the browser on high-DPI/Retina screens and
    // the text comes out visibly blurry.
    const dpr = window.devicePixelRatio || 1;
    const scaledView = page.getViewport({ scale: cssScale * dpr });

    const canvas = pdfCanvasRef.value;
    canvas.width  = Math.floor(scaledView.width);
    canvas.height = Math.floor(scaledView.height);
    canvas.style.width  = Math.floor(scaledView.width / dpr) + 'px';
    canvas.style.height = Math.floor(scaledView.height / dpr) + 'px';

    pdfRenderTask.value = page.render({ canvasContext: canvas.getContext('2d'), viewport: scaledView });
    try {
        await pdfRenderTask.value.promise;
    } catch (e) {
        if (e?.name !== 'RenderingCancelledException') pdfPreviewError.value = 'Failed to render page.';
    }
};

const goToPdfPreviewPage = async (n) => {
    const clamped = Math.max(1, Math.min(n, pdfTotalPages.value));
    pdfCurrentPage.value = clamped;
    await renderPdfPreviewPage(clamped);
};

const loadPdfPreview = async () => {
    pdfLoading.value = true;
    pdfPreviewError.value = null;
    try {
        // Load pdf.js from the same static public/ file as its worker (see the
        // postinstall script in package.json) so both share identical class
        // definitions — bundling pdf.js separately from the worker breaks
        // private-field access on its classes across the two copies.
        const root = window.location.origin;
        _pdfjsPreview = await import(/* @vite-ignore */ root + '/pdf.min.mjs');
        _pdfjsPreview.GlobalWorkerOptions.workerSrc = root + '/pdf.worker.min.mjs';

        const loadTask = _pdfjsPreview.getDocument({
            url: route('documents.file', props.document.id),
            withCredentials: true,
            // Some scanned/faxed PDFs embed JBIG2-encoded images, which pdf.js
            // v6 decodes via a separate WASM module (jbig2.wasm etc., copied
            // to public/wasm/ by the same postinstall script that copies
            // pdf.min.mjs/pdf.worker.min.mjs) — without this, decoding those
            // images fails silently and they just don't render.
            wasmUrl: root + '/wasm/',
        });
        const doc = await loadTask.promise;

        pdfDocPreview.value  = doc;
        pdfTotalPages.value  = doc.numPages;
        pdfCurrentPage.value = 1;
        pdfLoading.value     = false;

        await nextTick();
        await renderPdfPreviewPage(1);
    } catch (e) {
        pdfPreviewError.value = 'Failed to load PDF: ' + (e.message ?? e);
        pdfLoading.value = false;
    }
};

const unloadPdfPreview = () => {
    if (pdfRenderTask.value) pdfRenderTask.value.cancel();
    pdfDocPreview.value  = null;
    pdfTotalPages.value  = 0;
    pdfCurrentPage.value = 1;
    _pdfjsPreview = null;
};

// Load lazily when the modal opens (only for PDFs); tear down when it closes.
watch(showModal, (open) => {
    if (open && isPdf.value) {
        loadPdfPreview();
    } else if (!open) {
        unloadPdfPreview();
    }
});

// Re-fit the page whenever the modal's available width changes (fullscreen
// toggle, window resize, or phone rotation).
watch(isFullscreen, async () => {
    if (showModal.value && isPdf.value) {
        await nextTick();
        renderPdfPreviewPage(pdfCurrentPage.value);
    }
});

let pdfResizeDebounce;
const onPdfViewportResize = () => {
    clearTimeout(pdfResizeDebounce);
    pdfResizeDebounce = setTimeout(() => {
        if (showModal.value && isPdf.value) renderPdfPreviewPage(pdfCurrentPage.value);
    }, 200);
};
onMounted(()       => window.addEventListener('resize', onPdfViewportResize));
onBeforeUnmount(() => window.removeEventListener('resize', onPdfViewportResize));

// ── Recipient forms ──────────────────────────────────────────────────────────
// For most recipients, acknowledging and completing an assignment happen in
// one step. A division chief is the exception: acknowledging an incoming
// document doesn't finish it, since they may still want to route it to their
// own staff instead of handling it personally — see the "Route Document"
// card and the separate Complete form below, gated on canRoute/status.
const acknowledgeForm = useForm({ response_remarks: '' });
const completeForm = useForm({ response_remarks: '' });

// ── RUSH identifier ──────────────────────────────────────────────────────────
const hasRushRouting = computed(() => (props.document.routings ?? []).some(r => r.is_rush));

const acknowledge = () => {
    acknowledgeForm.patch(route('routing-recipients.acknowledge', props.recipientAssignment.id));
};
const complete = () => {
    completeForm.patch(route('routing-recipients.complete', props.recipientAssignment.id));
};
</script>

<template>
    <Head :title="document.title" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center gap-3">
                <Link :href="route('documents.index')" class="text-gray-400 hover:text-gray-600">
                    <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/>
                    </svg>
                </Link>
                <div>
                    <h2 class="flex items-center gap-2 text-xl font-semibold leading-tight text-gray-800">
                        <span
                            v-if="hasRushRouting"
                            class="inline-flex shrink-0 items-center rounded-full bg-red-600 px-2 py-0.5 text-xs font-bold tracking-wide text-white"
                            title="This document has been routed as RUSH"
                        >
                            RUSH
                        </span>
                        {{ document.title }}
                    </h2>
                    <p class="text-sm text-gray-500 capitalize">{{ document.type }} document</p>
                </div>
            </div>
        </template>

        <div class="py-8">
            <div class="mx-auto max-w-4xl space-y-6 sm:px-6 lg:px-8">

                <!-- ── Document Info Card ─────────────────────────────────── -->
                <div class="overflow-hidden rounded-lg bg-white shadow-sm">
                    <div class="border-b border-gray-100 px-6 py-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                        <h3 class="text-base font-semibold text-gray-800">Document Details</h3>
                        <div class="flex flex-wrap items-center gap-3">
                            <button
                                type="button"
                                class="inline-flex items-center gap-1.5 rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-50 hover:text-gray-900"
                                @click="showModal = true"
                            >
                                <svg class="h-4 w-4 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
                                </svg>
                                View Document
                            </button>
                            <span
                                class="inline-flex items-center whitespace-nowrap rounded-full px-3 py-1 text-xs font-semibold"
                                :class="STATUS_CLASS[document.status] ?? 'bg-gray-100 text-gray-700'"
                            >
                                {{ STATUS_LABEL[document.status] ?? document.status }}
                            </span>
                            <button
                                v-if="canDelete"
                                type="button"
                                class="inline-flex items-center gap-1.5 rounded-lg border border-red-300 bg-white px-3 py-1.5 text-xs font-medium text-red-600 hover:bg-red-50"
                                @click="showDeleteConfirm = true"
                            >
                                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                                </svg>
                                Delete
                            </button>
                        </div>
                    </div>
                    <dl class="grid grid-cols-1 gap-x-6 gap-y-4 p-6 sm:grid-cols-2">
                        <div>
                            <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">Title</dt>
                            <dd class="mt-1 text-sm text-gray-900 font-medium">{{ document.title }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">Uploaded By</dt>
                            <dd class="mt-1 text-sm text-gray-900">{{ document.uploader?.name ?? '—' }}</dd>
                        </div>
                        <div v-if="document.division">
                            <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">Division</dt>
                            <dd class="mt-1 text-sm text-gray-900">{{ document.division.name }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">Date Submitted</dt>
                            <dd class="mt-1 text-sm text-gray-900">{{ formatDate(document.created_at) }}</dd>
                        </div>
                        <div v-if="document.source_origin">
                            <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">Source / Origin</dt>
                            <dd class="mt-1 text-sm text-gray-900">{{ document.source_origin }}</dd>
                        </div>
                        <div v-if="document.control_number">
                            <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">Control Number</dt>
                            <dd class="mt-1 text-sm text-gray-900 font-mono">{{ document.control_number }}</dd>
                        </div>
                        <div v-if="document.reference_number">
                            <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">Reference Number</dt>
                            <dd class="mt-1 text-sm text-gray-900">{{ document.reference_number }}</dd>
                        </div>
                        <div v-if="document.type === 'outgoing'">
                            <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">Release</dt>
                            <dd class="mt-1 text-sm text-gray-900">
                                {{ document.requires_release ? 'Via Records Section' : 'For own use — no release needed' }}
                            </dd>
                        </div>
                        <div v-if="document.description" class="sm:col-span-2">
                            <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">Description</dt>
                            <dd class="mt-1 text-sm text-gray-900">{{ document.description }}</dd>
                        </div>
                    </dl>
                </div>

                <!-- ── Released + stamped PDF download (final derivative, once Records Section has released it) ── -->
                <div v-if="document.status === 'released' && document.release_stamp" class="overflow-hidden rounded-lg border border-emerald-300 bg-emerald-50 shadow-sm">
                    <div class="flex flex-col gap-3 px-6 py-4 sm:flex-row sm:items-center sm:justify-between">
                        <div class="flex items-center gap-3">
                            <svg class="h-8 w-8 shrink-0 text-emerald-700" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
                            </svg>
                            <div>
                                <p class="text-sm font-semibold text-emerald-800">Document Released &amp; Digitally Signed</p>
                                <p class="text-xs text-emerald-700">
                                    Released &amp; signed by {{ document.release_stamp.stamped_by?.name ?? '—' }}
                                    on {{ formatDate(document.release_stamp.stamped_at) }}
                                </p>
                            </div>
                        </div>
                        <div class="flex shrink-0 items-center gap-2">
                            <a
                                :href="route('documents.released-pdf', document.id)"
                                target="_blank"
                                class="inline-flex items-center gap-2 rounded-lg bg-emerald-700 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-800"
                            >
                                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                                </svg>
                                Download Released PDF
                            </a>
                            <button
                                v-if="canMarkEmailed"
                                type="button"
                                class="inline-flex items-center gap-1.5 rounded-lg border border-emerald-300 bg-white px-3 py-2 text-sm font-medium text-emerald-700 hover:bg-emerald-50"
                                @click="showMarkEmailedConfirm = true"
                            >
                                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/>
                                </svg>
                                Mark as Emailed
                            </button>
                        </div>
                    </div>
                    <div v-if="document.emailed_at" class="border-t border-emerald-200 px-6 py-3 text-xs text-emerald-700">
                        Emailed by {{ document.emailed_by?.name ?? '—' }} on {{ formatDate(document.emailed_at) }}
                    </div>
                </div>

                <!-- ── Signed PDF download (shown once document is signed, before release) ── -->
                <div v-else-if="['signed', 'released'].includes(document.status) && document.signature" class="overflow-hidden rounded-lg border border-green-200 bg-green-50 shadow-sm">
                    <div class="flex flex-col gap-3 px-6 py-4 sm:flex-row sm:items-center sm:justify-between">
                        <div class="flex items-center gap-3">
                            <svg class="h-8 w-8 shrink-0 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
                            </svg>
                            <div>
                                <p class="text-sm font-semibold text-green-800">Document Digitally Signed</p>
                                <p class="text-xs text-green-600">
                                    Signed by {{ document.signature.signer?.name ?? '—' }}
                                    on {{ formatDate(document.signature.signed_at) }}
                                </p>
                            </div>
                        </div>
                        <div class="flex shrink-0 items-center gap-2">
                            <a
                                :href="route('documents.signed-pdf', document.id)"
                                target="_blank"
                                class="inline-flex items-center gap-2 rounded-lg bg-green-700 px-4 py-2 text-sm font-medium text-white hover:bg-green-800"
                            >
                                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                                </svg>
                                Download Signed PDF
                            </a>
                            <button
                                v-if="canUndoSignature"
                                type="button"
                                class="inline-flex items-center gap-1.5 rounded-lg border border-amber-300 bg-white px-3 py-2 text-sm font-medium text-amber-700 hover:bg-amber-50"
                                @click="showUndoSignatureConfirm = true"
                            >
                                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h10a5 5 0 010 10H8m-5-10l4-4m-4 4l4 4"/>
                                </svg>
                                Undo &amp; Sign Again
                            </button>
                        </div>
                    </div>
                    <div v-if="document.status === 'released'" class="border-t border-green-200 px-6 py-3 text-xs text-green-700">
                        Released by {{ document.released_by?.name ?? '—' }} on {{ formatDate(document.released_at) }}
                    </div>
                </div>

                <!-- ── Outgoing: Review Chain ─────────────────────────────── -->
                <template v-if="document.type === 'outgoing'">

                    <!-- Stepper -->
                    <div class="overflow-hidden rounded-lg bg-white shadow-sm">
                        <div class="border-b border-gray-100 px-6 py-4">
                            <h3 class="text-base font-semibold text-gray-800">Review Chain</h3>
                        </div>
                        <div class="px-6 py-5">
                            <div v-if="['returned_for_revision','rejected','for_comment'].includes(document.status)" class="mb-4 rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-800">
                                Document was <strong>{{ STATUS_LABEL[document.status] }}</strong>.
                            </div>
                            <!-- Up to 6 stages need ~480px+ of width, more than
                                 fits a phone viewport — scrolls within its own
                                 region (negative margin restores the card's
                                 padding to the scrollable edge) rather than
                                 pushing the whole page into horizontal scroll. -->
                            <div class="-mx-6 overflow-x-auto px-6">
                                <ol class="flex items-center">
                                    <li
                                        v-for="(stage, i) in REVIEW_STAGES"
                                        :key="stage.status"
                                        class="flex items-start"
                                        :class="i < REVIEW_STAGES.length - 1 ? 'flex-1' : ''"
                                    >
                                        <span class="flex w-20 shrink-0 flex-col items-center gap-1">
                                            <span
                                                class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-xs font-bold"
                                                :class="currentStageIndex >= i
                                                    ? 'bg-blue-600 text-white'
                                                    : 'bg-gray-200 text-gray-500'"
                                            >
                                                <svg v-if="currentStageIndex > i" class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"/>
                                                </svg>
                                                <span v-else>{{ i + 1 }}</span>
                                            </span>
                                            <span class="text-xs text-center text-gray-600">{{ stage.label }}</span>
                                        </span>
                                        <span
                                            v-if="i < REVIEW_STAGES.length - 1"
                                            class="mx-2 mt-4 h-0.5 flex-1"
                                            :class="currentStageIndex > i ? 'bg-blue-600' : 'bg-gray-200'"
                                        />
                                    </li>
                                </ol>
                            </div>
                        </div>
                    </div>

                    <!-- Review History -->
                    <div v-if="document.reviews?.length" class="overflow-hidden rounded-lg bg-white shadow-sm">
                        <div class="border-b border-gray-100 px-6 py-4">
                            <h3 class="text-base font-semibold text-gray-800">Review History</h3>
                        </div>
                        <ul class="divide-y divide-gray-100">
                            <li v-for="review in document.reviews" :key="review.id" class="px-6 py-4">
                                <div class="flex items-start justify-between gap-4">
                                    <div>
                                        <p class="text-sm font-medium text-gray-900">
                                            {{ review.reviewer?.name ?? '—' }}
                                            <span class="ml-1 text-xs font-normal text-gray-500 capitalize">({{ review.role_at_review }})</span>
                                        </p>
                                        <p v-if="review.remarks" class="mt-1 text-sm text-gray-600 italic">"{{ review.remarks }}"</p>
                                    </div>
                                    <div class="flex shrink-0 flex-col items-end gap-1">
                                        <span
                                            class="inline-flex items-center whitespace-nowrap rounded-full px-2.5 py-0.5 text-xs font-medium"
                                            :class="REVIEW_ACTION_CLASS[review.action] ?? 'bg-gray-100 text-gray-700'"
                                        >
                                            {{ reviewActionLabel(review) }}
                                        </span>
                                        <span class="text-xs text-gray-400">{{ formatDateTime(review.created_at) }}</span>
                                    </div>
                                </div>
                            </li>
                        </ul>
                    </div>

                    <!-- ── Uploader: correct and resubmit (returned_for_revision / for_comment) ── -->
                    <div v-if="canResubmit" class="overflow-hidden rounded-lg border-2 border-amber-200 bg-white shadow-sm">
                        <div class="border-b border-amber-100 bg-amber-50 px-6 py-4 flex items-start justify-between gap-4">
                            <div>
                                <h3 class="text-base font-semibold text-amber-800">Correct and Resubmit</h3>
                                <p class="mt-0.5 text-sm text-amber-700">
                                    A reviewer asked for changes. Attach a corrected file to resubmit —
                                    this restarts the review chain from the beginning.
                                </p>
                            </div>
                            <Link
                                :href="route('documents.outgoing.resubmit.edit', document.id)"
                                class="shrink-0 inline-flex items-center gap-2 rounded-lg bg-amber-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-amber-700 focus:ring-4 focus:ring-amber-300"
                            >
                                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                                </svg>
                                Correct &amp; Resubmit
                            </Link>
                        </div>
                    </div>

                    <!-- ── Secretary: SDS left a comment that was never acted on ── -->
                    <div v-if="canReturnToOwner" class="overflow-hidden rounded-lg border-2 border-red-200 bg-white shadow-sm">
                        <div class="border-b border-red-100 bg-red-50 px-6 py-4 flex items-start justify-between gap-4">
                            <div>
                                <h3 class="text-base font-semibold text-red-800">SDS Left a Comment — Not Returned</h3>
                                <p class="mt-0.5 text-sm text-red-700">
                                    The SDS's own review has remarks (see Review History below), but the document was
                                    approved instead of sent back for revision. If this looks like an oversight, you
                                    can return it to the owner now instead of signing it.
                                </p>
                            </div>
                            <button
                                type="button"
                                class="shrink-0 inline-flex items-center gap-2 rounded-lg bg-red-700 px-4 py-2.5 text-sm font-semibold text-white hover:bg-red-800 focus:ring-4 focus:ring-red-300"
                                @click="showReturnToOwnerConfirm = true"
                            >
                                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                                </svg>
                                Return to Owner
                            </button>
                        </div>
                    </div>

                    <!-- ── Secretary: missing pages / wrong format found before signing ── -->
                    <div v-if="canReturnForCorrection" class="overflow-hidden rounded-lg border-2 border-amber-200 bg-white shadow-sm">
                        <div class="border-b border-amber-100 bg-amber-50 px-6 py-4 flex items-start justify-between gap-4">
                            <div>
                                <h3 class="text-base font-semibold text-amber-800">Missing Pages or Incorrect Format?</h3>
                                <p class="mt-0.5 text-sm text-amber-700">
                                    If the file has missing pages or doesn't follow the correct format, return it to
                                    the owner for correction instead of signing it.
                                </p>
                            </div>
                            <button
                                type="button"
                                class="shrink-0 inline-flex items-center gap-2 rounded-lg bg-amber-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-amber-700 focus:ring-4 focus:ring-amber-300"
                                @click="showReturnForCorrectionConfirm = true"
                            >
                                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                                </svg>
                                Return for Correction
                            </button>
                        </div>
                    </div>

                    <!-- ── Secretary: Attach PNPKI signature (secretary role, Approved status) ── -->
                    <div v-if="canSign" class="overflow-hidden rounded-lg border-2 border-purple-200 bg-white shadow-sm">
                        <div class="border-b border-purple-100 bg-purple-50 px-6 py-4 flex items-start justify-between gap-4">
                            <div>
                                <h3 class="text-base font-semibold text-purple-800">Attach Digital Signature</h3>
                                <p class="mt-0.5 text-sm text-purple-600">
                                    The SDS has approved this document. Attach the PNPKI digital certificate to finalize it.
                                    The signed file will be tamper-evident.
                                </p>
                            </div>
                            <Link
                                :href="route('documents.sign.create', document.id)"
                                class="shrink-0 inline-flex items-center gap-2 rounded-lg bg-purple-700 px-4 py-2.5 text-sm font-semibold text-white hover:bg-purple-800 focus:ring-4 focus:ring-purple-300"
                            >
                                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
                                </svg>
                                Open Signing Page
                            </Link>
                        </div>
                    </div>

                    <!-- ── Secretary: approved, but the SDS hasn't uploaded a signing credential yet ── -->
                    <div v-if="sdsSignatureMissing" class="overflow-hidden rounded-lg border-2 border-amber-200 bg-white shadow-sm">
                        <div class="flex items-start gap-3 bg-amber-50 px-6 py-4">
                            <svg class="mt-0.5 h-5 w-5 shrink-0 text-amber-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/>
                            </svg>
                            <div>
                                <h3 class="text-base font-semibold text-amber-800">Waiting for the SDS's Signature</h3>
                                <p class="mt-0.5 text-sm text-amber-700">
                                    The SDS has approved this document, but hasn't uploaded their digital signature yet, so it can't be signed.
                                    Please ask the SDS to upload their signing certificate under <span class="font-semibold">My Signature</span>.
                                    The option to sign will appear here once they do.
                                </p>
                            </div>
                        </div>
                    </div>

                    <!-- ── Records Section: mark released (Signed status) ── -->
                    <div v-if="canRelease" class="overflow-hidden rounded-lg border-2 border-emerald-200 bg-white shadow-sm">
                        <div class="border-b border-emerald-100 bg-emerald-50 px-6 py-4 flex items-start justify-between gap-4">
                            <div>
                                <h3 class="text-base font-semibold text-emerald-800">Ready for Release</h3>
                                <p class="mt-0.5 text-sm text-emerald-700">
                                    This document has been signed. Attach your release stamp once it has been physically handed out.
                                </p>
                            </div>
                            <Link
                                :href="route('documents.release.create', document.id)"
                                class="shrink-0 inline-flex items-center gap-2 rounded-lg bg-emerald-700 px-4 py-2.5 text-sm font-semibold text-white hover:bg-emerald-800 focus:ring-4 focus:ring-emerald-300"
                            >
                                Mark as Released
                            </Link>
                        </div>
                    </div>

                    <!-- ── Review form (all reviewers: unit_head, DC, ASDS, SDS) ── -->
                    <div v-if="canReview" class="overflow-hidden rounded-lg border-2 border-blue-200 bg-white shadow-sm">
                        <div class="border-b border-blue-100 bg-blue-50 px-6 py-4">
                            <h3 class="text-base font-semibold text-blue-800">Submit Your Review</h3>
                        </div>
                        <form @submit.prevent="submitReview" class="p-6 space-y-4">
                            <div>
                                <label for="review_action" class="mb-2 block text-sm font-medium text-gray-900">
                                    Action <span class="text-red-500">*</span>
                                </label>
                                <select
                                    id="review_action"
                                    v-model="reviewForm.action"
                                    class="block w-full rounded-lg border border-gray-300 bg-gray-50 p-2.5 text-sm text-gray-900 focus:border-blue-500 focus:ring-blue-500"
                                >
                                    <option value="">— Select action —</option>
                                    <option value="approved_forwarded">
                                        {{ approveActionLabel }}
                                    </option>
                                    <option value="for_comment">For Comment</option>
                                    <option value="returned_for_revision">Return for Revision</option>
                                    <option value="rejected">Reject</option>
                                </select>
                                <p v-if="reviewForm.errors.action" class="mt-1 text-sm text-red-600">{{ reviewForm.errors.action }}</p>
                            </div>
                            <div>
                                <label for="review_remarks" class="mb-2 block text-sm font-medium text-gray-900">
                                    Remarks
                                    <span v-if="remarksRequiredForAction" class="text-red-500">*</span>
                                    <span v-else class="text-gray-400 font-normal">(optional)</span>
                                </label>
                                <textarea
                                    id="review_remarks"
                                    v-model="reviewForm.remarks"
                                    rows="3"
                                    class="block w-full rounded-lg border border-gray-300 bg-gray-50 p-2.5 text-sm text-gray-900 focus:border-blue-500 focus:ring-blue-500"
                                    :placeholder="remarksRequiredForAction ? 'Required — let the originator know what to address.' : 'Optional comments for the originator.'"
                                />
                                <p v-if="reviewForm.errors.remarks" class="mt-1 text-sm text-red-600">{{ reviewForm.errors.remarks }}</p>
                                <p v-if="document.status === 'pending_sds_review'" class="mt-1 text-xs text-gray-500">
                                    Note: if you enter remarks here, this document will be returned for revision instead of approved — you don't need to pick an action, remarks alone are enough.
                                </p>
                            </div>
                            <div class="flex justify-end">
                                <button
                                    type="submit"
                                    :disabled="reviewForm.processing || !canSubmitReview"
                                    class="inline-flex items-center gap-2 rounded-lg bg-blue-700 px-5 py-2.5 text-sm font-medium text-white hover:bg-blue-800 disabled:opacity-60"
                                >
                                    <svg v-if="reviewForm.processing" class="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24">
                                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"/>
                                    </svg>
                                    Submit Review
                                </button>
                            </div>
                        </form>
                    </div>
                </template>

                <!-- ── Incoming: Routing Info ─────────────────────────────── -->
                <template v-if="document.type === 'incoming'">

                    <!-- Route button for SDS/ASDS/division_chief -->
                    <div v-if="canRoute" class="overflow-hidden rounded-lg border-2 border-indigo-200 bg-indigo-50 shadow-sm px-6 py-5 flex items-center justify-between">
                        <div>
                            <h3 class="text-base font-semibold text-indigo-900">Ready to Route</h3>
                            <p class="text-sm text-indigo-700 mt-0.5">Issue an action slip to assign this document to recipients.</p>
                        </div>
                        <Link
                            :href="route('documents.routings.create', document.id)"
                            class="inline-flex items-center rounded-lg bg-indigo-700 px-4 py-2.5 text-sm font-medium text-white hover:bg-indigo-800"
                        >
                            Route Document →
                        </Link>
                    </div>

                    <!-- Secretary: file a Records-Section upload directly, no routing needed -->
                    <div v-if="canArchive" class="overflow-hidden rounded-lg border-2 border-gray-200 bg-gray-50 shadow-sm px-6 py-5 flex items-center justify-between">
                        <div>
                            <h3 class="text-base font-semibold text-gray-800">No Routing Needed?</h3>
                            <p class="text-sm text-gray-600 mt-0.5">
                                If this document doesn't need to go to a division chief, file it directly instead of
                                routing it.
                            </p>
                        </div>
                        <button
                            type="button"
                            class="shrink-0 inline-flex items-center rounded-lg border border-gray-400 bg-white px-4 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-100"
                            @click="showArchiveConfirm = true"
                        >
                            File Document
                        </button>
                    </div>

                    <!-- Action Slips -->
                    <div v-if="document.routings?.length" class="overflow-hidden rounded-lg bg-white shadow-sm">
                        <div class="border-b border-gray-100 px-6 py-4">
                            <h3 class="text-base font-semibold text-gray-800">Action Slips</h3>
                        </div>
                        <div v-for="routing in document.routings" :key="routing.id" class="border-b border-gray-100 last:border-0">
                            <div class="px-6 py-4 bg-gray-50 flex items-center justify-between gap-4">
                                <div>
                                    <p class="flex items-center gap-2 text-sm font-medium text-gray-800">
                                        <span
                                            v-if="routing.is_rush"
                                            class="inline-flex shrink-0 items-center rounded-full bg-red-600 px-2 py-0.5 text-[10px] font-bold tracking-wide text-white"
                                        >
                                            RUSH
                                        </span>
                                        Routed by {{ routing.routed_by?.name ?? '—' }} on {{ formatDate(routing.routed_at) }}
                                        <span
                                            v-if="routing.cancelled_at"
                                            class="inline-flex shrink-0 items-center rounded-full bg-gray-200 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide text-gray-600"
                                        >
                                            Cancelled
                                        </span>
                                    </p>
                                    <p v-if="routing.remarks" class="text-sm text-gray-600 italic mt-0.5">"{{ routing.remarks }}"</p>
                                </div>
                                <button
                                    v-if="routing.can_cancel"
                                    type="button"
                                    class="shrink-0 rounded-lg border border-red-200 bg-white px-3 py-1.5 text-xs font-medium text-red-700 hover:bg-red-50"
                                    @click="cancelRoutingTarget = routing.id"
                                >
                                    Cancel & Reroute
                                </button>
                            </div>
                            <ul class="divide-y divide-gray-100 px-6">
                                <li v-for="r in routing.recipients" :key="r.id" class="py-3 flex items-center justify-between gap-4">
                                    <div>
                                        <p class="text-sm font-medium text-gray-900">
                                            {{ r.user?.name ?? (r.division ? `${r.division.name} (any member)` : '—') }}
                                        </p>
                                        <p class="text-xs text-gray-500">
                                            {{ r.action === 'others' ? r.action_other : (ROUTING_ACTION_LABEL[r.action] ?? r.action) }}
                                        </p>
                                        <p v-if="r.response_remarks" class="text-xs text-gray-600 italic mt-0.5">"{{ r.response_remarks }}"</p>
                                    </div>
                                    <div class="flex shrink-0 items-center gap-2">
                                        <span
                                            class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium capitalize"
                                            :class="RECIPIENT_STATUS_CLASS[r.status] ?? 'bg-gray-100 text-gray-700'"
                                        >
                                            {{ r.status }}
                                        </span>
                                        <button
                                            v-if="r.can_cancel"
                                            type="button"
                                            class="rounded-lg border border-red-200 bg-white px-2.5 py-1 text-xs font-medium text-red-700 hover:bg-red-50"
                                            @click="cancelRecipientTarget = r.id"
                                        >
                                            Cancel
                                        </button>
                                    </div>
                                </li>
                            </ul>
                        </div>
                    </div>

                    <!-- Recipient action (current user's assignment) -->
                    <div v-if="recipientAssignment" class="overflow-hidden rounded-lg border-2 border-green-200 bg-white shadow-sm">
                        <div class="border-b border-green-100 bg-green-50 px-6 py-4">
                            <h3 class="text-base font-semibold text-green-800">Your Assignment</h3>
                            <p class="text-sm text-green-700 mt-0.5">
                                Action:
                                <strong>
                                    {{ recipientAssignment.action === 'others'
                                        ? recipientAssignment.action_other
                                        : (ROUTING_ACTION_LABEL[recipientAssignment.action] ?? recipientAssignment.action) }}
                                </strong>
                            </p>
                        </div>
                        <div class="p-6 space-y-4">
                            <!-- Acknowledge (& Complete, unless this may still be routed to staff) -->
                            <form v-if="recipientAssignment.status === 'pending'" @submit.prevent="acknowledge" class="space-y-3">
                                <p class="text-sm text-gray-600">
                                    Confirm you have received and read this document, and note what action was taken.
                                </p>
                                <div>
                                    <label for="response_remarks" class="mb-2 block text-sm font-medium text-gray-900">
                                        Response / Remarks <span class="text-gray-400 font-normal">(optional)</span>
                                    </label>
                                    <textarea
                                        id="response_remarks"
                                        v-model="acknowledgeForm.response_remarks"
                                        rows="3"
                                        class="block w-full rounded-lg border border-gray-300 bg-gray-50 p-2.5 text-sm text-gray-900 focus:border-blue-500 focus:ring-blue-500"
                                        placeholder="Describe what action was taken..."
                                    />
                                </div>
                                <button
                                    type="submit"
                                    :disabled="acknowledgeForm.processing"
                                    class="inline-flex items-center gap-2 rounded-lg bg-green-700 px-5 py-2.5 text-sm font-medium text-white hover:bg-green-800 disabled:opacity-60"
                                >
                                    <svg v-if="acknowledgeForm.processing" class="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24">
                                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"/>
                                    </svg>
                                    {{ recipientMayDeferCompletion ? 'Acknowledge' : 'Acknowledge & Complete' }}
                                </button>
                            </form>

                            <!-- Acknowledged, not yet completed (division chief only) — either
                                 route it to staff (see the Route Document card above) or
                                 complete it personally here. -->
                            <form v-else-if="recipientAssignment.status === 'acknowledged'" @submit.prevent="complete" class="space-y-3">
                                <p class="text-sm text-gray-600">
                                    Acknowledged on {{ formatDateTime(recipientAssignment.acknowledged_at) }}.
                                    Route it to your staff above, or mark it complete yourself below.
                                </p>
                                <div>
                                    <label for="complete_response_remarks" class="mb-2 block text-sm font-medium text-gray-900">
                                        Response / Remarks <span class="text-gray-400 font-normal">(optional)</span>
                                    </label>
                                    <textarea
                                        id="complete_response_remarks"
                                        v-model="completeForm.response_remarks"
                                        rows="3"
                                        class="block w-full rounded-lg border border-gray-300 bg-gray-50 p-2.5 text-sm text-gray-900 focus:border-blue-500 focus:ring-blue-500"
                                        :placeholder="recipientAssignment.response_remarks || 'Describe what action was taken...'"
                                    />
                                </div>
                                <button
                                    type="submit"
                                    :disabled="completeForm.processing"
                                    class="inline-flex items-center gap-2 rounded-lg bg-blue-700 px-5 py-2.5 text-sm font-medium text-white hover:bg-blue-800 disabled:opacity-60"
                                >
                                    <svg v-if="completeForm.processing" class="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24">
                                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"/>
                                    </svg>
                                    Mark as Complete
                                </button>
                            </form>

                            <!-- Done -->
                            <p v-else class="text-sm text-green-700 font-medium">
                                ✓ Completed on {{ formatDateTime(recipientAssignment.completed_at) }}
                            </p>
                        </div>
                    </div>
                </template>

                <!-- ── Audit Log ───────────────────────────────────────────── -->
                <div v-if="document.audit_logs?.length" class="overflow-hidden rounded-lg bg-white shadow-sm">
                    <details>
                        <summary class="cursor-pointer border-b border-gray-100 px-6 py-4 text-base font-semibold text-gray-800 hover:bg-gray-50">
                            Audit Log ({{ document.audit_logs.length }} entries)
                        </summary>
                        <ul class="divide-y divide-gray-100 text-sm">
                            <li v-for="log in document.audit_logs" :key="log.id" class="flex items-start justify-between gap-4 px-6 py-3">
                                <div>
                                    <span class="font-mono text-xs text-gray-500">{{ log.action }}</span>
                                    <span v-if="log.user" class="ml-2 text-gray-700">by {{ log.user.name }}</span>
                                </div>
                                <span class="shrink-0 text-xs text-gray-400">{{ formatDateTime(log.created_at) }}</span>
                            </li>
                        </ul>
                    </details>
                </div>

            </div>
        </div>
    </AuthenticatedLayout>

    <!-- ── Delete Confirmation Modal ────────────────────────────────────────── -->
    <Teleport to="body">
        <Transition name="modal">
            <div
                v-if="showDeleteConfirm"
                class="fixed inset-0 z-50 flex items-center justify-center px-4"
                role="dialog"
                aria-modal="true"
            >
                <div class="absolute inset-0 bg-black/60 backdrop-blur-sm" @click="showDeleteConfirm = false" />
                <div class="relative z-10 w-full max-w-md rounded-xl bg-white p-6 shadow-2xl">
                    <div class="flex items-start gap-4">
                        <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-red-100">
                            <svg class="h-5 w-5 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                            </svg>
                        </div>
                        <div class="flex-1">
                            <h3 class="text-base font-semibold text-gray-900">Delete Document</h3>
                            <p class="mt-1 text-sm text-gray-600">
                                Are you sure you want to delete <strong>{{ document.title }}</strong>?
                                This action cannot be undone.
                            </p>
                        </div>
                    </div>
                    <div class="mt-5 flex justify-end gap-3">
                        <button
                            type="button"
                            class="rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50"
                            @click="showDeleteConfirm = false"
                        >
                            Cancel
                        </button>
                        <button
                            type="button"
                            :disabled="deleteForm.processing"
                            class="inline-flex items-center gap-2 rounded-lg bg-red-700 px-4 py-2 text-sm font-medium text-white hover:bg-red-800 disabled:opacity-60"
                            @click="confirmDelete"
                        >
                            <svg v-if="deleteForm.processing" class="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"/>
                            </svg>
                            Delete
                        </button>
                    </div>
                </div>
            </div>
        </Transition>
    </Teleport>

    <!-- ── Undo Signature Confirmation Modal ───────────────────────────────── -->
    <Teleport to="body">
        <Transition name="modal">
            <div
                v-if="showUndoSignatureConfirm"
                class="fixed inset-0 z-50 flex items-center justify-center px-4"
                role="dialog"
                aria-modal="true"
            >
                <div class="absolute inset-0 bg-black/60 backdrop-blur-sm" @click="showUndoSignatureConfirm = false" />
                <div class="relative z-10 w-full max-w-md rounded-xl bg-white p-6 shadow-2xl">
                    <div class="flex items-start gap-4">
                        <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-amber-100">
                            <svg class="h-5 w-5 text-amber-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                            </svg>
                        </div>
                        <div class="flex-1">
                            <h3 class="text-base font-semibold text-gray-900">Undo Signature</h3>
                            <p class="mt-1 text-sm text-gray-600">
                                This returns <strong>{{ document.title }}</strong> to <strong>Approved</strong> so you can sign it again —
                                use this if the SDS's signature was placed incorrectly. The previous signed copy stays on record.
                            </p>
                            <div class="mt-3">
                                <label for="undo_signature_remarks" class="mb-1 block text-xs font-medium text-gray-700">
                                    Reason <span class="text-gray-400 font-normal">(optional)</span>
                                </label>
                                <textarea
                                    id="undo_signature_remarks"
                                    v-model="undoSignatureForm.remarks"
                                    rows="2"
                                    class="block w-full rounded-lg border border-gray-300 bg-gray-50 p-2 text-sm text-gray-900 focus:border-amber-500 focus:ring-amber-500"
                                    placeholder="e.g. signature was placed on the wrong page"
                                />
                            </div>
                        </div>
                    </div>
                    <div class="mt-5 flex justify-end gap-3">
                        <button
                            type="button"
                            class="rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50"
                            @click="showUndoSignatureConfirm = false"
                        >
                            Cancel
                        </button>
                        <button
                            type="button"
                            :disabled="undoSignatureForm.processing"
                            class="inline-flex items-center gap-2 rounded-lg bg-amber-600 px-4 py-2 text-sm font-medium text-white hover:bg-amber-700 disabled:opacity-60"
                            @click="confirmUndoSignature"
                        >
                            <svg v-if="undoSignatureForm.processing" class="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"/>
                            </svg>
                            Undo Signature
                        </button>
                    </div>
                </div>
            </div>
        </Transition>
    </Teleport>

    <!-- ── Return to Owner Confirmation Modal ──────────────────────────────── -->
    <Teleport to="body">
        <Transition name="modal">
            <div
                v-if="showReturnToOwnerConfirm"
                class="fixed inset-0 z-50 flex items-center justify-center px-4"
                role="dialog"
                aria-modal="true"
            >
                <div class="absolute inset-0 bg-black/60 backdrop-blur-sm" @click="showReturnToOwnerConfirm = false" />
                <div class="relative z-10 w-full max-w-md rounded-xl bg-white p-6 shadow-2xl">
                    <div class="flex items-start gap-4">
                        <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-red-100">
                            <svg class="h-5 w-5 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                            </svg>
                        </div>
                        <div class="flex-1">
                            <h3 class="text-base font-semibold text-gray-900">Return to Owner</h3>
                            <p class="mt-1 text-sm text-gray-600">
                                This sends <strong>{{ document.title }}</strong> back to <strong>{{ document.uploader?.name ?? 'the owner' }}</strong>
                                for revision instead of signing it — the review chain restarts once they resubmit.
                            </p>
                            <div class="mt-3">
                                <label for="return_to_owner_remarks" class="mb-1 block text-xs font-medium text-gray-700">
                                    Note for the owner <span class="text-gray-400 font-normal">(optional)</span>
                                </label>
                                <textarea
                                    id="return_to_owner_remarks"
                                    v-model="returnToOwnerForm.remarks"
                                    rows="2"
                                    class="block w-full rounded-lg border border-gray-300 bg-gray-50 p-2 text-sm text-gray-900 focus:border-red-500 focus:ring-red-500"
                                    placeholder="e.g. see the SDS's earlier comment above"
                                />
                            </div>
                        </div>
                    </div>
                    <div class="mt-5 flex justify-end gap-3">
                        <button
                            type="button"
                            class="rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50"
                            @click="showReturnToOwnerConfirm = false"
                        >
                            Cancel
                        </button>
                        <button
                            type="button"
                            :disabled="returnToOwnerForm.processing"
                            class="inline-flex items-center gap-2 rounded-lg bg-red-700 px-4 py-2 text-sm font-medium text-white hover:bg-red-800 disabled:opacity-60"
                            @click="confirmReturnToOwner"
                        >
                            <svg v-if="returnToOwnerForm.processing" class="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"/>
                            </svg>
                            Return to Owner
                        </button>
                    </div>
                </div>
            </div>
        </Transition>
    </Teleport>

    <!-- ── Return for Correction Confirmation Modal ────────────────────────── -->
    <Teleport to="body">
        <Transition name="modal">
            <div
                v-if="showReturnForCorrectionConfirm"
                class="fixed inset-0 z-50 flex items-center justify-center px-4"
                role="dialog"
                aria-modal="true"
            >
                <div class="absolute inset-0 bg-black/60 backdrop-blur-sm" @click="showReturnForCorrectionConfirm = false" />
                <div class="relative z-10 w-full max-w-md rounded-xl bg-white p-6 shadow-2xl">
                    <div class="flex items-start gap-4">
                        <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-amber-100">
                            <svg class="h-5 w-5 text-amber-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                            </svg>
                        </div>
                        <div class="flex-1">
                            <h3 class="text-base font-semibold text-gray-900">Return for Correction</h3>
                            <p class="mt-1 text-sm text-gray-600">
                                This sends <strong>{{ document.title }}</strong> back to <strong>{{ document.uploader?.name ?? 'the owner' }}</strong>
                                for revision instead of signing it — the review chain restarts once they resubmit.
                            </p>
                            <div class="mt-3">
                                <label for="return_for_correction_remarks" class="mb-1 block text-xs font-medium text-gray-700">
                                    What needs to be fixed? <span class="text-red-500">*</span>
                                </label>
                                <textarea
                                    id="return_for_correction_remarks"
                                    v-model="returnForCorrectionForm.remarks"
                                    rows="2"
                                    class="block w-full rounded-lg border border-gray-300 bg-gray-50 p-2 text-sm text-gray-900 focus:border-amber-500 focus:ring-amber-500"
                                    placeholder="e.g. missing page 3, wrong letterhead format"
                                    required
                                />
                                <p v-if="returnForCorrectionForm.errors.remarks" class="mt-1 text-xs text-red-600">{{ returnForCorrectionForm.errors.remarks }}</p>
                            </div>
                        </div>
                    </div>
                    <div class="mt-5 flex justify-end gap-3">
                        <button
                            type="button"
                            class="rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50"
                            @click="showReturnForCorrectionConfirm = false"
                        >
                            Cancel
                        </button>
                        <button
                            type="button"
                            :disabled="returnForCorrectionForm.processing || returnForCorrectionForm.remarks.trim() === ''"
                            class="inline-flex items-center gap-2 rounded-lg bg-amber-600 px-4 py-2 text-sm font-medium text-white hover:bg-amber-700 disabled:opacity-60"
                            @click="confirmReturnForCorrection"
                        >
                            <svg v-if="returnForCorrectionForm.processing" class="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"/>
                            </svg>
                            Return for Correction
                        </button>
                    </div>
                </div>
            </div>
        </Transition>
    </Teleport>

    <!-- ── File Without Routing Confirmation Modal ─────────────────────────── -->
    <Teleport to="body">
        <Transition name="modal">
            <div
                v-if="showArchiveConfirm"
                class="fixed inset-0 z-50 flex items-center justify-center px-4"
                role="dialog"
                aria-modal="true"
            >
                <div class="absolute inset-0 bg-black/60 backdrop-blur-sm" @click="showArchiveConfirm = false" />
                <div class="relative z-10 w-full max-w-md rounded-xl bg-white p-6 shadow-2xl">
                    <div class="flex items-start gap-4">
                        <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-gray-100">
                            <svg class="h-5 w-5 text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 8h14M5 8a2 2 0 110-4h14a2 2 0 110 4M5 8v10a2 2 0 002 2h10a2 2 0 002-2V8"/>
                            </svg>
                        </div>
                        <div class="flex-1">
                            <h3 class="text-base font-semibold text-gray-900">File Document</h3>
                            <p class="mt-1 text-sm text-gray-600">
                                This closes out <strong>{{ document.title }}</strong> without routing it to anyone —
                                use this only when no division chief needs to act on it. This cannot be undone.
                            </p>
                            <div class="mt-3">
                                <label for="archive_remarks" class="mb-1 block text-xs font-medium text-gray-700">
                                    Note <span class="text-gray-400 font-normal">(optional)</span>
                                </label>
                                <textarea
                                    id="archive_remarks"
                                    v-model="archiveForm.remarks"
                                    rows="2"
                                    class="block w-full rounded-lg border border-gray-300 bg-gray-50 p-2 text-sm text-gray-900 focus:border-gray-500 focus:ring-gray-500"
                                    placeholder="e.g. informational copy only, no action needed"
                                />
                            </div>
                        </div>
                    </div>
                    <div class="mt-5 flex justify-end gap-3">
                        <button
                            type="button"
                            class="rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50"
                            @click="showArchiveConfirm = false"
                        >
                            Cancel
                        </button>
                        <button
                            type="button"
                            :disabled="archiveForm.processing"
                            class="inline-flex items-center gap-2 rounded-lg bg-gray-700 px-4 py-2 text-sm font-medium text-white hover:bg-gray-800 disabled:opacity-60"
                            @click="confirmArchive"
                        >
                            <svg v-if="archiveForm.processing" class="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"/>
                            </svg>
                            File Document
                        </button>
                    </div>
                </div>
            </div>
        </Transition>
    </Teleport>

    <!-- ── Cancel Routing Confirmation Modal ───────────────────────────────── -->
    <Teleport to="body">
        <Transition name="modal">
            <div
                v-if="cancelRoutingTarget"
                class="fixed inset-0 z-50 flex items-center justify-center px-4"
                role="dialog"
                aria-modal="true"
            >
                <div class="absolute inset-0 bg-black/60 backdrop-blur-sm" @click="cancelRoutingTarget = null" />
                <div class="relative z-10 w-full max-w-md rounded-xl bg-white p-6 shadow-2xl">
                    <div class="flex items-start gap-4">
                        <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-red-100">
                            <svg class="h-5 w-5 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                            </svg>
                        </div>
                        <div class="flex-1">
                            <h3 class="text-base font-semibold text-gray-900">Cancel & Reroute</h3>
                            <p class="mt-1 text-sm text-gray-600">
                                This withdraws this action slip from its recipient(s) — use this if the wrong staff
                                member was selected. Only possible because nobody has acknowledged it yet. You'll be
                                able to route <strong>{{ document.title }}</strong> again right after.
                            </p>
                        </div>
                    </div>
                    <div class="mt-5 flex justify-end gap-3">
                        <button
                            type="button"
                            class="rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50"
                            @click="cancelRoutingTarget = null"
                        >
                            Never mind
                        </button>
                        <button
                            type="button"
                            :disabled="cancelRoutingForm.processing"
                            class="inline-flex items-center gap-2 rounded-lg bg-red-600 px-4 py-2 text-sm font-medium text-white hover:bg-red-700 disabled:opacity-60"
                            @click="confirmCancelRouting"
                        >
                            <svg v-if="cancelRoutingForm.processing" class="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"/>
                            </svg>
                            Cancel & Reroute
                        </button>
                    </div>
                </div>
            </div>
        </Transition>
    </Teleport>

    <!-- ── Cancel Recipient Confirmation Modal ─────────────────────────────── -->
    <Teleport to="body">
        <Transition name="modal">
            <div
                v-if="cancelRecipientTarget"
                class="fixed inset-0 z-50 flex items-center justify-center px-4"
                role="dialog"
                aria-modal="true"
            >
                <div class="absolute inset-0 bg-black/60 backdrop-blur-sm" @click="cancelRecipientTarget = null" />
                <div class="relative z-10 w-full max-w-md rounded-xl bg-white p-6 shadow-2xl">
                    <div class="flex items-start gap-4">
                        <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-red-100">
                            <svg class="h-5 w-5 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                            </svg>
                        </div>
                        <div class="flex-1">
                            <h3 class="text-base font-semibold text-gray-900">Cancel This Recipient</h3>
                            <p class="mt-1 text-sm text-gray-600">
                                This withdraws the routing for this recipient only — any other recipient on the same
                                action slip is unaffected and keeps their assignment as-is.
                            </p>
                        </div>
                    </div>
                    <div class="mt-5 flex justify-end gap-3">
                        <button
                            type="button"
                            class="rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50"
                            @click="cancelRecipientTarget = null"
                        >
                            Never mind
                        </button>
                        <button
                            type="button"
                            :disabled="cancelRecipientForm.processing"
                            class="inline-flex items-center gap-2 rounded-lg bg-red-600 px-4 py-2 text-sm font-medium text-white hover:bg-red-700 disabled:opacity-60"
                            @click="confirmCancelRecipient"
                        >
                            <svg v-if="cancelRecipientForm.processing" class="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"/>
                            </svg>
                            Cancel Recipient
                        </button>
                    </div>
                </div>
            </div>
        </Transition>
    </Teleport>

    <!-- ── Mark as Emailed Confirmation Modal ──────────────────────────────── -->
    <Teleport to="body">
        <Transition name="modal">
            <div
                v-if="showMarkEmailedConfirm"
                class="fixed inset-0 z-50 flex items-center justify-center px-4"
                role="dialog"
                aria-modal="true"
            >
                <div class="absolute inset-0 bg-black/60 backdrop-blur-sm" @click="showMarkEmailedConfirm = false" />
                <div class="relative z-10 w-full max-w-md rounded-xl bg-white p-6 shadow-2xl">
                    <div class="flex items-start gap-4">
                        <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-emerald-100">
                            <svg class="h-5 w-5 text-emerald-700" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/>
                            </svg>
                        </div>
                        <div class="flex-1">
                            <h3 class="text-base font-semibold text-gray-900">Mark as Emailed</h3>
                            <p class="mt-1 text-sm text-gray-600">
                                Confirm you've downloaded the released PDF of <strong>{{ document.title }}</strong> and emailed it out.
                                The uploading office will be notified.
                            </p>
                            <div class="mt-3">
                                <label for="mark_emailed_remarks" class="mb-1 block text-xs font-medium text-gray-700">
                                    Notes <span class="text-gray-400 font-normal">(optional)</span>
                                </label>
                                <textarea
                                    id="mark_emailed_remarks"
                                    v-model="markEmailedForm.remarks"
                                    rows="2"
                                    class="block w-full rounded-lg border border-gray-300 bg-gray-50 p-2 text-sm text-gray-900 focus:border-emerald-500 focus:ring-emerald-500"
                                    placeholder="e.g. sent to the recipient's registrar office"
                                />
                            </div>
                        </div>
                    </div>
                    <div class="mt-5 flex justify-end gap-3">
                        <button
                            type="button"
                            class="rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50"
                            @click="showMarkEmailedConfirm = false"
                        >
                            Cancel
                        </button>
                        <button
                            type="button"
                            :disabled="markEmailedForm.processing"
                            class="inline-flex items-center gap-2 rounded-lg bg-emerald-700 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-800 disabled:opacity-60"
                            @click="confirmMarkEmailed"
                        >
                            <svg v-if="markEmailedForm.processing" class="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"/>
                            </svg>
                            Mark as Emailed
                        </button>
                    </div>
                </div>
            </div>
        </Transition>
    </Teleport>

    <!-- ── Document Preview Modal ──────────────────────────────────────────── -->
    <Teleport to="body">
        <Transition name="modal">
            <div
                v-if="showModal"
                class="fixed inset-0 z-50 flex items-end justify-center sm:items-center"
                role="dialog"
                aria-modal="true"
                :aria-label="`Preview: ${document.title}`"
            >
                <!-- Backdrop -->
                <div
                    class="absolute inset-0 bg-black/60 backdrop-blur-sm"
                    @click="closeModal"
                />

                <!-- Modal panel -->
                <div
                    class="relative z-10 flex flex-col bg-white shadow-2xl transition-all duration-200"
                    :class="isFullscreen
                        ? 'w-full h-dvh'
                        : 'w-full h-[92dvh] sm:rounded-2xl sm:w-[95vw] sm:h-[90vh] sm:max-w-5xl lg:max-w-6xl'"
                >

                    <!-- Header -->
                    <div class="flex items-center justify-between gap-4 border-b border-gray-200 px-4 py-3 shrink-0">
                        <div class="min-w-0">
                            <p class="text-xs text-gray-500 uppercase tracking-wide font-medium">Document Preview</p>
                            <h3 class="text-sm font-semibold text-gray-900 truncate leading-tight">{{ document.title }}</h3>
                        </div>
                        <div class="flex shrink-0 items-center gap-1.5">
                            <!-- Open in new tab (fallback / print) -->
                            <a
                                :href="route('documents.file', document.id)"
                                target="_blank"
                                rel="noopener"
                                class="inline-flex items-center gap-1.5 rounded-lg border border-gray-300 px-3 py-1.5 text-xs font-medium text-gray-600 hover:bg-gray-50"
                            >
                                <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/>
                                </svg>
                                <span class="hidden sm:inline">Open in tab</span>
                            </a>
                            <!-- Fullscreen toggle -->
                            <button
                                type="button"
                                class="flex h-9 w-9 items-center justify-center rounded-full text-gray-500 hover:bg-gray-100 hover:text-gray-700 focus:outline-none"
                                @click="toggleFullscreen"
                                :aria-label="isFullscreen ? 'Exit full screen' : 'Full screen'"
                                :title="isFullscreen ? 'Exit full screen (F)' : 'Full screen (F)'"
                            >
                                <!-- Expand icon -->
                                <svg v-if="!isFullscreen" class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 8V6a2 2 0 012-2h2M4 16v2a2 2 0 002 2h2m8-16h2a2 2 0 012 2v2m0 8v2a2 2 0 01-2 2h-2"/>
                                </svg>
                                <!-- Compress icon -->
                                <svg v-else class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 9V6m0 3H6m3 0l-3-3m9 3V6m0 3h3m-3 0l3-3M9 15v3m0-3H6m3 0l-3 3m9-3v3m0-3h3m-3 0l3 3"/>
                                </svg>
                            </button>
                            <!-- Close -->
                            <button
                                type="button"
                                class="flex h-9 w-9 items-center justify-center rounded-full text-gray-500 hover:bg-gray-100 hover:text-gray-700 focus:outline-none"
                                @click="closeModal"
                                aria-label="Close preview"
                                title="Close (Esc)"
                            >
                                <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                                </svg>
                            </button>
                        </div>
                    </div>

                    <!-- ── Quick actions: route / review, without leaving the preview ── -->
                    <div v-if="canRoute || canReview" class="shrink-0 border-b border-gray-200 bg-white p-3 sm:p-4">
                        <!-- Route -->
                        <div v-if="canRoute" class="flex flex-col items-start gap-2 sm:flex-row sm:items-center sm:justify-between">
                            <p class="text-sm text-gray-600">This document is ready to be routed to recipients.</p>
                            <button
                                type="button"
                                class="inline-flex shrink-0 items-center gap-2 rounded-lg bg-indigo-700 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-800"
                                @click="openRouteModal"
                            >
                                Route Document →
                            </button>
                        </div>

                        <!-- Review -->
                        <form v-if="canReview" @submit.prevent="submitReview" class="space-y-2">
                            <div class="flex flex-col gap-2 sm:flex-row sm:items-start">
                                <div class="flex-1">
                                    <select
                                        v-model="reviewForm.action"
                                        class="block w-full rounded-lg border border-gray-300 bg-gray-50 p-2 text-sm text-gray-900 focus:border-blue-500 focus:ring-blue-500"
                                    >
                                        <option value="">— Select action —</option>
                                        <option value="approved_forwarded">{{ approveActionLabel }}</option>
                                        <option value="for_comment">For Comment</option>
                                        <option value="returned_for_revision">Return for Revision</option>
                                        <option value="rejected">Reject</option>
                                    </select>
                                    <p v-if="reviewForm.errors.action" class="mt-1 text-xs text-red-600">{{ reviewForm.errors.action }}</p>
                                </div>
                                <button
                                    type="submit"
                                    :disabled="reviewForm.processing || !canSubmitReview"
                                    class="inline-flex shrink-0 items-center justify-center gap-2 rounded-lg bg-blue-700 px-5 py-2 text-sm font-medium text-white hover:bg-blue-800 disabled:opacity-60"
                                >
                                    Submit Review
                                </button>
                            </div>
                            <div>
                                <textarea
                                    v-model="reviewForm.remarks"
                                    rows="2"
                                    :placeholder="remarksRequiredForAction ? 'Remarks (required for this action)…' : 'Remarks (optional)…'"
                                    class="block w-full rounded-lg border border-gray-300 bg-gray-50 p-2 text-sm text-gray-900 focus:border-blue-500 focus:ring-blue-500"
                                />
                                <p v-if="reviewForm.errors.remarks" class="mt-1 text-xs text-red-600">{{ reviewForm.errors.remarks }}</p>
                                <p v-if="document.status === 'pending_sds_review'" class="mt-1 text-xs text-gray-500">
                                    Note: if you enter remarks here, this document will be returned for revision instead of approved — you don't need to pick an action, remarks alone are enough.
                                </p>
                            </div>
                        </form>
                    </div>

                    <!-- Content area -->
                    <div class="flex-1 overflow-auto bg-gray-100">

                        <!-- Images: scaled to fit and scrollable, not rendered at native size -->
                        <div v-if="isImage" class="flex min-h-full items-start justify-center p-2 sm:p-4">
                            <img
                                :src="route('documents.file', document.id)"
                                :alt="document.title"
                                class="max-w-full h-auto"
                            />
                        </div>

                        <!-- PDF — rendered via pdf.js so the page reliably fits the screen on mobile -->
                        <div v-else-if="isPdf" ref="pdfViewportRef" class="flex h-full flex-col">
                            <div v-if="pdfLoading" class="flex flex-1 items-center justify-center text-gray-500">
                                <svg class="mr-2 h-5 w-5 animate-spin" fill="none" viewBox="0 0 24 24">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"/>
                                </svg>
                                Loading document…
                            </div>
                            <div v-else-if="pdfPreviewError" class="flex flex-1 items-center justify-center p-8 text-center text-sm text-red-600">
                                {{ pdfPreviewError }}
                            </div>
                            <template v-else>
                                <div class="flex flex-1 items-start justify-center overflow-auto p-2 sm:p-4">
                                    <canvas ref="pdfCanvasRef" class="block max-w-full shadow-sm" />
                                </div>
                                <div
                                    v-if="pdfTotalPages > 1"
                                    class="flex shrink-0 items-center justify-center gap-3 border-t border-gray-200 bg-white px-4 py-2 text-sm"
                                >
                                    <button
                                        type="button"
                                        :disabled="pdfCurrentPage <= 1"
                                        class="rounded border border-gray-300 px-2 py-1 text-xs hover:bg-gray-50 disabled:opacity-40"
                                        @click="goToPdfPreviewPage(pdfCurrentPage - 1)"
                                    >‹ Prev</button>
                                    <span class="text-gray-500">Page {{ pdfCurrentPage }} / {{ pdfTotalPages }}</span>
                                    <button
                                        type="button"
                                        :disabled="pdfCurrentPage >= pdfTotalPages"
                                        class="rounded border border-gray-300 px-2 py-1 text-xs hover:bg-gray-50 disabled:opacity-40"
                                        @click="goToPdfPreviewPage(pdfCurrentPage + 1)"
                                    >Next ›</button>
                                </div>
                            </template>
                        </div>

                        <!-- Non-previewable (DOCX, DOC, etc.) -->
                        <div
                            v-else
                            class="flex h-full flex-col items-center justify-center gap-5 p-8 text-center"
                        >
                            <div class="flex h-16 w-16 items-center justify-center rounded-full bg-gray-200">
                                <svg class="h-8 w-8 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                                </svg>
                            </div>
                            <div>
                                <p class="text-sm font-medium text-gray-800">
                                    This file type cannot be previewed in the browser.
                                </p>
                                <p class="mt-1 text-xs text-gray-500">
                                    Download the file to open it in the appropriate application.
                                </p>
                            </div>
                            <a
                                :href="route('documents.file', document.id)"
                                download
                                class="inline-flex items-center gap-2 rounded-lg bg-blue-700 px-5 py-2.5 text-sm font-medium text-white hover:bg-blue-800"
                            >
                                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                                </svg>
                                Download File
                            </a>
                        </div>

                    </div>
                </div>
            </div>
        </Transition>
    </Teleport>

    <!-- ── Route Modal (stacked on top of the Document Preview modal) ─────────── -->
    <Teleport to="body">
        <Transition name="modal">
            <div
                v-if="showRouteModal"
                class="fixed inset-0 z-[60] flex items-end justify-center sm:items-center"
                role="dialog"
                aria-modal="true"
                aria-label="Route Document"
            >
                <!-- Backdrop -->
                <div
                    class="absolute inset-0 bg-black/60 backdrop-blur-sm"
                    @click="closeRouteModal"
                />

                <!-- Modal panel -->
                <div class="relative z-10 flex max-h-[92dvh] w-full flex-col bg-white shadow-2xl transition-all duration-200 sm:max-h-[85vh] sm:w-[95vw] sm:max-w-3xl sm:rounded-2xl">

                    <!-- Header -->
                    <div class="flex items-center justify-between gap-4 border-b border-gray-200 px-4 py-3 shrink-0">
                        <div class="min-w-0">
                            <p class="text-xs text-gray-500 uppercase tracking-wide font-medium">Routing Action Slip</p>
                            <h3 class="text-sm font-semibold text-gray-900 truncate leading-tight">{{ document.title }}</h3>
                        </div>
                        <button
                            type="button"
                            class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full text-gray-500 hover:bg-gray-100 hover:text-gray-700 focus:outline-none"
                            @click="closeRouteModal"
                            aria-label="Close routing form"
                            title="Close (Esc)"
                        >
                            <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                            </svg>
                        </button>
                    </div>

                    <!-- Loading / error -->
                    <div v-if="routeCandidatesLoading" class="flex flex-1 items-center justify-center p-10 text-sm text-gray-500">
                        <svg class="mr-2 h-5 w-5 animate-spin" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"/>
                        </svg>
                        Loading eligible recipients…
                    </div>
                    <div v-else-if="routeCandidatesError" class="flex flex-1 items-center justify-center p-10 text-sm text-red-600">
                        {{ routeCandidatesError }}
                    </div>

                    <form v-else @submit.prevent="submitRoute" class="flex min-h-0 flex-1 flex-col overflow-hidden">

                        <!-- RUSH toggle -->
                        <div class="flex shrink-0 items-center gap-3 border-b border-gray-100 px-6 py-3">
                            <label class="flex cursor-pointer items-center gap-3 select-none">
                                <input
                                    v-model="routeForm.is_rush"
                                    type="checkbox"
                                    class="h-4 w-4 rounded border-gray-300 text-red-600 focus:ring-red-500"
                                />
                                <div>
                                    <span class="text-sm font-bold text-red-600">RUSH</span>
                                    <span v-if="routeForm.is_rush" class="ml-2 text-xs text-red-500">— Requires immediate action</span>
                                </div>
                            </label>
                        </div>

                        <!-- Two-column: recipients + actions — kept side by side even on small screens -->
                        <div class="grid flex-1 grid-cols-2 divide-x divide-gray-100 overflow-y-auto">

                            <!-- LEFT: Recipients -->
                            <div class="flex min-w-0 flex-col">
                                <div class="flex flex-col gap-1 border-b border-gray-100 px-2 py-2 sm:flex-row sm:items-center sm:justify-between sm:px-4 sm:py-2.5">
                                    <h4 class="text-[11px] font-semibold uppercase tracking-wide text-gray-500 sm:text-xs">
                                        Recipients
                                        <span v-if="routeForm.user_ids.length" class="ml-1 rounded-full bg-blue-100 px-1.5 py-0.5 text-blue-700">
                                            {{ routeForm.user_ids.length }}
                                        </span>
                                    </h4>
                                    <button type="button" class="text-left text-[11px] text-blue-600 hover:underline sm:text-xs" @click="toggleAllRouteCandidates">
                                        {{ allRouteCandidatesSelected ? 'Deselect all' : 'Select all' }}
                                    </button>
                                </div>

                                <div class="max-h-56 overflow-y-auto divide-y divide-gray-50 sm:max-h-none">
                                    <label
                                        v-for="user in routeCandidates"
                                        :key="user.id"
                                        class="flex cursor-pointer items-start gap-2 px-2 py-2 hover:bg-blue-50 transition-colors sm:gap-3 sm:px-4 sm:py-2.5"
                                        :class="routeForm.user_ids.includes(user.id) ? 'bg-blue-50' : ''"
                                    >
                                        <input
                                            v-model="routeForm.user_ids"
                                            type="checkbox"
                                            :value="user.id"
                                            class="mt-0.5 h-4 w-4 shrink-0 rounded border-gray-300 text-blue-600 focus:ring-blue-500"
                                        />
                                        <div class="min-w-0">
                                            <p class="truncate text-xs font-medium text-gray-900 leading-tight sm:text-sm">{{ user.name }}</p>
                                            <p class="truncate text-[11px] text-gray-500 leading-tight">{{ user.subtitle }}</p>
                                            <p v-if="user.division" class="truncate text-[11px] text-gray-400 leading-tight">{{ user.division }}</p>
                                        </div>
                                    </label>
                                    <p v-if="routeCandidates.length === 0" class="px-2 py-6 text-center text-xs text-gray-400 sm:px-4 sm:text-sm">
                                        No eligible recipients found.
                                    </p>
                                </div>
                                <p v-if="routeForm.errors.user_ids" class="px-2 py-2 text-xs text-red-600 sm:px-4">{{ routeForm.errors.user_ids }}</p>
                            </div>

                            <!-- RIGHT: Action -->
                            <div class="flex min-w-0 flex-col">
                                <div class="border-b border-gray-100 px-2 py-2 sm:px-4 sm:py-2.5">
                                    <h4 class="text-[11px] font-semibold uppercase tracking-wide text-gray-500 sm:text-xs">Action</h4>
                                </div>

                                <div class="max-h-56 overflow-y-auto divide-y divide-gray-50 sm:max-h-none">
                                    <label
                                        v-for="a in ROUTING_ACTIONS"
                                        :key="a.value"
                                        class="flex cursor-pointer items-center gap-2 px-2 py-2 hover:bg-blue-50 transition-colors sm:gap-3 sm:px-4"
                                        :class="routeForm.action === a.value ? 'bg-blue-50' : ''"
                                    >
                                        <input
                                            v-model="routeForm.action"
                                            type="radio"
                                            :value="a.value"
                                            class="h-4 w-4 shrink-0 border-gray-300 text-blue-600 focus:ring-blue-500"
                                        />
                                        <span class="text-xs text-gray-700 sm:text-sm">{{ a.label }}</span>
                                    </label>
                                </div>
                                <p v-if="routeForm.errors.action" class="px-2 py-2 text-xs text-red-600 sm:px-4">{{ routeForm.errors.action }}</p>

                                <!-- Others specify -->
                                <div v-if="routeForm.action === 'others'" class="border-t border-gray-100 px-2 py-3 sm:px-4">
                                    <input
                                        v-model="routeForm.action_other"
                                        type="text"
                                        class="block w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 focus:border-blue-500 focus:ring-blue-500"
                                        placeholder="Please specify…"
                                    />
                                    <p v-if="routeForm.errors.action_other" class="mt-1 text-xs text-red-600">{{ routeForm.errors.action_other }}</p>
                                </div>
                            </div>
                        </div>

                        <!-- Remarks -->
                        <div class="shrink-0 border-t border-gray-100 px-6 py-3">
                            <label for="route_remarks" class="mb-1.5 block text-sm font-medium text-gray-700">
                                Remarks <span class="font-normal text-gray-400">(optional)</span>
                            </label>
                            <textarea
                                id="route_remarks"
                                v-model="routeForm.remarks"
                                rows="2"
                                class="block w-full rounded-lg border border-gray-300 bg-gray-50 p-2.5 text-sm text-gray-900 focus:border-blue-500 focus:ring-blue-500"
                                placeholder="Leave empty if not necessary."
                            />
                            <p v-if="routeForm.errors.remarks" class="mt-1 text-sm text-red-600">{{ routeForm.errors.remarks }}</p>
                        </div>

                        <!-- Footer actions -->
                        <div class="flex shrink-0 items-center justify-end gap-3 border-t border-gray-100 bg-gray-50 px-6 py-3">
                            <button
                                type="button"
                                class="rounded-lg border border-gray-300 bg-white px-5 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-100"
                                @click="closeRouteModal"
                            >
                                Cancel
                            </button>
                            <button
                                type="submit"
                                :disabled="routeForm.processing || !routeForm.user_ids.length || !routeForm.action"
                                class="inline-flex items-center gap-2 rounded-lg bg-indigo-700 px-5 py-2.5 text-sm font-medium text-white hover:bg-indigo-800 focus:outline-none focus:ring-4 focus:ring-indigo-300 disabled:opacity-50"
                            >
                                <svg v-if="routeForm.processing" class="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"/>
                                </svg>
                                Route Now
                            </button>
                        </div>

                    </form>
                </div>
            </div>
        </Transition>
    </Teleport>
</template>

<style scoped>
.modal-enter-active,
.modal-leave-active {
    transition: opacity 0.2s ease;
}
.modal-enter-active .relative,
.modal-leave-active .relative {
    transition: transform 0.2s ease, opacity 0.2s ease;
}
.modal-enter-from,
.modal-leave-to {
    opacity: 0;
}
.modal-enter-from .relative {
    transform: translateY(1.5rem);
    opacity: 0;
}
</style>
