<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, ref, shallowRef, watch, nextTick, onMounted, onBeforeUnmount } from 'vue';

const props = defineProps({
    documents:          Object,
    tab:                String,
    search:             String,
    mine:               Boolean,
    forActionCount:     Number,
    canCreateOutgoing:  Boolean,
    canCreateIncoming:  Boolean,
    isRecordsSection:   Boolean,
    isSds:               Boolean,
    forApprovalCount:   Number,
    forRoutingCount:    Number,
});

const STATUS_LABEL = {
    draft:                         'Draft',
    submitted:                     'Submitted',
    pending_unit_head_review:      'Pending Unit Head Review',
    pending_division_chief_review: 'Pending DC Review',
    pending_asds_review:            'Pending Recommending Approval',
    pending_sds_review:             'Pending SDS Review',
    approved:                      'Approved',
    for_comment:                   'For Comment',
    returned_for_revision:         'Returned',
    rejected:                      'Rejected',
    pending_signature:             'Pending Signature',
    signed:                        'Signed',
    released:                      'Released',
    received:                      'Received',
    pending_routing:               'Pending Routing',
    routed:                        'Routed',
    completed:                     'Completed',
    archived:                      'Archived',
};

const STATUS_CLASS = {
    draft:                         'bg-gray-100 text-gray-700',
    submitted:                     'bg-yellow-100 text-yellow-800',
    pending_unit_head_review:      'bg-orange-100 text-orange-800',
    pending_division_chief_review: 'bg-yellow-100 text-yellow-800',
    pending_asds_review:            'bg-indigo-100 text-indigo-800',
    pending_sds_review:             'bg-indigo-100 text-indigo-800',
    approved:                      'bg-green-100 text-green-800',
    for_comment:                   'bg-amber-100 text-amber-800',
    returned_for_revision:         'bg-red-100 text-red-800',
    rejected:                      'bg-red-100 text-red-800',
    pending_signature:             'bg-purple-100 text-purple-800',
    signed:                        'bg-green-100 text-green-800',
    released:                      'bg-emerald-200 text-emerald-900',
    received:                      'bg-blue-100 text-blue-800',
    pending_routing:               'bg-yellow-100 text-yellow-800',
    routed:                        'bg-indigo-100 text-indigo-800',
    completed:                     'bg-green-100 text-green-800',
    archived:                      'bg-gray-100 text-gray-700',
};

const formatDate = d => d
    ? new Date(d).toLocaleDateString('en-PH', { year: 'numeric', month: 'short', day: 'numeric' })
    : '—';

// A released document Records Section has already confirmed emailing out
// reads as "Emailed" rather than "Released" — same underlying status (this
// isn't a separate DocumentStatus, just emailed_at/emailed_by on top of it,
// see DocumentReleaseStampController::markEmailed()), but the badge should
// still tell the two apart at a glance in the list.
const statusLabel = doc => (doc.status === 'released' && doc.emailed_at)
    ? 'Emailed'
    : (STATUS_LABEL[doc.status] ?? doc.status);
const statusClass = doc => (doc.status === 'released' && doc.emailed_at)
    ? 'bg-sky-100 text-sky-800'
    : (STATUS_CLASS[doc.status] ?? 'bg-gray-100 text-gray-700');

const search = ref(props.search ?? '');
// Only meaningful on the Outgoing tab (see DocumentController::index()) — a
// tab switch away from Outgoing deliberately drops it rather than carrying
// it along silently to a tab where it has no checkbox and no effect.
const mine = ref(props.mine ?? false);

const goTab = (t) => router.get(
    route('documents.index'),
    { tab: t, search: search.value || undefined, mine: (t === 'outgoing' && mine.value) || undefined },
    { preserveState: false },
);

