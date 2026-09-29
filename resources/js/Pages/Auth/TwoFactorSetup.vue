<script setup>
import GuestLayout from '@/Layouts/GuestLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps({
    qrCodeSvg: { type: String, required: true },
    secretKey: { type: String, required: true },
    recoveryCodes: { type: Array, required: true },
});

const showSecret = ref(false);
const showRecoveryCodes = ref(false);

const form = useForm({ code: '' });

const submit = () => {
    form.post(route('two-factor.enroll'), {
        onError: () => form.reset('code'),
    });
};
</script>

<template>
    <GuestLayout>
        <Head title="Enable Two-Factor Authentication" />

        <div class="mb-6">
            <h2 class="text-lg font-semibold text-gray-900">
                Enable Two-Factor Authentication
            </h2>
            <p class="mt-1 text-sm text-gray-600">
                Two-factor authentication is <strong>required</strong> for all accounts.
                Scan the QR code below with your authenticator app (e.g. Google Authenticator, Authy), then enter the code it generates.
            </p>
        </div>

        <!-- QR Code -->
        <div class="mb-4 flex justify-center rounded-lg border border-gray-200 bg-white p-4">
            <div v-html="qrCodeSvg" />
        </div>

        <!-- Manual entry toggle -->
        <div class="mb-4 text-center">
            <button
                type="button"
                class="text-sm text-gray-600 underline hover:text-gray-900 focus:outline-none"
                @click="showSecret = !showSecret"
            >
                {{ showSecret ? 'Hide' : 'Cannot scan? Enter code manually' }}
            </button>
        </div>

        <div v-if="showSecret" class="mb-4 rounded-lg bg-gray-50 px-4 py-3 font-mono text-sm tracking-widest text-gray-800">
            {{ secretKey }}
        </div>

        <!-- Confirmation form -->
        <form @submit.prevent="submit">
            <InputLabel for="code" value="Authenticator Code" />
            <TextInput
                id="code"
                v-model="form.code"
                type="text"
                inputmode="numeric"
                autocomplete="one-time-code"
                class="mt-1 block w-full"
                autofocus
            />
            <InputError class="mt-2" :message="form.errors.code" />

            <PrimaryButton
                class="mt-4 w-full justify-center"
                :class="{ 'opacity-25': form.processing }"
                :disabled="form.processing"
            >
                Confirm and Enable 2FA
            </PrimaryButton>
        </form>

        <!-- Recovery codes (shown after scanning, before confirmation) -->
        <div class="mt-6 border-t border-gray-200 pt-4">
            <button
                type="button"
                class="text-sm text-gray-600 underline hover:text-gray-900 focus:outline-none"
                @click="showRecoveryCodes = !showRecoveryCodes"
            >
                {{ showRecoveryCodes ? 'Hide' : 'View' }} your recovery codes
            </button>
            <p class="mt-1 text-xs text-gray-500">
                Save these codes somewhere safe — they let you regain access if you lose your device.
            </p>
            <ul v-if="showRecoveryCodes" class="mt-3 grid grid-cols-2 gap-1 font-mono text-sm text-gray-800">
                <li v-for="code in recoveryCodes" :key="code" class="rounded bg-gray-100 px-2 py-1">
                    {{ code }}
                </li>
            </ul>
        </div>
    </GuestLayout>
</template>
