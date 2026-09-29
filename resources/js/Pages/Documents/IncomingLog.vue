<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, router } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps({
    documents: Array,
    date:      String,
});

const selectedDate = ref(props.date);

const goToDate = () => {
    if (!selectedDate.value) return;
    router.get(route('documents.incoming-log'), { date: selectedDate.value }, {
        preserveState: true,
        preserveScroll: true,
    });
};

const printLog = () => window.print();

const STATUS_LABEL = {
    received: 'Received', pending_routing: 'Pending Routing',
    routed: 'Routed', completed: 'Completed', archived: 'Archived',
};

const formatDisplayDate = (d) => d
    ? new Date(`${d}T00:00:00`).toLocaleDateString('en-PH', { year: 'numeric', month: 'long', day: 'numeric' })
    : '—';

const formatTime = (d) => d
    ? new Date(d).toLocaleTimeString('en-PH', { hour: '2-digit', minute: '2-digit' })
    : '—';
</script>

<template>
    <Head title="Incoming Documents Log" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <h2 class="text-xl font-semibold leading-tight text-gray-800">Incoming Documents Log</h2>
            </div>
        </template>

        <div class="py-8">
            <div class="mx-auto max-w-7xl sm:px-6 lg:px-8 space-y-4">

                <!-- ── Controls (hidden when printing) ──────────────────────── -->
                <div class="print:hidden overflow-hidden rounded-lg bg-white shadow-sm px-6 py-4 flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                    <div class="flex items-end gap-3">
                        <div>
                            <label for="log_date" class="mb-1 block text-sm font-medium text-gray-700">Date</label>
                            <input
                                id="log_date"
                                v-model="selectedDate"
                                type="date"
                                class="rounded-lg border border-gray-300 bg-gray-50 p-2 text-sm text-gray-900 focus:border-blue-500 focus:ring-blue-500"
                                @change="goToDate"
                            />
                        </div>
                    </div>
                    <button
                        type="button"
                        :disabled="documents.length === 0"
                        class="inline-flex items-center gap-2 rounded-lg bg-blue-700 px-4 py-2.5 text-sm font-medium text-white hover:bg-blue-800 disabled:opacity-50 disabled:cursor-not-allowed"
                        @click="printLog"
                    >
                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a1 1 0 001-1v-4a1 1 0 00-1-1H9a1 1 0 00-1 1v4a1 1 0 001 1zm0-14V5a1 1 0 011-1h4a1 1 0 011 1v2H9z"/>
                        </svg>
                        Print
                    </button>
                </div>

                <!-- ── Printable log ─────────────────────────────────────────── -->
                <div class="overflow-hidden rounded-lg bg-white shadow-sm print:shadow-none print:rounded-none">
                    <div class="px-6 py-4 border-b border-gray-100 print:border-black">
                        <h3 class="text-base font-semibold text-gray-800">Incoming Documents — {{ formatDisplayDate(date) }}</h3>
                        <p class="text-sm text-gray-500 print:hidden">{{ documents.length }} document(s) uploaded on this date.</p>
                    </div>

                    <div v-if="documents.length === 0" class="px-6 py-10 text-center text-sm text-gray-400">
                        No incoming documents were uploaded on this date.
                    </div>

                    <div v-else class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-100 text-sm print:text-xs">
                            <thead class="bg-gray-50 print:bg-transparent">
                                <tr>
                                    <th class="px-4 py-2.5 text-left font-semibold text-gray-600 print:border print:border-black">#</th>
                                    <th class="px-4 py-2.5 text-left font-semibold text-gray-600 print:border print:border-black">Control No.</th>
                                    <th class="px-4 py-2.5 text-left font-semibold text-gray-600 print:border print:border-black">Title</th>
                                    <th class="px-4 py-2.5 text-left font-semibold text-gray-600 print:border print:border-black">Source / Origin</th>
                                    <th class="px-4 py-2.5 text-left font-semibold text-gray-600 print:border print:border-black">Reference No.</th>
                                    <th class="px-4 py-2.5 text-left font-semibold text-gray-600 print:border print:border-black">Uploaded By</th>
                                    <th class="px-4 py-2.5 text-left font-semibold text-gray-600 print:border print:border-black">Division</th>
                                    <th class="px-4 py-2.5 text-left font-semibold text-gray-600 print:border print:border-black">Time</th>
                                    <th class="px-4 py-2.5 text-left font-semibold text-gray-600 print:border print:border-black">Status</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                <tr v-for="(doc, i) in documents" :key="doc.id">
                                    <td class="px-4 py-2.5 text-gray-500 print:border print:border-black">{{ i + 1 }}</td>
                                    <td class="px-4 py-2.5 font-mono text-gray-700 print:border print:border-black">{{ doc.control_number ?? '—' }}</td>
                                    <td class="px-4 py-2.5 text-gray-900 print:border print:border-black">{{ doc.title }}</td>
                                    <td class="px-4 py-2.5 text-gray-700 print:border print:border-black">{{ doc.source_origin ?? '—' }}</td>
                                    <td class="px-4 py-2.5 text-gray-700 print:border print:border-black">{{ doc.reference_number ?? '—' }}</td>
                                    <td class="px-4 py-2.5 text-gray-700 print:border print:border-black">{{ doc.uploader?.name ?? '—' }}</td>
                                    <td class="px-4 py-2.5 text-gray-700 print:border print:border-black">{{ doc.division?.name ?? '—' }}</td>
                                    <td class="px-4 py-2.5 text-gray-500 print:border print:border-black">{{ formatTime(doc.created_at) }}</td>
                                    <td class="px-4 py-2.5 text-gray-700 capitalize print:border print:border-black">{{ STATUS_LABEL[doc.status] ?? doc.status }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

            </div>
        </div>
    </AuthenticatedLayout>
</template>

<style>
@media print {
    @page {
        size: landscape;
        margin: 1.5cm;
    }
}
</style>
