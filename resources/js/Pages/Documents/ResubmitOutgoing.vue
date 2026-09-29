<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import FileInput from '@/Components/FileInput.vue';
import { Head, useForm } from '@inertiajs/vue3';

const props = defineProps({
    document: Object,
});

const form = useForm({
    title: props.document.title,
    description: props.document.description ?? '',
    file: null,
});

const submit = () => {
    form.transform(data => ({ ...data, _method: 'put' }))
        .post(route('documents.outgoing.resubmit.update', props.document.id), {
            forceFormData: true,
        });
};
</script>

<template>
    <Head title="Correct and Resubmit Document" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">Correct and Resubmit</h2>
        </template>

        <div class="py-8">
            <div class="mx-auto max-w-2xl sm:px-6 lg:px-8">
                <div class="overflow-hidden rounded-lg bg-white shadow-sm">
                    <form @submit.prevent="submit" class="divide-y divide-gray-100">
                        <div class="p-6 space-y-6">

                            <p class="rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-800">
                                Update the details and attach the corrected file. This restarts the review chain from the beginning.
                            </p>

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
                                    placeholder="Enter document title"
                                    required
                                />
                                <p v-if="form.errors.title" class="mt-1 text-sm text-red-600">{{ form.errors.title }}</p>
                            </div>

                            <!-- Description -->
                            <div>
                                <label for="description" class="mb-2 block text-sm font-medium text-gray-900">
                                    Description / Purpose
                                </label>
                                <textarea
                                    id="description"
                                    v-model="form.description"
                                    rows="4"
                                    class="block w-full rounded-lg border border-gray-300 bg-gray-50 p-2.5 text-sm text-gray-900 focus:border-blue-500 focus:ring-blue-500"
                                    placeholder="Briefly describe the document and its purpose"
                                />
                                <p v-if="form.errors.description" class="mt-1 text-sm text-red-600">{{ form.errors.description }}</p>
                            </div>

                            <!-- File -->
                            <div>
                                <label for="file" class="mb-2 block text-sm font-medium text-gray-900">
                                    Corrected File <span class="text-red-500">*</span>
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
                            <a :href="route('documents.show', document.id)" class="rounded-lg border border-gray-300 bg-white px-5 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-100">
                                Cancel
                            </a>
                            <button
                                type="submit"
                                :disabled="form.processing"
                                class="inline-flex items-center gap-2 rounded-lg bg-blue-700 px-5 py-2.5 text-sm font-medium text-white hover:bg-blue-800 focus:outline-none focus:ring-4 focus:ring-blue-300 disabled:opacity-60"
                            >
                                <svg v-if="form.processing" class="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"/>
                                </svg>
                                Resubmit for Review
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
