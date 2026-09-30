<script setup>
import { Head } from '@inertiajs/vue3';

defineProps({
    userName: String,
});

const today = new Date().toLocaleDateString('en-PH', {
    weekday: 'long', year: 'numeric', month: 'long', day: 'numeric',
});
</script>

<template>
    <Head title="System Unavailable" />

    <div class="flex min-h-screen flex-col items-center justify-center bg-gradient-to-br from-[#1a2e5a] to-[#0e1d3d] px-4">

        <!-- Logos -->
        <div class="mb-8 flex items-center gap-4">
            <div class="rounded-full bg-white p-2">
                <img src="/images/office-seal.png" alt="Schools Division Office Seal" class="h-14 w-14 object-contain" />
            </div>
            <div class="rounded-full bg-white p-2">
                <img src="/images/dart-logo.png" alt="DepEd DART" class="h-14 w-14 object-contain" />
            </div>
        </div>

        <!-- Card -->
        <div class="w-full max-w-md rounded-2xl bg-white/10 backdrop-blur-sm border border-white/20 px-8 py-10 text-center shadow-2xl">

            <!-- Icon -->
            <div class="mx-auto mb-5 flex h-16 w-16 items-center justify-center rounded-full bg-amber-400/20 border border-amber-400/40">
                <svg class="h-8 w-8 text-amber-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z"/>
                </svg>
            </div>

            <h1 class="text-2xl font-bold text-white">System Unavailable</h1>

            <p v-if="userName" class="mt-2 text-sm text-white/70">
                Hello, <span class="font-medium text-white">{{ userName }}</span>.
            </p>

            <p class="mt-4 text-sm leading-relaxed text-white/80">
                Access to <span class="font-semibold text-white">DepEd DART</span> is restricted on
                weekends. Please return on a weekday.
            </p>

            <p class="mt-3 text-xs text-white/50">{{ today }}</p>

        </div>

        <!-- App name -->
        <p class="mt-8 text-xs font-medium tracking-widest text-white/30 uppercase">
            DepEd DART &mdash; Document Approval and Routing Tool
        </p>

    </div>
</template>
