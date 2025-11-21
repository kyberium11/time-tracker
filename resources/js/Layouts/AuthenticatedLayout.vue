<script setup lang="ts">
import { ref } from 'vue';
import Dropdown from '@/Components/Dropdown.vue';
import DropdownLink from '@/Components/DropdownLink.vue';
import NavLink from '@/Components/NavLink.vue';
import ResponsiveNavLink from '@/Components/ResponsiveNavLink.vue';
import { Link } from '@inertiajs/vue3';

const showingNavigationDropdown = ref(false);
</script>

<template>
    <div>
        <div class="min-h-screen bg-gray-100">
            <nav
                class="border-b border-gray-100 bg-white"
            >
                <!-- Primary Navigation Menu -->
                <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                    <div class="flex h-16 justify-between">
                        <div class="flex">
                            <!-- Logo -->
                            <div class="flex shrink-0 items-center">
                                <Link :href="route('dashboard')" class="flex items-center">
                                    <span class="text-2xl font-bold bg-gradient-to-r from-indigo-600 to-purple-600 bg-clip-text text-transparent">
                                        Time Tracker
                                    </span>
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
                                    v-if="$page.props.auth.user.role === 'admin' || $page.props.auth.user.role === 'developer'"
                                    :href="route('users.index')"
                                    :active="route().current('users.*')"
                                >
                                    Users
                                </NavLink>
                                
                                <NavLink
                                    v-if="$page.props.auth.user.role === 'admin' || $page.props.auth.user.role === 'developer'"
                                    :href="route('teams.index')"
                                    :active="route().current('teams.*')"
                                >
                                    Teams
                                </NavLink>
                                
                                <NavLink
                                    v-if="$page.props.auth.user.role === 'admin' || $page.props.auth.user.role === 'manager' || $page.props.auth.user.role === 'developer'"
                                    :href="route('analytics.index')"
                                    :active="route().current('analytics.*')"
                                >
                                    Analytics
                                </NavLink>
                                
                                <NavLink
                                    v-if="$page.props.auth.user.role === 'employee' || $page.props.auth.user.role === 'admin' || $page.props.auth.user.role === 'manager' || $page.props.auth.user.role === 'developer'"
                                    :href="route('time-graph.index')"
                                    :active="route().current('time-graph.*')"
                                >
                                    Time Graph
                                </NavLink>
                                
                                <NavLink
                                    v-if="$page.props.auth.user.role === 'admin'"
                                    :href="route('efficiency.index')"
                                    :active="route().current('efficiency.*')"
                                >
                                    Efficiency Analytics
                                </NavLink>
                                
                                <NavLink
                                    v-if="$page.props.auth.user.role === 'developer'"
                                    :href="route('time-entries.index')"
                                    :active="route().current('time-entries.*')"
                                >
                                    Time Entries
                                </NavLink>
                                
                                <NavLink
                                    v-if="$page.props.auth.user.role === 'developer'"
                                    :href="route('deploy.index')"
                                    :active="route().current('deploy.*')"
                                >
                                    Deploy
                                </NavLink>
                                
                                <NavLink
                                    v-if="$page.props.auth.user.role === 'admin' || $page.props.auth.user.role === 'developer'"
                                    :href="route('impersonate.index')"
                                    :active="route().current('impersonate.*')"
                                >
                                    Impersonate
                                </NavLink>
                            </div>
                        </div>

                        <div class="hidden sm:ms-6 sm:flex sm:items-center">
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
                            v-if="$page.props.auth.user.role === 'admin' || $page.props.auth.user.role === 'developer'"
                            :href="route('users.index')"
                            :active="route().current('users.*')"
                        >
                            Users
                        </ResponsiveNavLink>
                        
                        <ResponsiveNavLink
                            v-if="$page.props.auth.user.role === 'admin' || $page.props.auth.user.role === 'developer'"
                            :href="route('teams.index')"
                            :active="route().current('teams.*')"
                        >
                            Teams
                        </ResponsiveNavLink>
                        
                        <ResponsiveNavLink
                            v-if="$page.props.auth.user.role === 'admin' || $page.props.auth.user.role === 'manager' || $page.props.auth.user.role === 'developer'"
                            :href="route('analytics.index')"
                            :active="route().current('analytics.*')"
                        >
                            Analytics
                        </ResponsiveNavLink>
                        
                        <ResponsiveNavLink
                            v-if="$page.props.auth.user.role === 'employee' || $page.props.auth.user.role === 'admin' || $page.props.auth.user.role === 'manager' || $page.props.auth.user.role === 'developer'"
                            :href="route('time-graph.index')"
                            :active="route().current('time-graph.*')"
                        >
                            Time Graph
                        </ResponsiveNavLink>
                        
                        <ResponsiveNavLink
                            v-if="$page.props.auth.user.role === 'admin'"
                            :href="route('efficiency.index')"
                            :active="route().current('efficiency.*')"
                        >
                            Efficiency Analytics
                        </ResponsiveNavLink>
                        
                        <ResponsiveNavLink
                            v-if="$page.props.auth.user.role === 'developer'"
                            :href="route('time-entries.index')"
                            :active="route().current('time-entries.*')"
                        >
                            Time Entries
                        </ResponsiveNavLink>
                        
                        <ResponsiveNavLink
                            v-if="$page.props.auth.user.role === 'developer'"
                            :href="route('deploy.index')"
                            :active="route().current('deploy.*')"
                        >
                            Deploy
                        </ResponsiveNavLink>
                        
                        <ResponsiveNavLink
                            v-if="$page.props.auth.user.role === 'admin' || $page.props.auth.user.role === 'developer'"
                            :href="route('impersonate.index')"
                            :active="route().current('impersonate.*')"
                        >
                            Impersonate
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

            <!-- Impersonation Banner -->
            <div
                v-if="$page.props.impersonation && ($page.props.impersonation as any).isImpersonating"
                class="bg-yellow-50 border-b border-yellow-200"
            >
                <div class="mx-auto max-w-7xl px-4 py-3 sm:px-6 lg:px-8">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center">
                            <svg
                                class="h-5 w-5 text-yellow-400 mr-2"
                                fill="currentColor"
                                viewBox="0 0 20 20"
                            >
                                <path
                                    fill-rule="evenodd"
                                    d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z"
                                    clip-rule="evenodd"
                                />
                            </svg>
                            <p class="text-sm text-yellow-700">
                                <span class="font-semibold">Impersonating:</span> You are viewing as
                                <span class="font-semibold">{{ $page.props.auth.user.name }}</span>
                                <span v-if="($page.props.impersonation as any)?.originalUser">
                                    (Original: {{ (($page.props.impersonation as any).originalUser as any).name }})
                                </span>
                            </p>
                        </div>
                        <a
                            :href="route('impersonate.index')"
                            class="text-sm font-medium text-yellow-700 hover:text-yellow-900 underline"
                        >
                            Stop Impersonating
                        </a>
                    </div>
                </div>
            </div>

            <!-- Page Heading -->
            <header
                class="bg-white shadow"
                v-if="$slots.header"
            >
                <div class="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8">
                    <slot name="header" />
                </div>
            </header>

            <!-- Page Content -->
            <main>
                <slot />
            </main>
        </div>
    </div>
</template>
