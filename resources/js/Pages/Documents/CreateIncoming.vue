<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import FileInput from '@/Components/FileInput.vue';
import { Head, useForm } from '@inertiajs/vue3';

const form = useForm({
    title: '',
    description: '',
    source_origin: '',
    reference_number: '',
    file: null,
});

const submit = () => {
    form.post(route('documents.incoming.store'), {
        forceFormData: true,
    });
};
</script>

<template>
    <Head title="Upload Incoming Document" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">Upload Incoming Document</h2>
        </template>

        <div class="py-8">
            <div class="mx-auto max-w-2xl sm:px-6 lg:px-8">
                <div class="overflow-hidden rounded-lg bg-white shadow-sm">
                    <form @submit.prevent="submit" class="divide-y divide-gray-100">
                        <div class="p-6 space-y-6">

                            <!-- Title -->
                            <div>
                                <label for="title" class="mb-2 block text-sm font-medium text-gray-900">
                                    Document Title <span class="text-red-500">*</span>
                                </label>
                                <input
                                    id="title"
                                    v-model="form.title"
                                    type="text"
                                    class="block w-full rounded-lg border border-gray-300 bg-gray-50 p-2.5 text-sm text-gray-900 focus:border-blue-500 focus:ring-blue-500"
                                    placeholder="e.g. Memorandum from Central Office re: Budget Allocation"
                                    required
                                />
                                <p v-if="form.errors.title" class="mt-1 text-sm text-red-600">{{ form.errors.title }}</p>
                            </div>

                            <!-- Source Origin -->
                            <div>
                                <label for="source_origin" class="mb-2 block text-sm font-medium text-gray-900">
                                    Source / Origin <span class="text-red-500">*</span>
                                </label>
                                <input
                                    id="source_origin"
                                    v-model="form.source_origin"
                                    type="text"
                                    class="block w-full rounded-lg border border-gray-300 bg-gray-50 p-2.5 text-sm text-gray-900 focus:border-blue-500 focus:ring-blue-500"
                                    placeholder="e.g. Central Office, CHED, DBM"
                                    required
                                />
                                <p v-if="form.errors.source_origin" class="mt-1 text-sm text-red-600">{{ form.errors.source_origin }}</p>
                            </div>

                            <!-- Reference Number -->
                            <div>
                                <label for="reference_number" class="mb-2 block text-sm font-medium text-gray-900">
                                    Reference Number
                                </label>
                                <input
                                    id="reference_number"
                                    v-model="form.reference_number"
                                    type="text"
                                    class="block w-full rounded-lg border border-gray-300 bg-gray-50 p-2.5 text-sm text-gray-900 focus:border-blue-500 focus:ring-blue-500"
                                    placeholder="e.g. CO-2026-001"
                                />
                                <p v-if="form.errors.reference_number" class="mt-1 text-sm text-red-600">{{ form.errors.reference_number }}</p>
                            </div>

                            <!-- Description -->
                            <div>
                                <label for="description" class="mb-2 block text-sm font-medium text-gray-900">Remarks / Notes</label>
                                <textarea
                                    id="description"
                                    v-model="form.description"
                                    rows="3"
                                    class="block w-full rounded-lg border border-gray-300 bg-gray-50 p-2.5 text-sm text-gray-900 focus:border-blue-500 focus:ring-blue-500"
                                    placeholder="Additional notes about this document"
                                />
                                <p v-if="form.errors.description" class="mt-1 text-sm text-red-600">{{ form.errors.description }}</p>
                            </div>

                            <!-- File -->
                            <div>
                                <label for="file" class="mb-2 block text-sm font-medium text-gray-900">
                                    Document File <span class="text-red-500">*</span>
                                </label>
                                <FileInput
                                    id="file"
                                    v-model="form.file"
                                    accept=".pdf"
                                    hint="PDF up to 10 MB."
                                    :error="form.errors.file"
                                />
                            </div>

                        </div>

                        <div class="flex items-center justify-end gap-3 px-6 py-4 bg-gray-50">
                            <a :href="route('documents.index')" class="rounded-lg border border-gray-300 bg-white px-5 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-100">
                                Cancel
                            </a>
                            <button
                                type="submit"
                                :disabled="form.processing"
                                class="inline-flex items-center gap-2 rounded-lg bg-green-700 px-5 py-2.5 text-sm font-medium text-white hover:bg-green-800 focus:outline-none focus:ring-4 focus:ring-green-300 disabled:opacity-60"
                            >
                                <svg v-if="form.processing" class="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"/>
                                </svg>
                                Upload Document
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
