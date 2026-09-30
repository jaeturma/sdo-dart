<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, router } from '@inertiajs/vue3';

defineProps({
    users: Array,
});

const formatDate = iso => iso
    ? new Date(iso).toLocaleDateString('en-PH', { year: 'numeric', month: 'short', day: 'numeric' })
    : '—';

const ROLE_LABEL = {
    sds:             'SDS',
    asds:            'ASDS',
    division_chief: 'Division Chief',
};

const statusBadge = credential => {
    if (! credential)                    return { label: 'No credential',   cls: 'bg-gray-100 text-gray-600' };
    if (credential.is_expired)           return { label: 'Expired',         cls: 'bg-red-100 text-red-800' };
    if (credential.is_expiring_soon)     return { label: 'Expiring Soon',   cls: 'bg-yellow-100 text-yellow-800' };
    return { label: 'Valid', cls: 'bg-green-100 text-green-800' };
};

const revoke = (userId, userName) => {
    if (! confirm(`Revoke signing credential for ${userName}? This cannot be undone.`)) return;
    router.delete(route('signing-credentials.destroy', userId));
};
</script>

<template>
    <Head title="Signing Credentials" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">Signing Credentials</h2>
        </template>

        <div class="py-8">
            <div class="mx-auto max-w-6xl sm:px-6 lg:px-8">
                <div class="overflow-hidden rounded-lg border border-gray-200 bg-white shadow-sm">
                    <table class="w-full text-left text-sm text-gray-600">
                        <thead class="bg-gray-50 text-xs font-semibold uppercase tracking-wide text-gray-500">
                            <tr>
                                <th class="px-6 py-3">Name</th>
                                <th class="px-6 py-3">Role(s)</th>
                                <th class="px-6 py-3">Division</th>
                                <th class="px-6 py-3">Certificate Subject</th>
                                <th class="px-6 py-3">Valid Until</th>
                                <th class="px-6 py-3">Status</th>
                                <th class="px-6 py-3"><span class="sr-only">Actions</span></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <tr v-if="users.length === 0">
                                <td colspan="7" class="px-6 py-12 text-center text-gray-400">
                                    No signatory users found.
                                </td>
                            </tr>
                            <tr v-for="u in users" :key="u.id" class="hover:bg-gray-50">
                                <td class="px-6 py-4 font-medium text-gray-900">{{ u.name }}</td>
                                <td class="px-6 py-4">
                                    <span
                                        v-for="role in u.roles" :key="role"
                                        class="me-1 inline-flex items-center rounded px-1.5 py-0.5 text-xs font-medium bg-indigo-100 text-indigo-700"
                                    >{{ ROLE_LABEL[role] ?? role }}</span>
                                </td>
                                <td class="px-6 py-4 text-gray-500">{{ u.division ?? '—' }}</td>
                                <td class="px-6 py-4 break-all text-gray-700">
                                    {{ u.credential?.certificate_subject ?? '—' }}
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    {{ formatDate(u.credential?.certificate_valid_until) }}
                                </td>
                                <td class="px-6 py-4">
                                    <span
                                        class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium"
                                        :class="statusBadge(u.credential).cls"
                                    >{{ statusBadge(u.credential).label }}</span>
                                </td>
                                <td class="px-6 py-4">
                                    <button
                                        v-if="u.credential"
                                        type="button"
                                        class="text-xs font-medium text-red-600 hover:text-red-800 hover:underline"
                                        @click="revoke(u.id, u.name)"
                                    >
                                        Revoke
                                    </button>
                                    <span v-else class="text-xs text-gray-400">—</span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <p class="mt-3 text-xs text-gray-500">
                    Signatories upload their own credentials via "My Signature" in the navigation. Admins may only view status and revoke.
                </p>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
