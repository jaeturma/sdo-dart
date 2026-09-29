<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import FileInput from '@/Components/FileInput.vue';
import { Head, useForm, router } from '@inertiajs/vue3';
import { ref, computed } from 'vue';

const props = defineProps({
    credential: Object, // null if not yet uploaded
});

const showReplaceForm = ref(false);

const form = useForm({
    p12_file:     null,
    p12_password: '',
    esig_image:   null,
});

const submit = () => form.post(route('my-signature.store'), { forceFormData: true });

const deleteCredential = () => {
    if (! confirm('Remove your signing credential? This cannot be undone.')) return;
    router.delete(route('my-signature.destroy'));
};

const formatDate = iso => iso
    ? new Date(iso).toLocaleDateString('en-PH', { year: 'numeric', month: 'long', day: 'numeric' })
    : '—';

const statusBadge = computed(() => {
    if (! props.credential) return null;
    if (props.credential.is_expired)       return { label: 'Expired',        cls: 'bg-red-100 text-red-800' };
    if (props.credential.is_expiring_soon) return { label: 'Expiring Soon',  cls: 'bg-yellow-100 text-yellow-800' };
    return { label: 'Valid', cls: 'bg-green-100 text-green-800' };
});
</script>

<template>
    <Head title="My Signature" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">My Signing Credential</h2>
        </template>

        <div class="py-8">
            <div class="mx-auto max-w-2xl sm:px-6 lg:px-8 space-y-6">

                <!-- ── Credential details (only when one exists) ── -->
                <template v-if="credential">
                    <div class="overflow-hidden rounded-lg bg-white shadow-sm">
                        <div class="flex items-center justify-between border-b border-gray-100 px-6 py-4">
                            <h3 class="text-sm font-semibold uppercase tracking-wide text-gray-600">Certificate Details</h3>
                            <span
                                class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium"
                                :class="statusBadge.cls"
                            >{{ statusBadge.label }}</span>
                        </div>
                        <dl class="divide-y divide-gray-100">
                            <div class="grid grid-cols-3 gap-4 px-6 py-4">
                                <dt class="text-sm font-medium text-gray-500">Subject (CN)</dt>
                                <dd class="col-span-2 text-sm text-gray-900 break-all">{{ credential.certificate_subject ?? '—' }}</dd>
                            </div>
                            <div class="grid grid-cols-3 gap-4 px-6 py-4">
                                <dt class="text-sm font-medium text-gray-500">Serial Number</dt>
                                <dd class="col-span-2 font-mono text-sm text-gray-900 break-all">{{ credential.certificate_serial ?? '—' }}</dd>
                            </div>
                            <div class="grid grid-cols-3 gap-4 px-6 py-4">
                                <dt class="text-sm font-medium text-gray-500">Valid From</dt>
                                <dd class="col-span-2 text-sm text-gray-900">{{ formatDate(credential.certificate_valid_from) }}</dd>
                            </div>
                            <div class="grid grid-cols-3 gap-4 px-6 py-4">
                                <dt class="text-sm font-medium text-gray-500">Valid Until</dt>
                                <dd class="col-span-2 text-sm text-gray-900" :class="credential.is_expired ? 'text-red-600 font-semibold' : ''">
                                    {{ formatDate(credential.certificate_valid_until) }}
                                </dd>
                            </div>
                            <div class="grid grid-cols-3 gap-4 px-6 py-4">
                                <dt class="text-sm font-medium text-gray-500">Uploaded</dt>
                                <dd class="col-span-2 text-sm text-gray-900">{{ formatDate(credential.uploaded_at) }}</dd>
                            </div>
                        </dl>
                    </div>

                    <!-- E-signature image preview -->
                    <div class="overflow-hidden rounded-lg bg-white shadow-sm">
                        <div class="border-b border-gray-100 px-6 py-4">
                            <h3 class="text-sm font-semibold uppercase tracking-wide text-gray-600">E-Signature Image</h3>
                        </div>
                        <div v-if="credential.has_esig" class="flex items-center justify-center bg-gray-50 p-8">
                            <img
                                :src="route('my-signature.esig')"
                                alt="E-Signature"
                                class="max-h-32 max-w-full rounded border border-gray-200 bg-white object-contain p-2 shadow-sm"
                            />
                        </div>
                        <p v-else class="px-6 py-4 text-sm text-gray-500">No e-signature image uploaded yet.</p>
                    </div>

                    <!-- Action buttons -->
                    <div class="flex flex-wrap items-center gap-3">
                        <button
                            type="button"
                            class="rounded-lg border border-indigo-300 bg-indigo-50 px-4 py-2 text-sm font-medium text-indigo-700 hover:bg-indigo-100"
                            @click="showReplaceForm = !showReplaceForm"
                        >
                            {{ showReplaceForm ? 'Cancel' : 'Replace Certificate' }}
                        </button>
                        <button
                            type="button"
                            class="rounded-lg border border-red-300 bg-red-50 px-4 py-2 text-sm font-medium text-red-700 hover:bg-red-100"
                            @click="deleteCredential"
                        >
                            Remove Credential
                        </button>
                    </div>
                </template>

                <!-- ── Upload form (shown when no credential, or when replacing) ── -->
                <div
                    v-if="!credential || showReplaceForm"
                    class="overflow-hidden rounded-lg bg-white shadow-sm"
                >
                    <div class="border-b border-gray-100 px-6 py-4">
                        <h3 class="text-sm font-semibold uppercase tracking-wide text-gray-600">
                            {{ credential ? 'Upload Replacement Certificate' : 'Upload Signing Credential' }}
                        </h3>
                        <p class="mt-1 text-xs text-gray-500">
                            Your password is used only to validate the certificate and is <strong>never stored</strong>.
                            <template v-if="credential"> Uploading will immediately replace your existing credential.</template>
                        </p>
                    </div>

                    <form @submit.prevent="submit" class="divide-y divide-gray-100">
                        <div class="p-6 space-y-5">

                            <!-- P12 file -->
                            <div>
                                <label for="p12_file" class="mb-2 block text-sm font-medium text-gray-900">
                                    P12 / PFX Certificate File <span class="text-red-500">*</span>
                                </label>
                                <FileInput
                                    id="p12_file"
                                    v-model="form.p12_file"
                                    accept=".p12,.pfx"
                                    hint="Accepts .p12 or .pfx files up to 5 MB."
                                    :error="form.errors.p12_file"
                                />
                            </div>

                            <!-- Password -->
                            <div>
                                <label for="p12_password" class="mb-2 block text-sm font-medium text-gray-900">
                                    Certificate Password <span class="text-red-500">*</span>
                                </label>
                                <input
                                    id="p12_password"
                                    v-model="form.p12_password"
                                    type="password"
                                    autocomplete="off"
                                    class="block w-full rounded-lg border border-gray-300 bg-gray-50 p-2.5 text-sm text-gray-900 focus:border-indigo-500 focus:ring-indigo-500"
                                    placeholder="Enter your P12 password"
                                />
                                <p class="mt-1 text-xs text-gray-500">Used only to verify the certificate. Never saved to the database.</p>
                                <p v-if="form.errors.p12_password" class="mt-1 text-sm text-red-600">{{ form.errors.p12_password }}</p>
                            </div>

                            <!-- E-signature image -->
                            <div>
                                <label for="esig_image" class="mb-2 block text-sm font-medium text-gray-900">
                                    E-Signature Image <span class="text-gray-400 font-normal">(optional)</span>
                                </label>
                                <FileInput
                                    id="esig_image"
                                    v-model="form.esig_image"
                                    accept=".png,.jpg,.jpeg"
                                    hint="PNG or JPG image of your handwritten signature, up to 2 MB."
                                    :error="form.errors.esig_image"
                                />
                            </div>

                        </div>

                        <div class="flex items-center justify-end gap-3 bg-gray-50 px-6 py-4">
                            <button
                                v-if="credential"
                                type="button"
                                class="rounded-lg border border-gray-300 bg-white px-5 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-100"
                                @click="showReplaceForm = false"
                            >
                                Cancel
                            </button>
                            <button
                                type="submit"
                                :disabled="form.processing"
                                class="inline-flex items-center gap-2 rounded-lg bg-indigo-700 px-5 py-2.5 text-sm font-medium text-white hover:bg-indigo-800 focus:outline-none focus:ring-4 focus:ring-indigo-300 disabled:opacity-60"
                            >
                                <svg v-if="form.processing" class="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"/>
                                </svg>
                                {{ credential ? 'Upload & Replace' : 'Upload Credential' }}
                            </button>
                        </div>
                    </form>
                </div>

            </div>
        </div>
    </AuthenticatedLayout>
</template>
