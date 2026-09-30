<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

const props = defineProps({
    users:      Object, // paginated
    search:     String,
    roleLabels: Object,
    magicLinkSecretRotatedAt: String,
    twoFactorEnforced: Boolean,
    passwordForceResetAt: String,
    emailVerificationEnforced: Boolean,
    asdsAvailable: Boolean,
    allOriginatorsCanUpload: Boolean,
});

const page     = usePage();
const authId   = computed(() => page.props.auth.user?.id);
const magicLink = computed(() => page.props.flash?.magic_link ?? null);
const temporaryPassword = computed(() => page.props.flash?.temporary_password ?? null);

const ROLE_CLASS = {
    admin:          'bg-red-100 text-red-800',
    sds:             'bg-purple-100 text-purple-800',
    asds:            'bg-indigo-100 text-indigo-800',
    division_chief: 'bg-blue-100 text-blue-800',
    unit_head:      'bg-orange-100 text-orange-800',
    originator:     'bg-gray-100 text-gray-700',
};

const deleteUser = (id, name) => {
    if (! confirm(`Delete user "${name}"? This cannot be undone.`)) return;
    router.delete(route('users.destroy', id));
};

const magicLinkForms = {};
const getMagicLinkForm = (userId) => {
    if (! magicLinkForms[userId]) {
        magicLinkForms[userId] = useForm({});
    }
    return magicLinkForms[userId];
};
const generateLink = (userId) => getMagicLinkForm(userId).post(route('users.magic-link', userId));

const resetPasswordForms = {};
const getResetPasswordForm = (userId) => {
    if (! resetPasswordForms[userId]) {
        resetPasswordForms[userId] = useForm({});
    }
    return resetPasswordForms[userId];
};
const resetPassword = (userId, name) => {
    if (! confirm(`Reset ${name}'s password? A new temporary password will be generated and shown once. They will be required to change it on next login.`)) return;
    getResetPasswordForm(userId).post(route('users.reset-password', userId));
};

const toggleActiveForms = {};
const getToggleActiveForm = (userId) => {
    if (! toggleActiveForms[userId]) {
        toggleActiveForms[userId] = useForm({});
    }
    return toggleActiveForms[userId];
};
const toggleActive = (userId, name, isActive) => {
    const message = isActive
        ? `Deactivate ${name}? They will no longer be able to log in.`
        : `Activate ${name}? They will be able to log in again.`;
    if (! confirm(message)) return;
    getToggleActiveForm(userId).post(route('users.toggle-active', userId));
};

const toggleWeekendAccessForms = {};
const getToggleWeekendAccessForm = (userId) => {
    if (! toggleWeekendAccessForms[userId]) {
        toggleWeekendAccessForms[userId] = useForm({});
    }
    return toggleWeekendAccessForms[userId];
};
const toggleWeekendAccess = (userId, name, canLoginWeekends) => {
    const message = canLoginWeekends
        ? `Revoke ${name}'s weekend access? They will no longer be able to log in on Saturday/Sunday.`
        : `Allow ${name} to log in on weekends?`;
    if (! confirm(message)) return;
    getToggleWeekendAccessForm(userId).post(route('users.toggle-weekend-access', userId));
};

const copyToClipboard = async (text) => {
    try {
        if (navigator.clipboard && window.isSecureContext) {
            await navigator.clipboard.writeText(text);
        } else {
            // navigator.clipboard is unavailable outside a secure context
            // (plain http, e.g. the local *.test domain) — fall back to the
            // old textarea + execCommand trick.
            const textarea = document.createElement('textarea');
            textarea.value = text;
            textarea.style.position = 'fixed';
            textarea.style.opacity = '0';
            document.body.appendChild(textarea);
            textarea.focus();
            textarea.select();
            document.execCommand('copy');
            document.body.removeChild(textarea);
        }
        return true;
    } catch {
        // clipboard access denied/unsupported — caller's button simply won't flip to "Copied!"
        return false;
    }
};

const copied = ref(false);
const copyLink = async () => {
    if (! magicLink.value) return;
    if (! await copyToClipboard(magicLink.value)) return;
    copied.value = true;
    setTimeout(() => { copied.value = false; }, 2000);
};

const copiedPassword = ref(false);
const copyPassword = async () => {
    if (! temporaryPassword.value) return;
    if (! await copyToClipboard(temporaryPassword.value.password)) return;
    copiedPassword.value = true;
    setTimeout(() => { copiedPassword.value = false; }, 2000);
};

