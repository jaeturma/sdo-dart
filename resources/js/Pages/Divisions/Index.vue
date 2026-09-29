<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref, watch } from 'vue';

const props = defineProps({
    divisions: Array,
    search:    String,
});

const deleteDivision = (id, name, canDelete) => {
    if (! canDelete) return;
    if (! confirm(`Delete office "${name}"? This cannot be undone.`)) return;
    router.delete(route('divisions.destroy', id));
};

const search = ref(props.search ?? '');

let searchDebounce = null;
watch(search, (value) => {
    clearTimeout(searchDebounce);
    searchDebounce = setTimeout(() => {
        router.get(
            route('divisions.index'),
            { search: value || undefined },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    }, 350);
});

const clearSearch = () => { search.value = ''; };
</script>

<template>
    <Head title="Offices" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <h2 class="text-xl font-semibold leading-tight text-gray-800">Offices</h2>
                <Link
                    :href="route('divisions.create')"
                    class="inline-flex items-center rounded-lg bg-blue-700 px-4 py-2 text-sm font-medium text-white hover:bg-blue-800 focus:outline-none focus:ring-4 focus:ring-blue-300"
                >
                    + New Office
                </Link>
            </div>
        </template>

        <div class="py-8">
            <div class="mx-auto max-w-6xl sm:px-6 lg:px-8 space-y-4">

                <!-- ── Search ────────────────────────────────────────────────── -->
                <div class="relative">
                    <svg class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-4.35-4.35M17 10a7 7 0 11-14 0 7 7 0 0114 0z"/>
                    </svg>
                    <input
                        v-model="search"
                        type="search"
                        placeholder="Search by name or code…"
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

                <div class="overflow-x-auto rounded-lg border border-gray-200 bg-white shadow-sm">
                    <table class="w-full text-left text-sm text-gray-600">
                        <thead class="bg-gray-50 text-xs font-semibold uppercase tracking-wide text-gray-500">
                            <tr>
                                <th class="px-6 py-3">Name</th>
                                <th class="px-6 py-3">Code</th>
                                <th class="px-6 py-3">Parent Office</th>
                                <th class="px-6 py-3">Direct SDS Routing</th>
                                <th class="px-6 py-3">Users</th>
                                <th class="px-6 py-3">Documents</th>
                                <th class="px-6 py-3"><span class="sr-only">Actions</span></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <tr v-if="divisions.length === 0">
                                <td colspan="7" class="px-6 py-12 text-center text-gray-400">
                                    {{ search ? `No offices match "${search}".` : 'No offices found.' }}
                                </td>
                            </tr>
                            <tr v-for="d in divisions" :key="d.id" class="hover:bg-gray-50">
                                <td class="px-6 py-4 font-medium text-gray-900">{{ d.name }}</td>
                                <td class="px-6 py-4">
                                    <span v-if="d.code" class="inline-flex items-center rounded-full bg-blue-100 px-2.5 py-0.5 text-xs font-medium text-blue-800">{{ d.code }}</span>
                                    <span v-else class="text-gray-400">—</span>
                                </td>
                                <td class="px-6 py-4">
                                    <span v-if="d.parent" class="text-gray-900">{{ d.parent }}</span>
                                    <span v-else class="text-gray-400">— (top-level)</span>
                                </td>
                                <td class="px-6 py-4">
                                    <span v-if="d.allowRdDirectRouting" class="inline-flex items-center rounded-full bg-emerald-100 px-2.5 py-0.5 text-xs font-medium text-emerald-800">Enabled</span>
                                    <span v-else class="text-gray-400">—</span>
                                </td>
                                <td class="px-6 py-4">{{ d.usersCount }}</td>
                                <td class="px-6 py-4">{{ d.documentsCount }}</td>
                                <td class="px-6 py-4">
                                    <div class="flex items-center gap-4">
                                        <Link
                                            :href="route('divisions.edit', d.id)"
                                            class="text-sm font-medium text-blue-600 hover:underline"
                                        >Edit</Link>
                                        <button
                                            type="button"
                                            :disabled="! d.canDelete"
                                            :title="d.canDelete ? '' : 'Has sub-offices, users, documents, or routing history — reassign or remove those first.'"
                                            class="text-sm font-medium text-red-600 hover:underline disabled:cursor-not-allowed disabled:text-gray-300 disabled:no-underline"
                                            @click="deleteDivision(d.id, d.name, d.canDelete)"
                                        >Delete</button>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
