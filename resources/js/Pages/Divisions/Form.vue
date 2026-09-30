<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    division:              Object,  // null = create mode
    parentOptions:         Array,   // flat ordered list with depth
    currentRecordsSection: String,  // name of another office already designated, if any
});

const isEdit = computed(() => props.division !== null);
const isOsds = computed(() => props.division?.is_osds === true);

const form = useForm({
    name:                     props.division?.name                     ?? '',
    code:                     props.division?.code                     ?? '',
    parent_id:                props.division?.parent_id                ?? '',
    allow_sds_direct_routing:  props.division?.allow_sds_direct_routing  ?? false,
    is_records_section:       props.division?.is_records_section       ?? false,
});

const submit = () => {
    if (isEdit.value) {
        form.put(route('divisions.update', props.division.id));
    } else {
        form.post(route('divisions.store'));
    }
};
</script>

<template>
    <Head :title="isEdit ? 'Edit Office' : 'New Office'" />

    <AuthenticatedLayout>
        <template #header>
            <div>
                <p class="text-sm text-gray-500 mb-1">Office Management</p>
                <h2 class="text-xl font-semibold leading-tight text-gray-800">
                    {{ isEdit ? `Edit: ${division.name}` : 'Create New Office' }}
                </h2>
            </div>
        </template>

        <div class="py-8">
            <div class="mx-auto max-w-2xl sm:px-6 lg:px-8">
                <div class="overflow-hidden rounded-lg bg-white shadow-sm">
                    <form @submit.prevent="submit" class="divide-y divide-gray-100">
                        <div class="p-6 space-y-5">

                            <!-- Name -->
                            <div>
                                <label for="name" class="mb-2 block text-sm font-medium text-gray-900">
                                    Office Name <span class="text-red-500">*</span>
                                </label>
                                <input
                                    id="name"
                                    v-model="form.name"
                                    type="text"
                                    class="block w-full rounded-lg border border-gray-300 bg-gray-50 p-2.5 text-sm text-gray-900 focus:border-blue-500 focus:ring-blue-500"
                                    placeholder="e.g. Curriculum and Learning Management Division"
                                    required
                                />
                                <p v-if="form.errors.name" class="mt-1 text-sm text-red-600">{{ form.errors.name }}</p>
                            </div>

                            <!-- Code -->
                            <div>
                                <label for="code" class="mb-2 block text-sm font-medium text-gray-900">Code</label>
                                <input
                                    id="code"
                                    v-model="form.code"
                                    type="text"
                                    maxlength="20"
                                    :disabled="isOsds"
                                    class="block w-full rounded-lg border border-gray-300 bg-gray-50 p-2.5 text-sm text-gray-900 uppercase focus:border-blue-500 focus:ring-blue-500 disabled:cursor-not-allowed disabled:text-gray-500"
                                    placeholder="e.g. CID"
                                />
                                <p v-if="isOsds" class="mt-1 text-xs text-gray-500">
                                    Fixed — the system uses this code to identify the Office of the SDS, so it can't be changed.
                                </p>
                                <p v-else class="mt-1 text-xs text-gray-500">
                                    Optional. Used as the office prefix in outgoing control numbers (e.g. OUTGOING-CID-2026-09-001); a sub-office without its own code uses its parent office's.
                                </p>
                                <p v-if="form.errors.code" class="mt-1 text-sm text-red-600">{{ form.errors.code }}</p>
                            </div>

                            <!-- Parent office -->
                            <div>
                                <label for="parent_id" class="mb-2 block text-sm font-medium text-gray-900">Parent Office</label>
                                <select
                                    id="parent_id"
                                    v-model="form.parent_id"
                                    class="block w-full rounded-lg border border-gray-300 bg-gray-50 p-2.5 text-sm text-gray-900 focus:border-blue-500 focus:ring-blue-500"
                                >
                                    <option value="">— None (top-level office) —</option>
                                    <option
                                        v-for="opt in parentOptions"
                                        :key="opt.id"
                                        :value="opt.id"
                                    >{{ opt.depth > 0 ? '　└ ' : '' }}{{ opt.label }}</option>
                                </select>
                                <p class="mt-1 text-xs text-gray-500">
                                    Leave blank for a top-level office (a Division). Choose a parent to make this a sub-office/unit under it.
                                </p>
                                <p v-if="form.errors.parent_id" class="mt-1 text-sm text-red-600">{{ form.errors.parent_id }}</p>
                            </div>

                            <!-- Allow SDS to route directly to this unit's head -->
                            <div>
                                <label class="flex cursor-pointer items-start gap-2 rounded-lg border border-gray-300 bg-gray-50 p-3 text-sm has-[:checked]:border-blue-500 has-[:checked]:bg-blue-50">
                                    <input type="checkbox" v-model="form.allow_sds_direct_routing" class="mt-0.5" />
                                    <span>
                                        <span class="block font-medium text-gray-900">Allow SDS to Route Directly to This Unit's Head</span>
                                        <span class="block text-gray-500">When enabled, the SDS (and her secretary) can route an incoming document straight to this office's unit head, the same way she routes to a division chief — not only through the parent office's chief first. Only meaningful for a sub-office with its own unit head (e.g. a unit under CID or SGOD).</span>
                                    </span>
                                </label>
                                <p v-if="form.errors.allow_sds_direct_routing" class="mt-1 text-sm text-red-600">{{ form.errors.allow_sds_direct_routing }}</p>
                            </div>

                            <!-- Records Section designation -->
                            <div>
                                <label class="flex cursor-pointer items-start gap-2 rounded-lg border border-gray-300 bg-gray-50 p-3 text-sm has-[:checked]:border-blue-500 has-[:checked]:bg-blue-50">
                                    <input type="checkbox" v-model="form.is_records_section" class="mt-0.5" />
                                    <span>
                                        <span class="block font-medium text-gray-900">This Office Is the Records Section</span>
                                        <span class="block text-gray-500">Staff assigned to this office apply the release stamp to signed outgoing documents, receive the SDS secretary's incoming uploads for filing, can upload incoming documents, and can view the daily Incoming Documents Log. Only one office can be the Records Section.</span>
                                        <span
                                            v-if="currentRecordsSection && form.is_records_section"
                                            class="mt-1 block font-medium text-amber-700"
                                        >Saving moves the designation here from "{{ currentRecordsSection }}".</span>
                                        <span
                                            v-else-if="currentRecordsSection"
                                            class="mt-1 block text-gray-500"
                                        >Currently designated: {{ currentRecordsSection }}.</span>
                                    </span>
                                </label>
                                <p v-if="form.errors.is_records_section" class="mt-1 text-sm text-red-600">{{ form.errors.is_records_section }}</p>
                            </div>

                        </div>

                        <!-- Actions -->
                        <div class="flex items-center justify-end gap-3 bg-gray-50 px-6 py-4">
                            <a
                                :href="route('divisions.index')"
                                class="rounded-lg border border-gray-300 bg-white px-5 py-2.5 text-sm font-medium text-gray-700 hover:bg-gray-100"
                            >Cancel</a>
                            <button
                                type="submit"
                                :disabled="form.processing"
                                class="inline-flex items-center gap-2 rounded-lg bg-blue-700 px-5 py-2.5 text-sm font-medium text-white hover:bg-blue-800 focus:outline-none focus:ring-4 focus:ring-blue-300 disabled:opacity-60"
                            >
                                <svg v-if="form.processing" class="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"/>
                                </svg>
                                {{ isEdit ? 'Save Changes' : 'Create Office' }}
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
