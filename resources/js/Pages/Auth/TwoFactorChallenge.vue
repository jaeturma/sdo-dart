<script setup>
import GuestLayout from '@/Layouts/GuestLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

const recovery = ref(false);

const form = useForm({
    code: '',
    recovery_code: '',
});

const toggleRecovery = () => {
    recovery.value = !recovery.value;
    form.reset();
};

const submit = () => {
    form.post(route('two-factor.login'));
};
</script>

<template>
    <GuestLayout>
        <Head title="Two-Factor Authentication" />

        <div class="mb-4 text-sm text-gray-600">
            <template v-if="!recovery">
                Please enter the 6-digit code from your authenticator app to continue.
            </template>
            <template v-else>
                Enter one of your emergency recovery codes to regain access.
            </template>
        </div>

        <form @submit.prevent="submit">
            <div v-if="!recovery">
                <InputLabel for="code" value="Authentication Code" />
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
            </div>

            <div v-else>
                <InputLabel for="recovery_code" value="Recovery Code" />
                <TextInput
                    id="recovery_code"
                    v-model="form.recovery_code"
                    type="text"
                    autocomplete="one-time-code"
                    class="mt-1 block w-full"
                    autofocus
                />
                <InputError class="mt-2" :message="form.errors.recovery_code" />
            </div>

            <div class="mt-4 flex items-center justify-between">
                <button
                    type="button"
                    class="text-sm text-gray-600 underline hover:text-gray-900 focus:outline-none"
                    @click="toggleRecovery"
                >
                    {{ recovery ? 'Use authentication code' : 'Use a recovery code' }}
                </button>

                <PrimaryButton
                    class="ms-4"
                    :class="{ 'opacity-25': form.processing }"
                    :disabled="form.processing"
                >
                    Sign In
                </PrimaryButton>
            </div>
        </form>
    </GuestLayout>
</template>