let searchDebounce = null;
watch(search, (value) => {
    clearTimeout(searchDebounce);
    searchDebounce = setTimeout(() => {
        router.get(
            route('documents.index'),
            { tab: props.tab, search: value || undefined, mine: mine.value || undefined },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    }, 350);
});

const toggleMine = () => {
    mine.value = !mine.value;
    router.get(
        route('documents.index'),
        { tab: props.tab, search: search.value || undefined, mine: mine.value || undefined },
        { preserveState: true, preserveScroll: true, replace: true },
    );
};

const clearSearch = () => { search.value = ''; };

const TABS = computed(() => {
    const tabs = [];

    // The SDS's queue is split into two focused lists instead of one mixed
    // "For Action" tab — everyone else keeps the combined view. For the SDS,
    // her action queues lead (left) and the document lists follow (right),
    // since that's what she reaches for first.
    if (props.isSds) {
        tabs.push(
            { key: 'for_approval', label: 'For Approval', count: props.forApprovalCount },
            { key: 'for_routing',  label: 'For Routing',  count: props.forRoutingCount },
            { key: 'outgoing', label: 'Outgoing' },
            { key: 'incoming', label: 'Incoming' },
        );
    } else {
        tabs.push(
            { key: 'outgoing', label: 'Outgoing' },
            { key: 'incoming', label: 'Incoming' },
            { key: 'for_action', label: 'For Action', count: props.forActionCount },
        );
    }

    if (props.isRecordsSection) {
        tabs.push(
            { key: 'records_incoming', label: 'Receiving' },
            { key: 'records_handled',  label: 'Acknowledged' },
            { key: 'ready_to_release', label: 'Ready for Release' },
            { key: 'released',        label: 'Released' },
        );
    }

    return tabs;
});

const EMPTY_MESSAGES = {
    outgoing:          'No outgoing documents found.',
    incoming:          'No incoming documents found.',
    for_action:        'Nothing requires your action right now.',
    for_approval:      'Nothing awaiting your approval right now.',
    for_routing:       'Nothing awaiting routing right now.',
    records_incoming:  'No documents auto-forwarded by the secretary yet.',
    records_handled:   'No acknowledged documents from the secretary yet.',
    ready_to_release:  'No signed documents waiting to be released.',
    released:          'No documents have been released yet.',
};

const emptyMessage = computed(() => props.search
    ? `No documents match "${props.search}".`
    : EMPTY_MESSAGES[props.tab]);

const showTypeCol = computed(() => props.tab === 'for_action');

// "Needs action" visual treatment (orange highlight) applies to the combined
// For Action tab and to both of its SDS-only split-out tabs.
const ACTION_TABS = ['for_action', 'for_approval', 'for_routing'];
const isActionTab = computed(() => ACTION_TABS.includes(props.tab));

// ── Mobile quick preview ─────────────────────────────────────────────────────
// On small screens, tapping a card opens this preview modal instead of
// navigating to the full document page (the desktop table still links
// straight to the full page) — a lighter-weight way to check a document
// without leaving the list.
const showPreviewModal = ref(false);
const previewDoc        = ref(null);

const previewFileExt = computed(() => {
    const path = previewDoc.value?.file_path ?? '';
    return (path.split('.').pop() ?? '').toLowerCase();
});
const previewIsImage = computed(() => ['png', 'jpg', 'jpeg'].includes(previewFileExt.value));
const previewIsPdf   = computed(() => previewFileExt.value === 'pdf');

const openPreview = (doc) => {
    previewDoc.value = doc;
    showPreviewModal.value = true;
    reviewForm.reset();
    reviewForm.clearErrors();
};
const closePreview = () => { showPreviewModal.value = false; };

const onPreviewKeyDown = (e) => {
    if (e.key === 'Escape') {
        if (showRouteModal.value) closeRouteModal();
        else if (showPreviewModal.value) closePreview();
    }
};
onMounted(()       => window.addEventListener('keydown', onPreviewKeyDown));
onBeforeUnmount(() => window.removeEventListener('keydown', onPreviewKeyDown));

// ── Review action (Submit Review, when previewDoc.can_review) ───────────────
const reviewForm = useForm({ action: '', remarks: '' });

// The SDS may submit remarks alone with no action selected — her own remarks
// already imply "send it back" regardless (see DocumentReviewController::
// store()'s remarks-win rule), so requiring an explicit pick would just be
// friction. Every other stage/role still must pick an action.
const canSubmitReview = computed(() =>
    reviewForm.action || (previewDoc.value?.status === 'pending_sds_review' && reviewForm.remarks.trim() !== ''));

// Same 'approved_forwarded' action, worded per the current stage — mirrors
// Documents/Show.vue's approveActionLabel.
const approveActionLabel = computed(() => {
    switch (previewDoc.value?.status) {
        case 'pending_sds_review':             return 'Approve';
        case 'pending_asds_review':            return 'Recommend for Approval';
        case 'submitted':
        case 'pending_division_chief_review': return 'Review & Forward';
        default:                              return 'Approve & Forward';
    }
});
// Safety net for fast mobile reviewing (tap card → paste/retype remarks →
// approve, repeated quickly for several documents in a row): if remarks
// look identical to what was just submitted on the PREVIOUS document, confirm
// before sending — easy to not notice a stale/reused remark when moving
// through a queue in a few seconds per document. Only ever warns; never
// blocks a genuinely repeated remark outright.
const lastSubmittedRemarks = ref(null);
const submitReview = () => {
    const trimmedRemarks = reviewForm.remarks.trim();

    if (trimmedRemarks && trimmedRemarks === lastSubmittedRemarks.value
        && ! confirm('These remarks look identical to what you just submitted on the previous document. Submit anyway?')) {
        return;
    }

    reviewForm.post(route('documents.reviews.store', previewDoc.value.id), {
        onSuccess: () => {
            lastSubmittedRemarks.value = trimmedRemarks || null;
            closePreview();
            reviewForm.reset();
            reviewForm.clearErrors();
        },
    });
};

// ── Route action (Route Document, when previewDoc.can_route) ────────────────
// Same nested-modal-on-top-of-preview pattern as Documents/Show.vue.
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

const showRouteModal         = ref(false);
const routeCandidates        = ref([]);
const routeCandidatesLoading = ref(false);
const routeCandidatesError   = ref(null);

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
        const { data } = await window.axios.get(route('documents.routings.candidates', previewDoc.value.id));
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
    routeForm.post(route('documents.routings.store', previewDoc.value.id), {
        onSuccess: () => {
            showRouteModal.value = false;
            routeForm.reset();
            routeForm.clearErrors();
            closePreview();
        },
    });
};

