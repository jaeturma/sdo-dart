<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    document: Object,
    users:    Array,
});

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

const form = useForm({
    is_rush:      false,
    remarks:      '',
    action:       '',
    action_other: '',
    user_ids:     [],
});

const submit = () => form.post(route('documents.routings.store', props.document.id));

const allSelected  = computed(() => form.user_ids.length === props.users.length && props.users.length > 0);
const someSelected = computed(() => form.user_ids.length > 0 && !allSelected.value);

const toggleAll = () => {
    if (allSelected.value) {
        form.user_ids = [];
    } else {
        form.user_ids = props.users.map(u => u.id);
    }
};
</script>

<template>
    <Head :title="`Route: ${document.title}`" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center gap-3">
                <Link :href="route('documents.show', document.id)" class="text-gray-400 hover:text-gray-600">
                    <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/>
                    </svg>
                </Link>
                <div>
                    <p class="text-sm text-gray-500">Routing Action Slip</p>
                    <h2 class="text-xl font-semibold leading-tight text-gray-800">{{ document.title }}</h2>
                </div>
            </div>
        </template>

        <div class="py-8">
            <div class="mx-auto max-w-4xl sm:px-6 lg:px-8">
                <div class="overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-gray-200">
                    <form @submit.prevent="submit">

                        <!-- RUSH toggle -->
                        <div class="flex items-center gap-3 border-b border-gray-100 px-6 py-4">
                            <label class="flex cursor-pointer items-center gap-3 select-none">
                                <input
                                    v-model="form.is_rush"
                                    type="checkbox"
                                    class="h-4 w-4 rounded border-gray-300 text-red-600 focus:ring-red-500"
                                />
                                <div>
                                    <span class="text-sm font-bold text-red-600">RUSH</span>
                                    <span v-if="form.is_rush" class="ml-2 text-xs text-red-500">— Requires immediate action</span>
                                </div>
                            </label>
                        </div>

                        <!-- Two-column: recipients + actions — kept side by side even on small screens -->
                        <div class="grid grid-cols-2 divide-x divide-gray-100">

                            <!-- LEFT: Recipients -->
                            <div class="flex flex-col">
                                <div class="flex items-center justify-between border-b border-gray-100 px-4 py-3">
                                    <h3 class="text-xs font-semibold uppercase tracking-wide text-gray-500">
                                        Recipients
                                        <span v-if="form.user_ids.length" class="ml-1 rounded-full bg-blue-100 px-1.5 py-0.5 text-blue-700">
                                            {{ form.user_ids.length }}
                                        </span>
                                    </h3>
                                    <button
                                        type="button"
                                        class="text-xs text-blue-600 hover:underline"
                                        @click="toggleAll"
                                    >
                                        {{ allSelected ? 'Deselect all' : 'Select all' }}
                                    </button>
                                </div>

                                <div class="max-h-96 overflow-y-auto divide-y divide-gray-50">
                                    <label
                                        v-for="user in users"
                                        :key="user.id"
                                        class="flex cursor-pointer items-start gap-3 px-4 py-3 hover:bg-blue-50 transition-colors"
                                        :class="form.user_ids.includes(user.id) ? 'bg-blue-50' : ''"
                                    >
                                        <input
                                            v-model="form.user_ids"
                                            type="checkbox"
                                            :value="user.id"
                                            class="mt-0.5 h-4 w-4 rounded border-gray-300 text-blue-600 focus:ring-blue-500"
                                        />
                                        <div class="min-w-0">
                                            <p class="text-sm font-medium text-gray-900 leading-tight">{{ user.name }}</p>
                                            <p class="text-xs text-gray-500 leading-tight">{{ user.subtitle }}</p>
                                            <p v-if="user.division" class="text-xs text-gray-400 leading-tight">{{ user.division }}</p>
                                        </div>
                                    </label>
                                    <p v-if="users.length === 0" class="px-4 py-6 text-center text-sm text-gray-400">
                                        No eligible recipients found.
                                    </p>
                                </div>
                                <p v-if="form.errors.user_ids" class="px-4 py-2 text-xs text-red-600">{{ form.errors.user_ids }}</p>
                            </div>

                            <!-- RIGHT: Action -->
                            <div class="flex flex-col">
                                <div class="border-b border-gray-100 px-4 py-3">
                                    <h3 class="text-xs font-semibold uppercase tracking-wide text-gray-500">Action</h3>
                                </div>

                                <div class="max-h-96 overflow-y-auto divide-y divide-gray-50">
                                    <label
                                        v-for="a in ROUTING_ACTIONS"
                                        :key="a.value"
                                        class="flex cursor-pointer items-center gap-3 px-4 py-2.5 hover:bg-blue-50 transition-colors"
                                        :class="form.action === a.value ? 'bg-blue-50' : ''"
                                    >
                                        <input
                                            v-model="form.action"
                                            type="radio"
                                            :value="a.value"
                                            class="h-4 w-4 border-gray-300 text-blue-600 focus:ring-blue-500"
                                        />
                                        <span class="text-sm text-gray-700">{{ a.label }}</span>
                                    </label>
                                </div>
                                <p v-if="form.errors.action" class="px-4 py-2 text-xs text-red-600">{{ form.errors.action }}</p>

                                <!-- Others specify -->
                                <div v-if="form.action === 'others'" class="border-t border-gray-100 px-4 py-3">
                                    <input
                                        v-model="form.action_other"
                                        type="text"
                                        class="block w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 focus:border-blue-500 focus:ring-blue-500"
                                        placeholder="Please specify…"
                                    />
                                    <p v-if="form.errors.action_other" class="mt-1 text-xs text-red-600">{{ form.errors.action_other }}</p>
                                </div>
                            </div>
                        </div>

                        <!-- Remarks -->
                        <div class="border-t border-gray-100 px-6 py-4">
                            <label for="remarks" class="mb-2 block text-sm font-medium text-gray-700">
                                Remarks <span class="font-normal text-gray-400">(optional)</span>
                            </label>
                            <textarea
                                id="remarks"
                                v-model="form.remarks"
                                rows="3"
                                class="block w-full rounded-lg border border-gray-300 bg-gray-50 p-2.5 text-sm text-gray-900 focus:border-blue-500 focus:ring-blue-500"
                                placeholder="Leave empty if not necessary."
                            />
                            <p v-if="form.errors.remarks" class="mt-1 text-sm text-red-600">{{ form.errors.remarks }}</p>
                        </div>

                        <!-- Footer actions -->
                        <div class="flex items-center justify-end gap-3 border-t border-gray-100 bg-gray-50 px-6 py-4">
                            <Link
                                :href="route('documents.show', document.id)"
                                class="rounded-lg border border-gray-300 bg-white px-5 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-100"
                            >
                                Cancel
                            </Link>
                            <button
                                type="submit"
                                :disabled="form.processing || !form.user_ids.length || !form.action"
                                class="inline-flex items-center gap-2 rounded-lg bg-indigo-700 px-5 py-2.5 text-sm font-medium text-white hover:bg-indigo-800 focus:outline-none focus:ring-4 focus:ring-indigo-300 disabled:opacity-50"
                            >
                                <svg v-if="form.processing" class="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"/>
                                </svg>
                                Route Now
                            </button>
                        </div>

                    </form>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
