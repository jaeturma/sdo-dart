<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import FileInput from '@/Components/FileInput.vue';
import { Head, useForm } from '@inertiajs/vue3';

const form = useForm({
    title: '',
    description: '',
    file: null,
    requires_release: null,
});

const submit = () => {
    form.post(route('documents.outgoing.store'), {
        forceFormData: true,
    });
};
</script>

<template>
    <Head title="Submit Outgoing Document" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">Submit Outgoing Document</h2>
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

                            <!-- Release by Records Section -->
                            <div>
                                <label class="mb-2 block text-sm font-medium text-gray-900">
                                    Should this document be released by Records Section? <span class="text-red-500">*</span>
                                </label>
                                <div class="space-y-2">
                                    <label class="flex cursor-pointer items-start gap-2 rounded-lg border border-gray-300 bg-gray-50 p-3 text-sm has-[:checked]:border-blue-500 has-[:checked]:bg-blue-50">
                                        <input type="radio" v-model="form.requires_release" :value="true" name="requires_release" class="mt-0.5" required />
                                        <span>
                                            <span class="block font-medium text-gray-900">Yes, release through Records Section</span>
                                            <span class="block text-gray-500">Once signed, it goes to Records Section's "Ready to Release" queue for release through email.</span>
                                        </span>
                                    </label>
                                    <label class="flex cursor-pointer items-start gap-2 rounded-lg border border-gray-300 bg-gray-50 p-3 text-sm has-[:checked]:border-blue-500 has-[:checked]:bg-blue-50">
                                        <input type="radio" v-model="form.requires_release" :value="false" name="requires_release" class="mt-0.5" required />
                                        <span>
                                            <span class="block font-medium text-gray-900">No, for our own use</span>
                                            <span class="block text-gray-500">Once signed, you'll just be notified — it won't be sent to Records Section.</span>
                                        </span>
                                    </label>
                                </div>
                                <p v-if="form.errors.requires_release" class="mt-1 text-sm text-red-600">{{ form.errors.requires_release }}</p>
                            </div>

                        </div>

                        <div class="flex items-center justify-end gap-3 px-6 py-4 bg-gray-50">
                            <a :href="route('documents.index')" class="rounded-lg border border-gray-300 bg-white px-5 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-100">
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
                                Submit for Review
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