const rotateForm = useForm({});
const rotateKey = () => {
    if (! confirm('Rotate the magic link key? Every previously generated SDS login link will stop working immediately.')) return;
    rotateForm.post(route('magic-link-secret.rotate'));
};

const rotatedAtLabel = computed(() => {
    if (! props.magicLinkSecretRotatedAt) return 'Never rotated';
    return new Date(props.magicLinkSecretRotatedAt).toLocaleString();
});

const twoFactorForm = useForm({});
const toggleTwoFactor = () => {
    const message = props.twoFactorEnforced
        ? 'Make two-factor authentication optional for all users? Anyone without it enabled will be able to sign in without setting it up.'
        : 'Make two-factor authentication mandatory for all users again? Anyone without it enabled will be forced to set it up on next login.';
    if (! confirm(message)) return;
    twoFactorForm.post(route('two-factor-setting.toggle'));
};

const forceResetForm = useForm({});
const forceResetAll = () => {
    if (! confirm('Force every user to change their password on next login? Use this if you suspect an account or credentials have been compromised.')) return;
    forceResetForm.post(route('password-policy.force-reset-all'));
};

const forceResetAtLabel = computed(() => {
    if (! props.passwordForceResetAt) return 'Never triggered';
    return new Date(props.passwordForceResetAt).toLocaleString();
});

const emailVerificationForm = useForm({});
const toggleEmailVerification = () => {
    const message = props.emailVerificationEnforced
        ? 'Make email verification optional? Users will be able to access the app without verifying their email address.'
        : 'Require email verification again? Anyone without a verified email will be blocked until they verify it.';
    if (! confirm(message)) return;
    emailVerificationForm.post(route('email-verification-setting.toggle'));
};

const asdsAvailabilityForm = useForm({});
const toggleAsdsAvailability = () => {
    const message = props.asdsAvailable
        ? 'Mark ASDS as unavailable? Outgoing documents will skip ASDS review entirely and go straight from the division chief to the SDS until this is turned back on.'
        : 'Mark ASDS as available again? Outgoing documents will go through ASDS review as normal.';
    if (! confirm(message)) return;
    asdsAvailabilityForm.post(route('asds-availability-setting.toggle'));
};

const originatorUploadForm = useForm({});
const toggleOriginatorUpload = () => {
    const message = props.allOriginatorsCanUpload
        ? 'Restrict outgoing document creation to KMT Focal again? Staff / Originators who are not KMT Focal will no longer be able to create outgoing documents.'
        : 'Allow every Staff / Originator to create outgoing documents, even if they are not KMT Focal?';
    if (! confirm(message)) return;
    originatorUploadForm.post(route('originator-upload-setting.toggle'));
};

const search = ref(props.search ?? '');

