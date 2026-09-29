<script setup>
import { Link } from '@inertiajs/vue3';

defineProps({
    twoFactorEnabled: Boolean,
});
</script>

<template>
    <section>
        <header>
            <h2 class="text-lg font-medium text-gray-900">
                Two-Factor Authentication
            </h2>

            <p class="mt-1 text-sm text-gray-600">
                Add an extra layer of security to your account by requiring a
                verification code from your phone in addition to your password.
            </p>
        </header>

        <div class="mt-6">
            <div v-if="twoFactorEnabled" class="flex items-center gap-2">
                <span class="inline-flex items-center rounded-full bg-green-100 px-2.5 py-0.5 text-xs font-medium text-green-800">
                    Enabled
                </span>
                <p class="text-sm text-gray-600">Two-factor authentication is active on your account.</p>
            </div>

            <div v-else class="flex items-center gap-4">
                <span class="inline-flex items-center rounded-full bg-gray-100 px-2.5 py-0.5 text-xs font-medium text-gray-700">
                    Disabled
                </span>
                <Link
                    :href="route('two-factor.setup')"
                    as="button"
                    class="inline-flex items-center rounded-md border border-transparent bg-gray-800 px-4 py-2 text-xs font-semibold uppercase tracking-widest text-white transition duration-150 ease-in-out hover:bg-gray-700 focus:bg-gray-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 active:bg-gray-900"
                >Enable Two-Factor Authentication</Link>
            </div>
        </div>
    </section>
</template>
