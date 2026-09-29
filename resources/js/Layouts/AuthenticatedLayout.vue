<script setup>
import { ref, computed } from 'vue';
import ApplicationLogo from '@/Components/ApplicationLogo.vue';
import Dropdown from '@/Components/Dropdown.vue';
import DropdownLink from '@/Components/DropdownLink.vue';
import NavLink from '@/Components/NavLink.vue';
import NotificationBell from '@/Components/NotificationBell.vue';
import ResponsiveNavLink from '@/Components/ResponsiveNavLink.vue';
import { Link, usePage } from '@inertiajs/vue3';

const page = usePage();
const flash = computed(() => page.props.flash);
const userRoles = computed(() => page.props.auth.user?.roles ?? []);
const hasRole = (...roles) => roles.some(r => userRoles.value.includes(r));

const showingNavigationDropdown = ref(false);
</script>

<template>
    <div>
        <div class="min-h-screen bg-gray-100">
            <nav
                class="border-b border-gray-100 bg-white print:hidden"
            >
                <!-- Primary Navigation Menu -->
                <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                    <div class="flex h-16 justify-between">
                        <div class="flex">
                            <!-- Logo -->
                            <div class="flex shrink-0 items-center">
                                <Link :href="route('dashboard')" class="flex items-center gap-2">
                                    <ApplicationLogo class="block h-9 w-auto" />
                                    <span class="hidden font-bold text-[#003087] sm:inline">DepEd DART</span>
                                </Link>
                            </div>

                            <!-- Navigation Links -->
                            <div
                                class="hidden space-x-8 sm:-my-px sm:ms-10 sm:flex"
                            >
                                <NavLink
                                    :href="route('dashboard')"
                                    :active="route().current('dashboard')"
                                >
                                    Dashboard
                                </NavLink>
                                <NavLink
                                    :href="route('documents.index')"
                                    :active="route().current('documents.*')"
                                >
                                    Documents
                                </NavLink>
                                <NavLink
                                    v-if="page.props.auth.canUploadSignature"
                                    :href="route('my-signature.show')"
                                    :active="route().current('my-signature.*')"
                                >
                                    My Signature
                                </NavLink>
                                <NavLink
                                    v-if="hasRole('admin')"
                                    :href="route('signing-credentials.index')"
                                    :active="route().current('signing-credentials.*')"
                                >
                                    Sig. Credentials
                                </NavLink>
                                <NavLink
                                    v-if="hasRole('admin')"
                                    :href="route('users.index')"
                                    :active="route().current('users.*')"
                                >
                                    Users
                                </NavLink>
                                <NavLink
                                    v-if="hasRole('admin')"
                                    :href="route('divisions.index')"
                                    :active="route().current('divisions.*')"
                                >
                                    Offices
                                </NavLink>
                            </div>
                        </div>

                        <div class="flex items-center">
                            <NotificationBell />

                            <div class="hidden sm:ms-3 sm:flex sm:items-center">
                            <!-- Settings Dropdown -->
                            <div class="relative ms-3">
                                <Dropdown align="right" width="48">
                                    <template #trigger>
                                        <span class="inline-flex rounded-md">
                                            <button
                                                type="button"
                                                class="inline-flex items-center rounded-md border border-transparent bg-white px-3 py-2 text-sm font-medium leading-4 text-gray-500 transition duration-150 ease-in-out hover:text-gray-700 focus:outline-none"
                                            >
                                                {{ $page.props.auth.user.name }}

                                                <svg
                                                    class="-me-0.5 ms-2 h-4 w-4"
                                                    xmlns="http://www.w3.org/2000/svg"
                                                    viewBox="0 0 20 20"
                                                    fill="currentColor"
                                                >
                                                    <path
                                                        fill-rule="evenodd"
                                                        d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z"
                                                        clip-rule="evenodd"
                                                    />
                                                </svg>
                                            </button>
                                        </span>
                                    </template>

                                    <template #content>
                                        <DropdownLink
                                            :href="route('profile.edit')"
                                        >
                                            Profile
                                        </DropdownLink>
                                        <DropdownLink
                                            :href="route('logout')"
                                            method="post"
                                            as="button"
                                        >
                                            Log Out
                                        </DropdownLink>
                                    </template>
                                </Dropdown>
                            </div>
                            </div>
                        </div>

                        <!-- Hamburger -->
                        <div class="-me-2 flex items-center sm:hidden">
                            <button
                                @click="
                                    showingNavigationDropdown =
                                        !showingNavigationDropdown
                                "
                                class="inline-flex items-center justify-center rounded-md p-2 text-gray-400 transition duration-150 ease-in-out hover:bg-gray-100 hover:text-gray-500 focus:bg-gray-100 focus:text-gray-500 focus:outline-none"
                            >
                                <svg
                                    class="h-6 w-6"
                                    stroke="currentColor"
                                    fill="none"
                                    viewBox="0 0 24 24"
                                >
                                    <path
                                        :class="{
                                            hidden: showingNavigationDropdown,
                                            'inline-flex':
                                                !showingNavigationDropdown,
                                        }"
                                        stroke-linecap="round"
                                        stroke-linejoin="round"
                                        stroke-width="2"
                                        d="M4 6h16M4 12h16M4 18h16"
                                    />
                                    <path
                                        :class="{
                                            hidden: !showingNavigationDropdown,
                                            'inline-flex':
                                                showingNavigationDropdown,
                                        }"
                                        stroke-linecap="round"
                                        stroke-linejoin="round"
                                        stroke-width="2"
                                        d="M6 18L18 6M6 6l12 12"
                                    />
                                </svg>
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Responsive Navigation Menu -->
                <div
                    :class="{
                        block: showingNavigationDropdown,
                        hidden: !showingNavigationDropdown,
                    }"
                    class="sm:hidden"
                >
                    <div class="space-y-1 pb-3 pt-2">
                        <ResponsiveNavLink
                            :href="route('dashboard')"
                            :active="route().current('dashboard')"
                        >
                            Dashboard
                        </ResponsiveNavLink>
                        <ResponsiveNavLink
                            :href="route('documents.index')"
                            :active="route().current('documents.*')"
                        >
                            Documents
                        </ResponsiveNavLink>
                        <ResponsiveNavLink
                            v-if="page.props.auth.canUploadSignature"
                            :href="route('my-signature.show')"
                            :active="route().current('my-signature.*')"
                        >
                            My Signature
                        </ResponsiveNavLink>
                        <ResponsiveNavLink
                            v-if="hasRole('admin')"
                            :href="route('signing-credentials.index')"
                            :active="route().current('signing-credentials.*')"
                        >
                            Sig. Credentials
                        </ResponsiveNavLink>
                        <ResponsiveNavLink
                            v-if="hasRole('admin')"
                            :href="route('users.index')"
                            :active="route().current('users.*')"
                        >
                            Users
                        </ResponsiveNavLink>
                        <ResponsiveNavLink
                            v-if="hasRole('admin')"
                            :href="route('divisions.index')"
                            :active="route().current('divisions.*')"
                        >
                            Offices
                        </ResponsiveNavLink>
                    </div>

                    <!-- Responsive Settings Options -->
                    <div
                        class="border-t border-gray-200 pb-1 pt-4"
                    >
                        <div class="px-4">
                            <div
                                class="text-base font-medium text-gray-800"
                            >
                                {{ $page.props.auth.user.name }}
                            </div>
                            <div class="text-sm font-medium text-gray-500">
                                {{ $page.props.auth.user.email }}
                            </div>
                        </div>

                        <div class="mt-3 space-y-1">
                            <ResponsiveNavLink :href="route('profile.edit')">
                                Profile
                            </ResponsiveNavLink>
                            <ResponsiveNavLink
                                :href="route('logout')"
                                method="post"
                                as="button"
                            >
                                Log Out
                            </ResponsiveNavLink>
                        </div>
                    </div>
                </div>
            </nav>

            <!-- Page Heading -->
            <header
                class="bg-white shadow print:hidden"
                v-if="$slots.header"
            >
                <div class="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8">
                    <slot name="header" />
                </div>
            </header>

            <!-- Flash messages -->
            <div v-if="flash?.success || flash?.warning || flash?.error" class="mx-auto max-w-7xl px-4 pt-4 sm:px-6 lg:px-8 print:hidden">
                <div v-if="flash?.success" class="mb-2 flex items-center rounded-lg border border-green-200 bg-green-50 p-4 text-sm text-green-800">
                    <svg class="me-3 inline h-4 w-4 shrink-0" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
                    {{ flash.success }}
                </div>
                <div v-if="flash?.warning" class="mb-2 flex items-center rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800">
                    <svg class="me-3 inline h-4 w-4 shrink-0" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l6.28 11.19c.75 1.334-.213 2.987-1.744 2.987H3.721c-1.531 0-2.494-1.653-1.745-2.987l6.281-11.19zM10 6a1 1 0 011 1v3a1 1 0 11-2 0V7a1 1 0 011-1zm0 8a1 1 0 100-2 1 1 0 000 2z" clip-rule="evenodd"/></svg>
                    {{ flash.warning }}
                </div>
                <div v-if="flash?.error" class="mb-2 flex items-center rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-800">
                    <svg class="me-3 inline h-4 w-4 shrink-0" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"/></svg>
                    {{ flash.error }}
                </div>
            </div>

            <!-- Page Content -->
            <main>
                <slot />
            </main>
        </div>
    </div>
</template>