let searchDebounce = null;
watch(search, (value) => {
    clearTimeout(searchDebounce);
    searchDebounce = setTimeout(() => {
        router.get(
            route('users.index'),
            { search: value || undefined },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    }, 350);
});

const clearSearch = () => { search.value = ''; };
</script>

<template>
    <Head title="Users" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <h2 class="text-xl font-semibold leading-tight text-gray-800">Users</h2>
                <Link
                    :href="route('users.create')"
                    class="inline-flex items-center rounded-lg bg-blue-700 px-4 py-2 text-sm font-medium text-white hover:bg-blue-800 focus:outline-none focus:ring-4 focus:ring-blue-300"
                >
                    + New User
                </Link>
            </div>
        </template>

        <div class="py-8">
            <div class="mx-auto max-w-7xl sm:px-6 lg:px-8 space-y-4">

                <!-- ── Magic link banner ──────────────────────────────────── -->
                <div v-if="magicLink" class="flex items-start gap-3 rounded-lg border border-indigo-200 bg-indigo-50 p-4">
                    <svg class="mt-0.5 h-5 w-5 shrink-0 text-indigo-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"/>
                    </svg>
                    <div class="flex-1 min-w-0">
                        <p class="text-sm font-semibold text-indigo-800">Login Link Generated</p>
                        <div class="mt-1 flex items-center gap-2">
                            <p class="min-w-0 flex-1 break-all rounded bg-white px-2 py-1.5 font-mono text-xs text-indigo-700 border border-indigo-200">{{ magicLink }}</p>
                            <button
                                type="button"
                                class="shrink-0 rounded-lg border border-indigo-300 bg-white px-3 py-1.5 text-xs font-medium text-indigo-700 hover:bg-indigo-100"
                                @click="copyLink"
                            >
                                {{ copied ? 'Copied!' : 'Copy' }}
                            </button>
                        </div>
                    </div>
                </div>

                <!-- ── Temporary password banner ──────────────────────────── -->
                <div v-if="temporaryPassword" class="flex items-start gap-3 rounded-lg border border-amber-200 bg-amber-50 p-4">
                    <svg class="mt-0.5 h-5 w-5 shrink-0 text-amber-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z"/>
                    </svg>
                    <div class="flex-1 min-w-0">
                        <p class="text-sm font-semibold text-amber-800">Temporary Password for {{ temporaryPassword.user }}</p>
                        <div class="mt-1 flex items-center gap-2">
                            <p class="min-w-0 flex-1 break-all rounded bg-white px-2 py-1.5 font-mono text-xs text-amber-700 border border-amber-200">{{ temporaryPassword.password }}</p>
                            <button
                                type="button"
                                class="shrink-0 rounded-lg border border-amber-300 bg-white px-3 py-1.5 text-xs font-medium text-amber-700 hover:bg-amber-100"
                                @click="copyPassword"
                            >
                                {{ copiedPassword ? 'Copied!' : 'Copy' }}
                            </button>
                        </div>
                        <p class="mt-1 text-xs text-amber-600">Share this with {{ temporaryPassword.user }} securely — it won't be shown again. They'll be required to change it on next login.</p>
                    </div>
                </div>

                <!-- ── Magic link security ────────────────────────────────── -->
                <div class="flex items-center justify-between gap-4 rounded-lg border border-gray-200 bg-white p-4 shadow-sm">
                    <div>
                        <p class="text-sm font-semibold text-gray-800">Magic Link Security</p>
                        <p class="mt-1 text-xs text-gray-500">Last rotated: {{ rotatedAtLabel }}</p>
                    </div>
                    <button
                        type="button"
                        :disabled="rotateForm.processing"
                        class="shrink-0 rounded-lg border border-red-300 bg-white px-3 py-1.5 text-xs font-medium text-red-700 hover:bg-red-50 disabled:opacity-50"
                        @click="rotateKey"
                    >Rotate Key</button>
                </div>

                <!-- ── Two-factor enforcement ─────────────────────────────── -->
                <div class="flex items-center justify-between gap-4 rounded-lg border border-gray-200 bg-white p-4 shadow-sm">
                    <div>
                        <p class="text-sm font-semibold text-gray-800">Two-Factor Authentication</p>
                        <p class="mt-1 text-xs text-gray-500">
                            <span v-if="twoFactorEnforced">Mandatory for all users.</span>
                            <span v-else>Optional — not currently enforced.</span>
                        </p>
                    </div>
                    <button
                        type="button"
                        :disabled="twoFactorForm.processing"
                        class="shrink-0 rounded-lg border px-3 py-1.5 text-xs font-medium disabled:opacity-50"
                        :class="twoFactorEnforced
                            ? 'border-red-300 bg-white text-red-700 hover:bg-red-50'
                            : 'border-green-300 bg-white text-green-700 hover:bg-green-50'"
                        @click="toggleTwoFactor"
                    >{{ twoFactorEnforced ? 'Make Optional' : 'Make Mandatory' }}</button>
                </div>

                <!-- ── Force password reset ───────────────────────────────── -->
                <div class="flex items-center justify-between gap-4 rounded-lg border border-gray-200 bg-white p-4 shadow-sm">
                    <div>
                        <p class="text-sm font-semibold text-gray-800">Password Reset</p>
                        <p class="mt-1 text-xs text-gray-500">Last forced: {{ forceResetAtLabel }}</p>
                    </div>
                    <button
                        type="button"
                        :disabled="forceResetForm.processing"
                        class="shrink-0 rounded-lg border border-red-300 bg-white px-3 py-1.5 text-xs font-medium text-red-700 hover:bg-red-50 disabled:opacity-50"
                        @click="forceResetAll"
                    >Force Password Reset for All Users</button>
                </div>

                <!-- ── Email verification enforcement ─────────────────────── -->
                <div class="flex items-center justify-between gap-4 rounded-lg border border-gray-200 bg-white p-4 shadow-sm">
                    <div>
                        <p class="text-sm font-semibold text-gray-800">Email Verification</p>
                        <p class="mt-1 text-xs text-gray-500">
                            <span v-if="emailVerificationEnforced">Required for all users.</span>
                            <span v-else>Optional — not currently enforced.</span>
                        </p>
                    </div>
                    <button
                        type="button"
                        :disabled="emailVerificationForm.processing"
                        class="shrink-0 rounded-lg border px-3 py-1.5 text-xs font-medium disabled:opacity-50"
                        :class="emailVerificationEnforced
                            ? 'border-red-300 bg-white text-red-700 hover:bg-red-50'
                            : 'border-green-300 bg-white text-green-700 hover:bg-green-50'"
                        @click="toggleEmailVerification"
                    >{{ emailVerificationEnforced ? 'Make Optional' : 'Make Required' }}</button>
                </div>

                <!-- ── ASDS availability ────────────────────────────────────── -->
                <div class="flex items-center justify-between gap-4 rounded-lg border border-gray-200 bg-white p-4 shadow-sm">
                    <div>
                        <p class="text-sm font-semibold text-gray-800">ASDS Availability</p>
                        <p class="mt-1 text-xs text-gray-500">
                            <span v-if="asdsAvailable">Outgoing documents go through ASDS review as normal.</span>
                            <span v-else>ASDS marked unavailable — outgoing documents skip ASDS and go straight to the SDS.</span>
                        </p>
                    </div>
                    <button
                        type="button"
                        :disabled="asdsAvailabilityForm.processing"
                        class="shrink-0 rounded-lg border px-3 py-1.5 text-xs font-medium disabled:opacity-50"
                        :class="asdsAvailable
                            ? 'border-red-300 bg-white text-red-700 hover:bg-red-50'
                            : 'border-green-300 bg-white text-green-700 hover:bg-green-50'"
                        @click="toggleAsdsAvailability"
                    >{{ asdsAvailable ? 'Mark Unavailable' : 'Mark Available' }}</button>
                </div>

                <!-- ── Outgoing uploads by originators ───────────────────── -->
                <div class="flex items-center justify-between gap-4 rounded-lg border border-gray-200 bg-white p-4 shadow-sm">
                    <div>
                        <p class="text-sm font-semibold text-gray-800">Outgoing Uploads by Staff / Originators</p>
                        <p class="mt-1 text-xs text-gray-500">
                            <span v-if="allOriginatorsCanUpload">Open to all — every Staff / Originator can create outgoing documents, KMT Focal or not.</span>
                            <span v-else>KMT Focal only — a Staff / Originator needs the KMT Focal tag to create outgoing documents.</span>
                        </p>
                    </div>
                    <button
                        type="button"
                        :disabled="originatorUploadForm.processing"
                        class="shrink-0 rounded-lg border px-3 py-1.5 text-xs font-medium disabled:opacity-50"
                        :class="allOriginatorsCanUpload
                            ? 'border-red-300 bg-white text-red-700 hover:bg-red-50'
                            : 'border-green-300 bg-white text-green-700 hover:bg-green-50'"
                        @click="toggleOriginatorUpload"
                    >{{ allOriginatorsCanUpload ? 'Restrict to KMT Focal' : 'Allow All Originators' }}</button>
                </div>

                <!-- ── Search ────────────────────────────────────────────────── -->
                <div class="relative">
                    <svg class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-4.35-4.35M17 10a7 7 0 11-14 0 7 7 0 0114 0z"/>
                    </svg>
                    <input
                        v-model="search"
                        type="search"
                        placeholder="Search by name or email…"
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
                                <th class="px-6 py-3">Email</th>
                                <th class="px-6 py-3">Role</th>
                                <th class="px-6 py-3">Division / Unit</th>
                                <th class="px-6 py-3">Status</th>
                                <th class="px-6 py-3"><span class="sr-only">Actions</span></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <tr v-if="users.data.length === 0">
                                <td colspan="6" class="px-6 py-12 text-center text-gray-400">
                                    {{ search ? `No users match "${search}".` : 'No users found.' }}
                                </td>
                            </tr>
                            <tr v-for="u in users.data" :key="u.id" class="hover:bg-gray-50">
                                <td class="px-6 py-4 font-medium text-gray-900">
                                    {{ u.name }}
                                    <span v-if="u.id === authId" class="ms-1 text-xs text-gray-400">(you)</span>
                                </td>
                                <td class="px-6 py-4 text-gray-600">{{ u.email }}</td>
                                <td class="px-6 py-4">
                                    <div class="flex flex-wrap items-center gap-1">
                                        <span
                                            v-if="u.role"
                                            class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium"
                                            :class="ROLE_CLASS[u.role] ?? 'bg-gray-100 text-gray-700'"
                                        >{{ roleLabels[u.role] ?? u.role }}</span>
                                        <span v-else class="text-gray-400">—</span>
                                        <span
                                            v-if="u.kmtFocal"
                                            class="inline-flex items-center rounded-full bg-teal-100 px-2.5 py-0.5 text-xs font-medium text-teal-800"
                                            title="Additional role — may also create outgoing documents"
                                        >KMT Focal</span>
                                    </div>
                                </td>
                                <td class="px-6 py-4">
                                    <template v-if="u.division">
                                        <span class="text-gray-900">{{ u.division.name }}</span>
                                        <span v-if="u.division.parent" class="ms-1 text-xs text-gray-400">({{ u.division.parent }})</span>
                                    </template>
                                    <span v-else class="text-gray-400">—</span>
                                </td>
                                <td class="px-6 py-4">
                                    <div class="flex flex-wrap items-center gap-1.5">
                                        <span
                                            class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium"
                                            :class="u.isActive ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-700'"
                                        >{{ u.isActive ? 'Active' : 'Inactive' }}</span>
                                        <span
                                            v-if="u.role !== 'sds' && u.role !== 'admin' && u.canLoginWeekends"
                                            class="inline-flex items-center rounded-full bg-sky-100 px-2.5 py-0.5 text-xs font-medium text-sky-800"
                                            title="Can log in on weekends"
                                        >Weekends OK</span>
                                    </div>
                                </td>
                                <td class="px-6 py-4">
                                    <div class="flex items-center gap-4">
                                        <Link
                                            :href="route('users.edit', u.id)"
                                            class="text-sm font-medium text-blue-600 hover:underline"
                                        >Edit</Link>
                                        <button
                                            v-if="u.role === 'sds' || u.role === 'asds'"
                                            type="button"
                                            :disabled="getMagicLinkForm(u.id).processing"
                                            class="text-sm font-medium text-indigo-600 hover:underline disabled:opacity-50"
                                            @click="generateLink(u.id)"
                                        >Login Link</button>
                                        <button
                                            type="button"
                                            :disabled="getResetPasswordForm(u.id).processing"
                                            class="text-sm font-medium text-amber-600 hover:underline disabled:opacity-50"
                                            @click="resetPassword(u.id, u.name)"
                                        >Reset Password</button>
                                        <button
                                            v-if="u.id !== authId"
                                            type="button"
                                            :disabled="getToggleActiveForm(u.id).processing"
                                            class="text-sm font-medium hover:underline disabled:opacity-50"
                                            :class="u.isActive ? 'text-gray-600' : 'text-green-600'"
                                            @click="toggleActive(u.id, u.name, u.isActive)"
                                        >{{ u.isActive ? 'Deactivate' : 'Activate' }}</button>
                                        <button
                                            v-if="u.role !== 'sds' && u.role !== 'admin'"
                                            type="button"
                                            :disabled="getToggleWeekendAccessForm(u.id).processing"
                                            class="text-sm font-medium hover:underline disabled:opacity-50"
                                            :class="u.canLoginWeekends ? 'text-gray-600' : 'text-sky-600'"
                                            @click="toggleWeekendAccess(u.id, u.name, u.canLoginWeekends)"
                                        >{{ u.canLoginWeekends ? 'Revoke Weekends' : 'Allow Weekends' }}</button>
                                        <button
                                            v-if="u.id !== authId"
                                            type="button"
                                            class="text-sm font-medium text-red-600 hover:underline"
                                            @click="deleteUser(u.id, u.name)"
                                        >Delete</button>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination -->
                <div v-if="users.last_page > 1" class="flex items-center justify-center gap-1">
                    <template v-for="link in users.links" :key="link.label">
                        <Link
                            v-if="link.url"
                            :href="link.url"
                            class="min-w-[2rem] rounded border px-3 py-1.5 text-center text-sm"
                            :class="link.active
                                ? 'border-blue-600 bg-blue-600 text-white'
                                : 'border-gray-300 bg-white text-gray-700 hover:bg-gray-50'"
                            v-html="link.label"
                        />
                        <span
                            v-else
                            class="min-w-[2rem] rounded border border-gray-200 bg-gray-50 px-3 py-1.5 text-center text-sm text-gray-400"
                            v-html="link.label"
                        />
                    </template>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