const allRouteCandidatesSelected = computed(() => routeForm.user_ids.length === routeCandidates.value.length && routeCandidates.value.length > 0);
const toggleAllRouteCandidates = () => {
    routeForm.user_ids = allRouteCandidatesSelected.value ? [] : routeCandidates.value.map(u => u.id);
};

// PDF preview rendering — same pdf.js-onto-canvas approach as Documents/Show.vue
// (see that file for why: consistent "fit to width" across mobile browsers,
// and pdf.js is loaded from public/ rather than bundled so it shares class
// definitions with its worker).
const pdfViewportRef  = ref(null);
const pdfCanvasRef    = ref(null);
const pdfCurrentPage  = ref(1);
const pdfTotalPages   = ref(0);
const pdfLoading      = ref(true);
const pdfPreviewError = ref(null);
const pdfDocPreview   = shallowRef(null);
const pdfRenderTask   = shallowRef(null);
let _pdfjsPreview = null;

const renderPdfPreviewPage = async (num) => {
    if (!pdfDocPreview.value || !pdfCanvasRef.value) return;
    if (pdfRenderTask.value) pdfRenderTask.value.cancel();

    const page = await pdfDocPreview.value.getPage(num);
    const containerW = (pdfViewportRef.value?.clientWidth || 800) - 16;
    const viewport = page.getViewport({ scale: 1 });
    const cssScale = containerW / viewport.width;

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
        const root = window.location.origin;
        _pdfjsPreview = await import(/* @vite-ignore */ root + '/pdf.min.mjs');
        _pdfjsPreview.GlobalWorkerOptions.workerSrc = root + '/pdf.worker.min.mjs';

        const loadTask = _pdfjsPreview.getDocument({
            url: route('documents.file', previewDoc.value.id),
            withCredentials: true,
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

watch(showPreviewModal, (open) => {
    if (open && previewIsPdf.value) {
        loadPdfPreview();
    } else if (!open) {
        unloadPdfPreview();
        previewDoc.value = null;
    }
});
</script>

<template>
    <Head title="Documents" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <h2 class="text-xl font-semibold leading-tight text-gray-800">Documents</h2>
                <div class="flex flex-wrap gap-2">
                    <Link
                        v-if="canCreateOutgoing"
                        :href="route('documents.outgoing.create')"
                        class="inline-flex items-center rounded-lg bg-blue-700 px-4 py-2 text-sm font-medium text-white hover:bg-blue-800 focus:outline-none focus:ring-4 focus:ring-blue-300"
                    >
                        + New Outgoing
                    </Link>
                    <Link
                        v-if="canCreateIncoming"
                        :href="route('documents.incoming.create')"
                        class="inline-flex items-center rounded-lg bg-green-700 px-4 py-2 text-sm font-medium text-white hover:bg-green-800 focus:outline-none focus:ring-4 focus:ring-green-300"
                    >
                        + Upload Incoming
                    </Link>
                    <Link
                        v-if="isRecordsSection"
                        :href="route('documents.incoming-log')"
                        class="inline-flex items-center rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-4 focus:ring-gray-200"
                    >
                        Incoming Log
                    </Link>
                    <Link
                        v-if="isRecordsSection"
                        :href="route('documents.released-log')"
                        class="inline-flex items-center rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-4 focus:ring-gray-200"
                    >
                        Released Log
                    </Link>
                </div>
            </div>
        </template>

        <div class="py-8">
            <div class="mx-auto max-w-7xl sm:px-6 lg:px-8 space-y-4">

                <!-- ── Tabs ──────────────────────────────────────────────────── -->
                <!-- Scrolls horizontally on narrow screens instead of squeezing every
                     tab evenly (flex-1) into the viewport — with enough tabs (e.g. the
                     SDS's Outgoing/Incoming/For Approval/For Routing), that used to clip
                     the trailing tabs' badge counts off-screen entirely. -->
                <div class="flex gap-1 overflow-x-auto rounded-xl bg-gray-100 p-1">
                    <button
                        v-for="t in TABS"
                        :key="t.key"
                        type="button"
                        class="relative flex shrink-0 items-center justify-center gap-2 whitespace-nowrap rounded-lg px-4 py-2 text-sm font-medium transition-all sm:flex-1"
                        :class="tab === t.key
                            ? 'bg-white text-gray-900 shadow-sm'
                            : 'text-gray-600 hover:text-gray-900'"
                        @click="goTab(t.key)"
                    >
                        {{ t.label }}
                        <span
                            v-if="t.count > 0"
                            class="inline-flex h-5 min-w-[1.25rem] shrink-0 items-center justify-center rounded-full bg-red-500 px-1.5 text-xs font-bold text-white"
                        >
                            {{ t.count > 99 ? '99+' : t.count }}
                        </span>
                    </button>
                </div>

                <!-- ── Search ────────────────────────────────────────────────── -->
                <div class="relative">
                    <svg class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-4.35-4.35M17 10a7 7 0 11-14 0 7 7 0 0114 0z"/>
                    </svg>
                    <input
                        v-model="search"
                        type="search"
                        placeholder="Search by title, control number, reference number, source, division, or uploader…"
                        class="w-full rounded-lg border border-gray-300 bg-white py-2 pl-9 pr-9 text-sm text-gray-900 placeholder:text-gray-400 focus:border-blue-500 focus:ring-blue-500"
                    />
                    <button
                        v-if="search"
                        type="button"
                        class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600"
                        aria-label="Clear search"
                        @click="clearSearch"
                    >
                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                        </svg>
                    </button>
                </div>

                <!-- ── My documents filter (Outgoing tab only) ─────────────────── -->
                <label v-if="tab === 'outgoing'" class="inline-flex w-fit cursor-pointer items-center gap-2 text-sm text-gray-700">
                    <input
                        type="checkbox"
                        :checked="mine"
                        class="rounded border-gray-300 text-blue-600 focus:ring-blue-500"
                        @change="toggleMine"
                    />
                    My documents only
                </label>

                <!-- ── Table (md and up) ─────────────────────────────────────── -->
                <div class="hidden overflow-x-auto rounded-lg border border-gray-200 bg-white shadow-sm md:block">
                    <table class="w-full text-left text-sm text-gray-600">
                        <thead class="bg-gray-50 text-xs font-semibold uppercase tracking-wide text-gray-500">
                            <tr>
                                <th class="px-6 py-3">Title</th>
                                <th v-if="showTypeCol" class="px-6 py-3">Type</th>
                                <th class="px-6 py-3">Status</th>
                                <th class="px-6 py-3">Division</th>
                                <th class="px-6 py-3">Uploaded by</th>
                                <th class="px-6 py-3">Date</th>
                                <th class="px-6 py-3"><span class="sr-only">View</span></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <tr v-if="documents.data.length === 0">
                                <td :colspan="showTypeCol ? 7 : 6" class="px-6 py-12 text-center text-gray-400">
                                    {{ emptyMessage }}
                                </td>
                            </tr>
                            <tr
                                v-for="doc in documents.data"
                                :key="doc.id"
                                class="cursor-pointer transition-colors"
                                :class="isActionTab
                                    ? 'hover:bg-orange-50 border-l-2 border-l-orange-400'
                                    : 'hover:bg-blue-50'"
                                @click="router.visit(route('documents.show', doc.id))"
                            >
                                <td class="max-w-xs px-6 py-4 font-medium text-gray-900">
                                    <div class="flex items-center gap-2">
                                        <span
                                            v-if="doc.has_rush_routing"
                                            class="inline-flex shrink-0 items-center rounded-full bg-red-600 px-2 py-0.5 text-[10px] font-bold tracking-wide text-white"
                                            title="Routed as RUSH"
                                        >
                                            RUSH
                                        </span>
                                        <span class="truncate">{{ doc.title }}</span>
                                    </div>
                                    <div v-if="doc.control_number" class="mt-0.5 truncate font-mono text-xs font-normal text-gray-400">
                                        {{ doc.control_number }}
                                    </div>
                                </td>
                                <td v-if="showTypeCol" class="px-6 py-4">
                                    <span
                                        class="inline-flex items-center whitespace-nowrap rounded-full px-2.5 py-0.5 text-xs font-medium capitalize"
                                        :class="doc.type === 'outgoing'
                                            ? 'bg-blue-100 text-blue-800'
                                            : 'bg-green-100 text-green-800'"
                                    >
                                        {{ doc.type }}
                                    </span>
                                </td>
                                <td class="px-6 py-4">
                                    <span
                                        class="inline-flex items-center whitespace-nowrap rounded-full px-2.5 py-0.5 text-xs font-medium"
                                        :class="statusClass(doc)"
                                    >
                                        {{ statusLabel(doc) }}
                                    </span>
                                </td>
                                <td class="px-6 py-4">{{ doc.division?.name ?? '—' }}</td>
                                <td class="px-6 py-4">{{ doc.uploader?.name ?? '—' }}</td>
                                <td class="px-6 py-4 whitespace-nowrap">{{ formatDate(doc.created_at) }}</td>
                                <td class="px-6 py-4">
                                    <Link
                                        :href="route('documents.show', doc.id)"
                                        class="font-medium text-blue-600 hover:underline"
                                        @click.stop
                                    >
                                        View
                                    </Link>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- ── Cards (below md) ──────────────────────────────────────── -->
                <div class="space-y-3 md:hidden">
                    <p v-if="documents.data.length === 0" class="rounded-lg border border-gray-200 bg-white px-4 py-12 text-center text-sm text-gray-400 shadow-sm">
                        {{ emptyMessage }}
                    </p>
                    <button
                        v-for="doc in documents.data"
                        :key="doc.id"
                        type="button"
                        class="block w-full rounded-lg border border-gray-200 bg-white p-4 text-left shadow-sm transition-colors"
                        :class="isActionTab ? 'border-l-4 border-l-orange-400' : ''"
                        @click="openPreview(doc)"
                    >
                        <div class="flex items-start justify-between gap-3">
                            <p class="flex min-w-0 items-center gap-1.5 font-medium text-gray-900">
                                <span
                                    v-if="doc.has_rush_routing"
                                    class="inline-flex shrink-0 items-center rounded-full bg-red-600 px-2 py-0.5 text-[10px] font-bold tracking-wide text-white"
                                >
                                    RUSH
                                </span>
                                <span class="truncate">{{ doc.title }}</span>
                            </p>
                            <span
                                class="inline-flex shrink-0 items-center whitespace-nowrap rounded-full px-2.5 py-0.5 text-xs font-medium"
                                :class="statusClass(doc)"
                            >
                                {{ statusLabel(doc) }}
                            </span>
                        </div>
                        <div class="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-gray-500">
                            <span
                                v-if="showTypeCol"
                                class="inline-flex items-center whitespace-nowrap rounded-full px-2 py-0.5 font-medium capitalize"
                                :class="doc.type === 'outgoing'
                                    ? 'bg-blue-100 text-blue-800'
                                    : 'bg-green-100 text-green-800'"
                            >
                                {{ doc.type }}
                            </span>
                            <span v-if="doc.control_number" class="font-mono">{{ doc.control_number }}</span>
                            <span>{{ doc.division?.name ?? '—' }}</span>
                            <span>{{ doc.uploader?.name ?? '—' }}</span>
                            <span>{{ formatDate(doc.created_at) }}</span>
                        </div>
                    </button>
                </div>

                <!-- ── Pagination ─────────────────────────────────────────────── -->
                <div v-if="documents.last_page > 1" class="flex flex-col gap-2 text-sm text-gray-500 sm:flex-row sm:items-center sm:justify-between">
                    <span>
                        Showing {{ documents.from }}–{{ documents.to }} of {{ documents.total }}
                    </span>
                    <div class="flex flex-wrap gap-1">
                        <template v-for="link in documents.links" :key="link.label">
                            <Link
                                v-if="link.url"
                                :href="link.url"
                                class="min-w-[2rem] rounded border px-3 py-1.5 text-center"
                                :class="link.active
                                    ? 'border-blue-600 bg-blue-600 text-white'
                                    : 'border-gray-300 bg-white text-gray-700 hover:bg-gray-50'"
                                v-html="link.label"
                            />
                            <span
                                v-else
                                class="min-w-[2rem] rounded border border-gray-200 bg-gray-50 px-3 py-1.5 text-center text-gray-400"
                                v-html="link.label"
                            />
                        </template>
                    </div>
                </div>

                <!-- Record count when only one page -->
                <p v-else-if="documents.total > 0" class="text-right text-xs text-gray-400">
                    {{ documents.total }} {{ documents.total === 1 ? 'record' : 'records' }}
                </p>

            </div>
        </div>
    </AuthenticatedLayout>

    <!-- ── Mobile Quick Preview Modal ───────────────────────────────────────── -->
    <Teleport to="body">
        <Transition name="modal">
            <div
                v-if="showPreviewModal"
                class="fixed inset-0 z-50 flex items-end justify-center sm:items-center"
                role="dialog"
                aria-modal="true"
                :aria-label="`Preview: ${previewDoc?.title}`"
            >
                <!-- Backdrop -->
                <div class="absolute inset-0 bg-black/60 backdrop-blur-sm" @click="closePreview" />

                <!-- Modal panel -->
                <div class="relative z-10 flex h-[95dvh] w-full flex-col bg-white shadow-2xl sm:h-[85vh] sm:max-w-lg sm:rounded-2xl">

                    <!-- Header -->
                    <div class="flex items-center justify-between gap-4 border-b border-gray-200 px-4 py-3 shrink-0">
                        <div class="min-w-0">
                            <p class="text-xs text-gray-500 uppercase tracking-wide font-medium">Document Preview</p>
                            <h3 class="text-sm font-semibold text-gray-900 truncate leading-tight">{{ previewDoc?.title }}</h3>
                        </div>
                        <div class="flex shrink-0 items-center gap-1.5">
                            <Link
                                :href="route('documents.show', previewDoc.id)"
                                class="inline-flex items-center gap-1.5 rounded-lg border border-gray-300 px-3 py-1.5 text-xs font-medium text-gray-600 hover:bg-gray-50"
                            >
                                Full details
                            </Link>
                            <button
                                type="button"
                                class="flex h-9 w-9 items-center justify-center rounded-full text-gray-500 hover:bg-gray-100 hover:text-gray-700 focus:outline-none"
                                @click="closePreview"
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
                    <div v-if="previewDoc?.can_route || previewDoc?.can_review" class="shrink-0 border-b border-gray-200 bg-white p-3">
                        <!-- Route -->
                        <div v-if="previewDoc.can_route" class="flex flex-col items-start gap-2">
                            <p class="text-sm text-gray-600">This document is ready to be routed to recipients.</p>
                            <button
                                type="button"
                                class="inline-flex w-full shrink-0 items-center justify-center gap-2 rounded-lg bg-indigo-700 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-800"
                                @click="openRouteModal"
                            >
                                Route Document →
                            </button>
                        </div>

                        <!-- Review -->
                        <form v-if="previewDoc.can_review" @submit.prevent="submitReview" class="space-y-2">
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
                            <p v-if="reviewForm.errors.action" class="text-xs text-red-600">{{ reviewForm.errors.action }}</p>

                            <textarea
                                v-model="reviewForm.remarks"
                                rows="2"
                                placeholder="Remarks (required for return/reject)…"
                                class="block w-full rounded-lg border border-gray-300 bg-gray-50 p-2 text-sm text-gray-900 focus:border-blue-500 focus:ring-blue-500"
                            />
                            <p v-if="reviewForm.errors.remarks" class="text-xs text-red-600">{{ reviewForm.errors.remarks }}</p>
                            <p v-if="previewDoc.status === 'pending_sds_review'" class="text-xs text-gray-500">
                                Note: if you enter remarks here, this document will be returned for revision instead of approved — you don't need to pick an action, remarks alone are enough.
                            </p>

                            <button
                                type="submit"
                                :disabled="reviewForm.processing || !canSubmitReview"
                                class="inline-flex w-full shrink-0 items-center justify-center gap-2 rounded-lg bg-blue-700 px-5 py-2 text-sm font-medium text-white hover:bg-blue-800 disabled:opacity-60"
                            >
                                Submit Review
                            </button>
                        </form>
                    </div>

                    <!-- Content area -->
                    <div class="flex-1 overflow-auto bg-gray-100">

                        <!-- Images -->
                        <div v-if="previewIsImage" class="flex min-h-full items-start justify-center p-2 sm:p-4">
                            <img
                                :src="route('documents.file', previewDoc.id)"
                                :alt="previewDoc.title"
                                class="max-w-full h-auto"
                            />
                        </div>

                        <!-- PDF -->
                        <div v-else-if="previewIsPdf" ref="pdfViewportRef" class="flex h-full flex-col">
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

                        <!-- Non-previewable -->
                        <div v-else class="flex h-full flex-col items-center justify-center gap-5 p-8 text-center">
                            <div class="flex h-16 w-16 items-center justify-center rounded-full bg-gray-200">
                                <svg class="h-8 w-8 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                                </svg>
                            </div>
                            <div>
                                <p class="text-sm font-medium text-gray-800">
                                    This file type cannot be previewed here.
                                </p>
                                <p class="mt-1 text-xs text-gray-500">
                                    Open the full document page to download it.
                                </p>
                            </div>
                            <Link
                                :href="route('documents.show', previewDoc.id)"
                                class="inline-flex items-center gap-2 rounded-lg bg-blue-700 px-5 py-2.5 text-sm font-medium text-white hover:bg-blue-800"
                            >
                                Full details →
                            </Link>
                        </div>

                    </div>
                </div>
            </div>
        </Transition>
    </Teleport>

    <!-- ── Route Modal (stacked on top of the Mobile Quick Preview modal) ─────── -->
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
                <div class="absolute inset-0 bg-black/60 backdrop-blur-sm" @click="closeRouteModal" />

                <!-- Modal panel -->
                <div class="relative z-10 flex max-h-[92dvh] w-full flex-col bg-white shadow-2xl transition-all duration-200 sm:max-h-[85vh] sm:w-[95vw] sm:max-w-3xl sm:rounded-2xl">

                    <!-- Header -->
                    <div class="flex items-center justify-between gap-4 border-b border-gray-200 px-4 py-3 shrink-0">
                        <div class="min-w-0">
                            <p class="text-xs text-gray-500 uppercase tracking-wide font-medium">Routing Action Slip</p>
                            <h3 class="text-sm font-semibold text-gray-900 truncate leading-tight">{{ previewDoc?.title }}</h3>
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
                            <label for="mobile_route_remarks" class="mb-1.5 block text-sm font-medium text-gray-700">
                                Remarks <span class="font-normal text-gray-400">(optional)</span>
                            </label>
                            <textarea
                                id="mobile_route_remarks"
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
