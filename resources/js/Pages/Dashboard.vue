<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    stats:        Object,
    pendingItems: Array,
    recentDocs:   Array,
});

const page      = usePage();
const userRoles = computed(() => page.props.auth.user?.roles ?? []);
const hasRole   = (...roles) => roles.some(r => userRoles.value.includes(r));

const STATUS_LABEL = {
    draft:                         'Draft',
    submitted:                     'Submitted',
    pending_unit_head_review:      'Pending Unit Head Review',
    pending_division_chief_review: 'Pending DC Review',
    pending_ard_review:            'Pending Recommending Approval',
    pending_rd_review:             'Pending RD Review',
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
    pending_ard_review:            'bg-indigo-100 text-indigo-800',
    pending_rd_review:             'bg-indigo-100 text-indigo-800',
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

const showBreakdown = computed(() => hasRole('admin', 'rd', 'ard', 'division_chief', 'unit_head'));

const OUTGOING_PIPELINE = [
    'draft',
    'submitted',
    'pending_unit_head_review',
    'pending_division_chief_review',
    'pending_ard_review',
    'pending_rd_review',
    'approved',
    'for_comment',
    'pending_signature',
    'signed',
    'released',
    'returned_for_revision',
    'rejected',
];

const INCOMING_PIPELINE = [
    'received',
    'pending_routing',
    'routed',
    'completed',
    'archived',
];

const outgoingMax = computed(() =>
    Math.max(1, ...OUTGOING_PIPELINE.map(s => props.stats?.outgoingByStatus?.[s] ?? 0))
);
const incomingMax = computed(() =>
    Math.max(1, ...INCOMING_PIPELINE.map(s => props.stats?.incomingByStatus?.[s] ?? 0))
);

const goToTab = (tab) => router.get(route('documents.index'), { tab }, { preserveState: false });
</script>

<template>
    <Head title="Dashboard" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">Dashboard</h2>
        </template>

        <div class="py-8">
            <div class="mx-auto max-w-7xl sm:px-6 lg:px-8 space-y-6">

                <!-- ── Stats Cards ──────────────────────────────────────────── -->
                <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">

                    <!-- Outgoing -->
                    <button
                        type="button"
                        class="group flex flex-col gap-1 rounded-xl border border-gray-200 bg-white p-5 shadow-sm text-left hover:shadow-md transition-shadow"
                        @click="goToTab('outgoing')"
                    >
                        <div class="flex items-center justify-between">
                            <span class="text-xs font-semibold uppercase tracking-wide text-gray-500">Outgoing</span>
                            <span class="flex h-9 w-9 items-center justify-center rounded-full bg-blue-50 text-blue-600">
                                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 10.5 12 3m0 0 7.5 7.5M12 3v18" />
                                </svg>
                            </span>
                        </div>
                        <p class="text-3xl font-bold text-gray-900">{{ stats.totalOutgoing }}</p>
                        <p class="text-xs text-blue-600 group-hover:underline">View outgoing →</p>
                    </button>

                    <!-- Incoming -->
                    <button
                        type="button"
                        class="group flex flex-col gap-1 rounded-xl border border-gray-200 bg-white p-5 shadow-sm text-left hover:shadow-md transition-shadow"
                        @click="goToTab('incoming')"
                    >
                        <div class="flex items-center justify-between">
                            <span class="text-xs font-semibold uppercase tracking-wide text-gray-500">Incoming</span>
                            <span class="flex h-9 w-9 items-center justify-center rounded-full bg-green-50 text-green-600">
                                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 13.5 12 21m0 0-7.5-7.5M12 21V3" />
                                </svg>
                            </span>
                        </div>
                        <p class="text-3xl font-bold text-gray-900">{{ stats.totalIncoming }}</p>
                        <p class="text-xs text-green-600 group-hover:underline">View incoming →</p>
                    </button>

                    <!-- For Action -->
                    <button
                        type="button"
                        class="group flex flex-col gap-1 rounded-xl border p-5 shadow-sm text-left hover:shadow-md transition-shadow"
                        :class="stats.forActionCount > 0
                            ? 'border-red-200 bg-red-50'
                            : 'border-gray-200 bg-white'"
                        @click="goToTab('for_action')"
                    >
                        <div class="flex items-center justify-between">
                            <span
                                class="text-xs font-semibold uppercase tracking-wide"
                                :class="stats.forActionCount > 0 ? 'text-red-600' : 'text-gray-500'"
                            >For Action</span>
                            <span
                                class="flex h-9 w-9 items-center justify-center rounded-full"
                                :class="stats.forActionCount > 0 ? 'bg-red-100 text-red-600' : 'bg-gray-100 text-gray-500'"
                            >
                                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M14.857 17.082a23.848 23.848 0 0 0 5.454-1.31A8.967 8.967 0 0 1 18 9.75V9A6 6 0 0 0 6 9v.75a8.967 8.967 0 0 1-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 0 1-5.714 0m5.714 0a3 3 0 1 1-5.714 0" />
                                </svg>
                            </span>
                        </div>
                        <p
                            class="text-3xl font-bold"
                            :class="stats.forActionCount > 0 ? 'text-red-700' : 'text-gray-900'"
                        >{{ stats.forActionCount }}</p>
                        <p
                            class="text-xs"
                            :class="stats.forActionCount > 0 ? 'text-red-500 group-hover:underline' : 'text-gray-400'"
                        >{{ stats.forActionCount > 0 ? 'Items need action →' : 'Nothing pending' }}</p>
                    </button>

                    <!-- Completed This Month -->
                    <div class="flex flex-col gap-1 rounded-xl border border-gray-200 bg-white p-5 shadow-sm">
                        <div class="flex items-center justify-between">
                            <span class="text-xs font-semibold uppercase tracking-wide text-gray-500">Done This Month</span>
                            <span class="flex h-9 w-9 items-center justify-center rounded-full bg-teal-50 text-teal-600">
                                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                                </svg>
                            </span>
                        </div>
                        <p class="text-3xl font-bold text-gray-900">{{ stats.completedThisMonth }}</p>
                        <p class="text-xs text-gray-400">Completed documents</p>
                    </div>

                </div>

                <!-- ── Needs Your Attention ──────────────────────────────────── -->
                <div
                    v-if="pendingItems.length > 0"
                    class="overflow-hidden rounded-xl border border-amber-200 bg-white shadow-sm"
                >
                    <div class="flex items-center gap-2 border-b border-amber-200 bg-amber-50 px-5 py-3">
                        <svg class="h-5 w-5 shrink-0 text-amber-500" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z" />
                        </svg>
                        <h3 class="text-sm font-semibold text-amber-800">
                            Needs Your Attention
                            <span class="ml-1 inline-flex h-5 min-w-[1.25rem] items-center justify-center rounded-full bg-amber-500 px-1.5 text-xs font-bold text-white">
                                {{ stats.forActionCount > 99 ? '99+' : stats.forActionCount }}
                            </span>
                        </h3>
                    </div>

                    <ul class="divide-y divide-gray-100">
                        <li
                            v-for="doc in pendingItems"
                            :key="doc.id"
                            class="flex items-center justify-between gap-4 px-5 py-3 hover:bg-amber-50 transition-colors"
                        >
                            <div class="min-w-0 flex-1">
                                <p class="flex items-center gap-1.5 text-sm font-medium text-gray-900">
                                    <span
                                        v-if="doc.has_rush_routing"
                                        class="inline-flex shrink-0 items-center rounded-full bg-red-600 px-2 py-0.5 text-[10px] font-bold tracking-wide text-white"
                                    >
                                        RUSH
                                    </span>
                                    <span class="truncate">{{ doc.title }}</span>
                                </p>
                                <p class="text-xs text-gray-400">{{ doc.division?.name ?? '—' }}</p>
                            </div>
                            <div class="flex shrink-0 items-center gap-3">
                                <span
                                    class="inline-flex items-center whitespace-nowrap rounded-full px-2.5 py-0.5 text-xs font-medium"
                                    :class="STATUS_CLASS[doc.status] ?? 'bg-gray-100 text-gray-700'"
                                >
                                    {{ STATUS_LABEL[doc.status] ?? doc.status }}
                                </span>
                                <Link
                                    :href="route('documents.show', doc.id)"
                                    class="whitespace-nowrap text-xs font-medium text-amber-700 hover:underline"
                                >
                                    View →
                                </Link>
                            </div>
                        </li>
                    </ul>

                    <div v-if="stats.forActionCount > pendingItems.length" class="border-t border-amber-100 bg-amber-50 px-5 py-2 text-right">
                        <button
                            type="button"
                            class="text-xs font-medium text-amber-700 hover:underline"
                            @click="goToTab('for_action')"
                        >
                            View all {{ stats.forActionCount }} items →
                        </button>
                    </div>
                </div>

                <!-- ── Recent Documents ─────────────────────────────────────── -->
                <div class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm">
                    <div class="flex items-center justify-between border-b border-gray-200 px-5 py-3">
                        <h3 class="text-sm font-semibold text-gray-800">Recent Documents</h3>
                        <Link :href="route('documents.index')" class="text-xs font-medium text-blue-600 hover:underline">
                            View all →
                        </Link>
                    </div>

                    <div v-if="recentDocs.length === 0" class="px-5 py-10 text-center text-sm text-gray-400">
                        No documents yet.
                    </div>

                    <table v-else class="hidden w-full text-left text-sm text-gray-600 md:table">
                        <tbody class="divide-y divide-gray-100">
                            <tr
                                v-for="doc in recentDocs"
                                :key="doc.id"
                                class="cursor-pointer transition-colors hover:bg-blue-50"
                                @click="router.visit(route('documents.show', doc.id))"
                            >
                                <td class="max-w-xs px-5 py-3 font-medium text-gray-900">
                                    <div class="flex items-center gap-1.5">
                                        <span
                                            v-if="doc.has_rush_routing"
                                            class="inline-flex shrink-0 items-center rounded-full bg-red-600 px-2 py-0.5 text-[10px] font-bold tracking-wide text-white"
                                        >
                                            RUSH
                                        </span>
                                        <span class="truncate">{{ doc.title }}</span>
                                    </div>
                                </td>
                                <td class="px-5 py-3">
                                    <span
                                        class="inline-flex items-center whitespace-nowrap rounded-full px-2.5 py-0.5 text-xs font-medium capitalize"
                                        :class="doc.type === 'outgoing' ? 'bg-blue-100 text-blue-800' : 'bg-green-100 text-green-800'"
                                    >{{ doc.type }}</span>
                                </td>
                                <td class="px-5 py-3">
                                    <span
                                        class="inline-flex items-center whitespace-nowrap rounded-full px-2.5 py-0.5 text-xs font-medium"
                                        :class="STATUS_CLASS[doc.status] ?? 'bg-gray-100 text-gray-700'"
                                    >{{ STATUS_LABEL[doc.status] ?? doc.status }}</span>
                                </td>
                                <td class="whitespace-nowrap px-5 py-3 text-xs text-gray-400">{{ formatDate(doc.created_at) }}</td>
                                <td class="px-5 py-3">
                                    <Link
                                        :href="route('documents.show', doc.id)"
                                        class="font-medium text-blue-600 hover:underline"
                                        @click.stop
                                    >View</Link>
                                </td>
                            </tr>
                        </tbody>
                    </table>

                    <!-- Cards (below md) -->
                    <ul v-if="recentDocs.length > 0" class="divide-y divide-gray-100 md:hidden">
                        <li
                            v-for="doc in recentDocs"
                            :key="doc.id"
                            class="cursor-pointer px-5 py-3 transition-colors hover:bg-blue-50"
                            @click="router.visit(route('documents.show', doc.id))"
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
                                    :class="STATUS_CLASS[doc.status] ?? 'bg-gray-100 text-gray-700'"
                                >{{ STATUS_LABEL[doc.status] ?? doc.status }}</span>
                            </div>
                            <div class="mt-1.5 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-gray-500">
                                <span
                                    class="inline-flex items-center whitespace-nowrap rounded-full px-2 py-0.5 font-medium capitalize"
                                    :class="doc.type === 'outgoing' ? 'bg-blue-100 text-blue-800' : 'bg-green-100 text-green-800'"
                                >{{ doc.type }}</span>
                                <span>{{ formatDate(doc.created_at) }}</span>
                            </div>
                        </li>
                    </ul>
                </div>

                <!-- ── Status Breakdown Pipeline ────────────────────────────── -->
                <div v-if="showBreakdown" class="grid grid-cols-1 gap-4 lg:grid-cols-2">

                    <!-- Outgoing Pipeline -->
                    <div class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm">
                        <div class="border-b border-gray-200 bg-blue-50 px-5 py-3">
                            <h3 class="text-sm font-semibold text-blue-900">Outgoing Pipeline</h3>
                        </div>
                        <ul class="divide-y divide-gray-100 px-5 py-1">
                            <li
                                v-for="s in OUTGOING_PIPELINE"
                                :key="s"
                                class="flex items-center gap-3 py-2"
                            >
                                <span class="w-44 shrink-0 text-xs text-gray-600">{{ STATUS_LABEL[s] }}</span>
                                <div class="flex-1 overflow-hidden rounded-full bg-gray-100 h-2">
                                    <div
                                        class="h-2 rounded-full bg-blue-400 transition-all"
                                        :style="`width: ${Math.round(((stats.outgoingByStatus?.[s] ?? 0) / outgoingMax) * 100)}%`"
                                    />
                                </div>
                                <span class="w-8 shrink-0 text-right text-xs font-semibold text-gray-700">
                                    {{ stats.outgoingByStatus?.[s] ?? 0 }}
                                </span>
                            </li>
                        </ul>
                    </div>

                    <!-- Incoming Pipeline -->
                    <div class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm">
                        <div class="border-b border-gray-200 bg-green-50 px-5 py-3">
                            <h3 class="text-sm font-semibold text-green-900">Incoming Pipeline</h3>
                        </div>
                        <ul class="divide-y divide-gray-100 px-5 py-1">
                            <li
                                v-for="s in INCOMING_PIPELINE"
                                :key="s"
                                class="flex items-center gap-3 py-2"
                            >
                                <span class="w-44 shrink-0 text-xs text-gray-600">{{ STATUS_LABEL[s] }}</span>
                                <div class="flex-1 overflow-hidden rounded-full bg-gray-100 h-2">
                                    <div
                                        class="h-2 rounded-full bg-green-400 transition-all"
                                        :style="`width: ${Math.round(((stats.incomingByStatus?.[s] ?? 0) / incomingMax) * 100)}%`"
                                    />
                                </div>
                                <span class="w-8 shrink-0 text-right text-xs font-semibold text-gray-700">
                                    {{ stats.incomingByStatus?.[s] ?? 0 }}
                                </span>
                            </li>
                        </ul>
                    </div>

                </div>

            </div>
        </div>
    </AuthenticatedLayout>
</template>
