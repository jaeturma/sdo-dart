<script setup>
import { Link } from '@inertiajs/vue3';
</script>

<template>
    <div class="flex min-h-screen flex-col items-center justify-center bg-gradient-to-br from-[#001F5B] to-[#003087] px-4 py-12">

        <!-- Logos + System Identity -->
        <div class="mb-8 flex flex-col items-center gap-4 text-center">
            <div class="flex items-center gap-4">
                <Link href="/">
                    <div class="rounded-full bg-white p-1 shadow-lg">
                        <img src="/images/deped-xi-seal.png" class="h-16 w-16" alt="DepEd Region XI" />
                    </div>
                </Link>
                <div class="h-14 w-px bg-white/30"></div>
                <Link href="/">
                    <div class="rounded-full bg-white p-1 shadow-lg">
                        <img src="/images/dart-logo.png" class="h-16 w-16" alt="DepEd DART" />
                    </div>
                </Link>
            </div>

            <div class="leading-tight">
                <p class="text-xs font-medium text-white/60">Republic of the Philippines | Department of Education</p>
                <h1 class="mt-1 text-2xl font-extrabold tracking-wide text-white">DepEd DART</h1>
                <p class="text-sm text-white/70">Document Approval and Routing Tool</p>
                <p class="text-xs text-white/50">Regional Office XI — Davao Region</p>
            </div>
        </div>

        <!-- Form Card -->
        <div class="w-full max-w-md rounded-2xl bg-white px-8 py-6 shadow-xl">
            <slot />
        </div>

        <!-- Footer -->
        <p class="mt-8 text-xs text-white/40">
            © 2026 Department of Education | Regional Office XI
        </p>
    </div>
</template>
