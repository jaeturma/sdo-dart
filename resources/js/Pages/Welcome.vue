<script setup>
import { Head, Link } from '@inertiajs/vue3';

defineProps({
    canLogin:    Boolean,
    canRegister: Boolean,
});
</script>

<template>
    <Head title="DepEd DART" />

    <div class="flex min-h-screen flex-col">

        <!-- ── Hero (navy gradient) ─────────────────────────────────── -->
        <div class="flex flex-1 flex-col bg-gradient-to-br from-[#001F5B] to-[#003087]">

            <!-- Nav bar -->
            <header class="flex items-center justify-between px-6 py-4 lg:px-12">
                <div class="flex items-center gap-3">
                    <div class="shrink-0 rounded-full bg-white p-0.5">
                        <img src="/images/deped-xi-seal.png" class="h-10 w-10" alt="Schools Division Office Seal" />
                    </div>
                    <div class="leading-tight text-white">
                        <p class="text-xs font-medium opacity-70">Republic of the Philippines</p>
                        <p class="text-sm font-semibold">Department of Education</p>
                        <p class="text-xs opacity-60">[Your Schools Division Office]</p>
                    </div>
                </div>

                <nav v-if="canLogin">
                    <Link
                        v-if="$page.props.auth.user"
                        :href="route('dashboard')"
                        class="rounded-full bg-white px-5 py-2 text-sm font-semibold text-[#003087] shadow transition-colors hover:bg-gray-100"
                    >
                        Dashboard
                    </Link>
                    <Link
                        v-else
                        :href="route('login')"
                        class="rounded-full bg-white px-5 py-2 text-sm font-semibold text-[#003087] shadow transition-colors hover:bg-gray-100"
                    >
                        Sign In
                    </Link>
                </nav>
            </header>

            <!-- Hero content -->
            <div class="flex flex-1 flex-col items-center justify-center px-6 py-16 text-center">
                <div class="rounded-full bg-white p-3 shadow-2xl">
                    <img
                        src="/images/dart-logo.png"
                        alt="DepEd DART Logo"
                        class="h-44 w-44"
                    />
                </div>

                <h1 class="mt-8 text-4xl font-extrabold tracking-tight text-white sm:text-5xl">
                    DepEd <span class="text-yellow-300">DART</span>
                </h1>
                <p class="mt-2 text-lg font-medium text-white/80">Document Approval and Routing Tool</p>
                <p class="text-sm text-white/60">for [Your Schools Division Office]</p>

                <p class="mt-6 max-w-md text-sm leading-relaxed text-white/70">
                    Digitize, track, and securely approve documents from initial submission
                    through division review, SDS sign-off, and digital signature.
                </p>

                <Link
                    v-if="canLogin && !$page.props.auth.user"
                    :href="route('login')"
                    class="mt-8 inline-flex items-center gap-2 rounded-full bg-white px-8 py-3 text-sm font-bold text-[#003087] shadow-lg transition-colors hover:bg-gray-100 focus:outline-none focus:ring-2 focus:ring-white/50"
                >
                    Sign In
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5 21 12m0 0-7.5 7.5M21 12H3" />
                    </svg>
                </Link>
                <Link
                    v-else-if="canLogin && $page.props.auth.user"
                    :href="route('dashboard')"
                    class="mt-8 inline-flex items-center gap-2 rounded-full bg-white px-8 py-3 text-sm font-bold text-[#003087] shadow-lg transition-colors hover:bg-gray-100"
                >
                    Go to Dashboard
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5 21 12m0 0-7.5 7.5M21 12H3" />
                    </svg>
                </Link>
            </div>
        </div>

        <!-- ── Feature Cards ───────────────────────────────────────── -->
        <section class="bg-gray-50 px-6 py-16 lg:px-12">
            <div class="mx-auto max-w-5xl">
                <h2 class="mb-10 text-center text-2xl font-bold text-gray-800">How DART Works</h2>

                <div class="grid gap-6 sm:grid-cols-3">

                    <!-- Submit & Track -->
                    <div class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-200">
                        <div class="mb-4 flex h-12 w-12 items-center justify-center rounded-full bg-[#003087]/10">
                            <svg class="h-6 w-6 text-[#003087]" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z" />
                            </svg>
                        </div>
                        <h3 class="text-base font-semibold text-gray-900">Submit & Track</h3>
                        <p class="mt-2 text-sm leading-relaxed text-gray-500">
                            Upload outgoing and incoming documents and monitor their status through every stage of the workflow in real time.
                        </p>
                    </div>

                    <!-- Review & Approve -->
                    <div class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-200">
                        <div class="mb-4 flex h-12 w-12 items-center justify-center rounded-full bg-[#003087]/10">
                            <svg class="h-6 w-6 text-[#003087]" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75m-3-7.036A11.959 11.959 0 0 1 3.598 6 11.99 11.99 0 0 0 3 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285Z" />
                            </svg>
                        </div>
                        <h3 class="text-base font-semibold text-gray-900">Review & Approve</h3>
                        <p class="mt-2 text-sm leading-relaxed text-gray-500">
                            Structured review chain from Division Chief through Assistant Schools Division Superintendent to Schools Division Superintendent, with remarks and return-for-revision support.
                        </p>
                    </div>

                    <!-- Route & Sign -->
                    <div class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-200">
                        <div class="mb-4 flex h-12 w-12 items-center justify-center rounded-full bg-[#003087]/10">
                            <svg class="h-6 w-6 text-[#003087]" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931Zm0 0L19.5 7.125M18 14v4.75A2.25 2.25 0 0 1 15.75 21H5.25A2.25 2.25 0 0 1 3 18.75V8.25A2.25 2.25 0 0 1 5.25 6H10" />
                            </svg>
                        </div>
                        <h3 class="text-base font-semibold text-gray-900">Route & Sign</h3>
                        <p class="mt-2 text-sm leading-relaxed text-gray-500">
                            Distribute incoming documents to staff for appropriate action, and apply tamper-evident digital signatures to approved outgoing documents.
                        </p>
                    </div>

                </div>
            </div>
        </section>

        <!-- ── Footer ──────────────────────────────────────────────── -->
        <footer class="bg-[#001F5B] px-6 py-5 text-center text-sm text-white/50">
            © 2026 Department of Education | [Your Schools Division Office]
            <span class="mx-2 opacity-30">·</span>
            DepEd DART v1.0
        </footer>

    </div>
</template>
