<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    user:      Object,  // null = create mode
    divisions: Array,   // flat ordered list with depth
    roles:     Object,  // { role_key: 'Label' }
});

const isEdit = computed(() => props.user !== null);

const form = useForm({
    name:                  props.user?.name        ?? '',
    email:                 props.user?.email       ?? '',
    password:              '',
    password_confirmation: '',
    role:                  props.user?.role        ?? '',
    kmt_focal:             props.user?.kmt_focal   ?? false,
    division_id:           props.user?.division_id ?? '',
});

const submit = () => {
    if (isEdit.value) {
        form.put(route('users.update', props.user.id));
    } else {
        form.post(route('users.store'));
    }
};
</script>

<template>
    <Head :title="isEdit ? 'Edit User' : 'New User'" />

    <AuthenticatedLayout>
        <template #header>
            <div>
                <p class="text-sm text-gray-500 mb-1">User Management</p>
                <h2 class="text-xl font-semibold leading-tight text-gray-800">
                    {{ isEdit ? `Edit: ${user.name}` : 'Create New User' }}
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
                                    Full Name <span class="text-red-500">*</span>
                                </label>
                                <input
                                    id="name"
                                    v-model="form.name"
                                    type="text"
                                    class="block w-full rounded-lg border border-gray-300 bg-gray-50 p-2.5 text-sm text-gray-900 focus:border-blue-500 focus:ring-blue-500"
                                    placeholder="e.g. Juan Dela Cruz"
                                    required
                                />
                                <p v-if="form.errors.name" class="mt-1 text-sm text-red-600">{{ form.errors.name }}</p>
                            </div>

                            <!-- Email -->
                            <div>
                                <label for="email" class="mb-2 block text-sm font-medium text-gray-900">
                                    Email Address <span class="text-red-500">*</span>
                                </label>
                                <input
                                    id="email"
                                    v-model="form.email"
                                    type="email"
                                    class="block w-full rounded-lg border border-gray-300 bg-gray-50 p-2.5 text-sm text-gray-900 focus:border-blue-500 focus:ring-blue-500"
                                    placeholder="e.g. jdelacruz@caraga.ched.gov.ph"
                                    required
                                />
                                <p v-if="form.errors.email" class="mt-1 text-sm text-red-600">{{ form.errors.email }}</p>
                            </div>

                            <!-- Role -->
                            <div>
                                <label for="role" class="mb-2 block text-sm font-medium text-gray-900">
                                    Role <span class="text-red-500">*</span>
                                </label>
                                <select
                                    id="role"
                                    v-model="form.role"
                                    class="block w-full rounded-lg border border-gray-300 bg-gray-50 p-2.5 text-sm text-gray-900 focus:border-blue-500 focus:ring-blue-500"
                                    required
                                >
                                    <option value="">— Select role —</option>
                                    <option v-for="(label, key) in roles" :key="key" :value="key">{{ label }}</option>
                                </select>
                                <p v-if="form.errors.role" class="mt-1 text-sm text-red-600">{{ form.errors.role }}</p>
                            </div>

                            <!-- KMT Focal (additional role, layered on top of the one above) -->
                            <div>
                                <label class="flex cursor-pointer items-start gap-2 rounded-lg border border-gray-300 bg-gray-50 p-3 text-sm has-[:checked]:border-blue-500 has-[:checked]:bg-blue-50">
                                    <input type="checkbox" v-model="form.kmt_focal" class="mt-0.5" />
                                    <span>
                                        <span class="block font-medium text-gray-900">KMT Focal</span>
                                        <span class="block text-gray-500">Additional role, on top of the one above. A plain Staff / Originator cannot create outgoing documents — this grants that ability, subject to the usual unit head / division chief review.</span>
                                    </span>
                                </label>
                                <p v-if="form.errors.kmt_focal" class="mt-1 text-sm text-red-600">{{ form.errors.kmt_focal }}</p>
                            </div>

                            <!-- Division / Unit -->
                            <div>
                                <label for="division_id" class="mb-2 block text-sm font-medium text-gray-900">
                                    Division / Unit <span v-if="form.role !== 'detailed_staff'" class="text-red-500">*</span>
                                </label>
                                <select
                                    id="division_id"
                                    v-model="form.division_id"
                                    class="block w-full rounded-lg border border-gray-300 bg-gray-50 p-2.5 text-sm text-gray-900 focus:border-blue-500 focus:ring-blue-500"
                                    :required="form.role !== 'detailed_staff'"
                                >
                                    <option value="">— Select division or unit —</option>
                                    <option
                                        v-for="div in divisions"
                                        :key="div.id"
                                        :value="div.id"
                                    >{{ div.depth > 0 ? '　└ ' : '' }}{{ div.label }}</option>
                                </select>
                                <p v-if="form.role === 'detailed_staff'" class="mt-1 text-xs text-gray-500">
                                    Not applicable — detailed staff aren't tied to a Regional Office division.
                                </p>
                                <p v-if="form.errors.division_id" class="mt-1 text-sm text-red-600">{{ form.errors.division_id }}</p>
                            </div>

                        </div>

                        <!-- Password section -->
                        <div class="p-6 space-y-5">
                            <h3 class="text-sm font-semibold uppercase tracking-wide text-gray-500">
                                {{ isEdit ? 'Reset Password' : 'Password' }}
                            </h3>
                            <p v-if="isEdit" class="text-xs text-gray-500 -mt-3">Leave both fields blank to keep the current password.</p>

                            <div>
                                <label for="password" class="mb-2 block text-sm font-medium text-gray-900">
                                    Password <span v-if="!isEdit" class="text-red-500">*</span>
                                </label>
                                <input
                                    id="password"
                                    v-model="form.password"
                                    type="password"
                                    autocomplete="new-password"
                                    class="block w-full rounded-lg border border-gray-300 bg-gray-50 p-2.5 text-sm text-gray-900 focus:border-blue-500 focus:ring-blue-500"
                                    :required="!isEdit"
                                    placeholder="Minimum 8 characters"
                                />
                                <p v-if="form.errors.password" class="mt-1 text-sm text-red-600">{{ form.errors.password }}</p>
                            </div>

                            <div>
                                <label for="password_confirmation" class="mb-2 block text-sm font-medium text-gray-900">
                                    Confirm Password <span v-if="!isEdit" class="text-red-500">*</span>
                                </label>
                                <input
                                    id="password_confirmation"
                                    v-model="form.password_confirmation"
                                    type="password"
                                    autocomplete="new-password"
                                    class="block w-full rounded-lg border border-gray-300 bg-gray-50 p-2.5 text-sm text-gray-900 focus:border-blue-500 focus:ring-blue-500"
                                    :required="!isEdit"
                                />
                            </div>
                        </div>

                        <!-- Actions -->
                        <div class="flex items-center justify-end gap-3 bg-gray-50 px-6 py-4">
                            <a
                                :href="route('users.index')"
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
                                {{ isEdit ? 'Save Changes' : 'Create User' }}
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
