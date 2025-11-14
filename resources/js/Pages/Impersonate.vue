<script setup lang="ts">
import { ref, computed } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head } from '@inertiajs/vue3';
import api from '@/api';

interface User {
    id: number;
    name: string;
    email: string;
    role: string;
}

interface Props {
    users: User[];
    isImpersonating: boolean;
    originalUserId?: number;
}

const props = defineProps<Props>();

const loading = ref(false);
const searchQuery = ref('');
const impersonatingUser = ref<User | null>(null);

const filteredUsers = computed(() => {
    if (!searchQuery.value) return props.users;
    const query = searchQuery.value.toLowerCase();
    return props.users.filter(
        (user) =>
            user.name.toLowerCase().includes(query) ||
            user.email.toLowerCase().includes(query) ||
            user.role.toLowerCase().includes(query)
    );
});

const startImpersonating = async (user: User) => {
    if (loading.value) return;
    
    if (!confirm(`Are you sure you want to login as ${user.name}?`)) {
        return;
    }

    loading.value = true;
    try {
        const response = await api.post(`/impersonate/start/${user.id}`);
        if (response.data.success) {
            impersonatingUser.value = response.data.user;
            // Reload the page to reflect the new user session
            window.location.href = '/dashboard';
        }
    } catch (error: any) {
        alert(error?.response?.data?.error || 'Failed to start impersonation');
    } finally {
        loading.value = false;
    }
};

const stopImpersonating = async () => {
    if (loading.value) return;
    
    loading.value = true;
    try {
        const response = await api.post('/impersonate/stop');
        if (response.data.success) {
            // Reload the page to reflect the original user session
            window.location.href = '/dashboard';
        }
    } catch (error: any) {
        alert(error?.response?.data?.error || 'Failed to stop impersonation');
    } finally {
        loading.value = false;
    }
};

const getRoleColor = (role: string) => {
    switch (role) {
        case 'admin':
            return 'bg-red-100 text-red-800';
        case 'developer':
            return 'bg-purple-100 text-purple-800';
        case 'manager':
            return 'bg-blue-100 text-blue-800';
        case 'employee':
            return 'bg-green-100 text-green-800';
        default:
            return 'bg-gray-100 text-gray-800';
    }
};
</script>

<template>
    <Head title="Impersonate User" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">Impersonate User</h2>
        </template>

        <div class="py-12">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
                <!-- Impersonation Status Banner -->
                <div
                    v-if="isImpersonating"
                    class="mb-6 bg-yellow-50 border-l-4 border-yellow-400 p-4 rounded"
                >
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
                                You are currently impersonating a user. All actions will be performed as that user.
                            </p>
                        </div>
                        <button
                            @click="stopImpersonating"
                            :disabled="loading"
                            class="px-4 py-2 bg-yellow-600 text-white rounded-md hover:bg-yellow-700 focus:outline-none focus:ring-2 focus:ring-yellow-500 disabled:opacity-50"
                        >
                            {{ loading ? 'Stopping...' : 'Stop Impersonating' }}
                        </button>
                    </div>
                </div>

                <!-- Search Bar -->
                <div class="bg-white shadow rounded-lg p-6 mb-6">
                    <label for="search" class="block text-sm font-medium text-gray-700 mb-2">
                        Search Users
                    </label>
                    <input
                        id="search"
                        v-model="searchQuery"
                        type="text"
                        placeholder="Search by name, email, or role..."
                        class="block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                    />
                </div>

                <!-- Users List -->
                <div class="bg-white shadow rounded-lg overflow-hidden">
                    <div class="px-6 py-4 border-b border-gray-200">
                        <h3 class="text-lg font-medium text-gray-900">
                            Select a user to impersonate
                        </h3>
                        <p class="text-sm text-gray-500 mt-1">
                            Click on a user to login as them and see their view of the application.
                        </p>
                    </div>

                    <div v-if="filteredUsers.length === 0" class="p-6 text-center text-gray-500">
                        No users found matching your search.
                    </div>

                    <div v-else class="divide-y divide-gray-200">
                        <div
                            v-for="user in filteredUsers"
                            :key="user.id"
                            class="px-6 py-4 hover:bg-gray-50 transition-colors cursor-pointer"
                            @click="startImpersonating(user)"
                        >
                            <div class="flex items-center justify-between">
                                <div class="flex items-center space-x-4">
                                    <div class="flex-shrink-0">
                                        <div
                                            class="h-10 w-10 rounded-full bg-indigo-100 flex items-center justify-center"
                                        >
                                            <span class="text-indigo-600 font-semibold">
                                                {{ user.name.charAt(0).toUpperCase() }}
                                            </span>
                                        </div>
                                    </div>
                                    <div>
                                        <p class="text-sm font-medium text-gray-900">{{ user.name }}</p>
                                        <p class="text-sm text-gray-500">{{ user.email }}</p>
                                    </div>
                                </div>
                                <div class="flex items-center space-x-3">
                                    <span
                                        :class="[
                                            'px-2 py-1 text-xs font-semibold rounded-full',
                                            getRoleColor(user.role),
                                        ]"
                                    >
                                        {{ user.role }}
                                    </span>
                                    <svg
                                        class="h-5 w-5 text-gray-400"
                                        fill="none"
                                        stroke="currentColor"
                                        viewBox="0 0 24 24"
                                    >
                                        <path
                                            stroke-linecap="round"
                                            stroke-linejoin="round"
                                            stroke-width="2"
                                            d="M9 5l7 7-7 7"
                                        />
                                    </svg>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>

