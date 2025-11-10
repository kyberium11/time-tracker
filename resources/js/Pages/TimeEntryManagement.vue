<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head } from '@inertiajs/vue3';
import { ref, computed, onMounted } from 'vue';
import api from '@/api';

interface TimeEntry {
    id: number;
    user_id: number;
    task_id: number | null;
    date: string;
    clock_in: string | null;
    clock_out: string | null;
    break_start: string | null;
    break_end: string | null;
    lunch_start: string | null;
    lunch_end: string | null;
    total_hours: number;
    entry_type: string;
    created_at: string;
    updated_at: string;
    user?: {
        id: number;
        name: string;
        email: string;
    };
    task?: {
        id: number;
        title: string;
        clickup_task_id: string | null;
    };
}

const timeEntries = ref<TimeEntry[]>([]);
const loading = ref(false);
const editingEntry = ref<TimeEntry | null>(null);
const showEditModal = ref(false);
const showDeleteModal = ref(false);
const deletingEntry = ref<TimeEntry | null>(null);
const error = ref<string | null>(null);
const success = ref<string | null>(null);

// Filters
const dateFrom = ref(new Date().toISOString().split('T')[0]);
const dateTo = ref(new Date().toISOString().split('T')[0]);
const userId = ref<number | null>(null);
const users = ref<Array<{ id: number; name: string; email: string }>>([]);

// Pagination
const currentPage = ref(1);
const perPage = ref(50);
const total = ref(0);

const loadTimeEntries = async () => {
    loading.value = true;
    error.value = null;
    try {
        const params: any = {
            page: currentPage.value,
            per_page: perPage.value,
            date_from: dateFrom.value,
            date_to: dateTo.value,
        };
        if (userId.value) {
            params.user_id = userId.value;
        }
        const response = await api.get('/admin/time-entries', { params });
        timeEntries.value = response.data.data || [];
        total.value = response.data.total || 0;
    } catch (e: any) {
        error.value = e.response?.data?.message || 'Failed to load time entries';
        timeEntries.value = [];
    } finally {
        loading.value = false;
    }
};

const loadUsers = async () => {
    try {
        const response = await api.get('/admin/users', { params: { per_page: 1000 } });
        // Handle paginated response
        if (response.data?.data) {
            users.value = response.data.data;
        } else if (Array.isArray(response.data)) {
            users.value = response.data;
        } else {
            users.value = [];
        }
    } catch (e) {
        console.error('Failed to load users', e);
        users.value = [];
    }
};


const formatDateTimeLocal = (dateTime: string | null): string => {
    if (!dateTime) return '';
    const date = new Date(dateTime);
    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');
    const hours = String(date.getHours()).padStart(2, '0');
    const minutes = String(date.getMinutes()).padStart(2, '0');
    const seconds = String(date.getSeconds()).padStart(2, '0');
    return `${year}-${month}-${day}T${hours}:${minutes}:${seconds}`;
};

const openEditModal = async (entry: TimeEntry) => {
    editingEntry.value = {
        ...entry,
        clock_in: entry.clock_in ? formatDateTimeLocal(entry.clock_in) : null,
        clock_out: entry.clock_out ? formatDateTimeLocal(entry.clock_out) : null,
    };
    showEditModal.value = true;
};

const closeEditModal = () => {
    editingEntry.value = null;
    showEditModal.value = false;
    error.value = null;
    success.value = null;
};

const convertDateTimeLocalToISO = (dateTimeLocal: string | null): string | null => {
    if (!dateTimeLocal) return null;
    // Convert datetime-local format (YYYY-MM-DDTHH:mm:ss) to ISO format
    // Handle both with and without seconds
    let dateStr = dateTimeLocal;
    if (!dateStr.includes(':')) {
        return null;
    }
    // If seconds are missing, add :00
    const parts = dateStr.split('T');
    if (parts.length === 2) {
        const timeParts = parts[1].split(':');
        if (timeParts.length === 2) {
            // Add seconds if missing
            dateStr = `${parts[0]}T${parts[1]}:00`;
        }
    }
    const date = new Date(dateStr);
    return date.toISOString();
};

const saveEntry = async () => {
    if (!editingEntry.value) return;
    
    error.value = null;
    success.value = null;
    
    try {
        await api.put(`/admin/time-entries/${editingEntry.value.id}`, {
            clock_in: convertDateTimeLocalToISO(editingEntry.value.clock_in),
            clock_out: convertDateTimeLocalToISO(editingEntry.value.clock_out),
        });
        success.value = 'Time entry updated successfully and synced to ClickUp';
        closeEditModal();
        await loadTimeEntries();
        setTimeout(() => {
            success.value = null;
        }, 3000);
    } catch (e: any) {
        error.value = e.response?.data?.message || 'Failed to update time entry';
    }
};

const openDeleteModal = (entry: TimeEntry) => {
    deletingEntry.value = entry;
    showDeleteModal.value = true;
};

const closeDeleteModal = () => {
    deletingEntry.value = null;
    showDeleteModal.value = false;
    error.value = null;
};

const deleteEntry = async () => {
    if (!deletingEntry.value) return;
    
    error.value = null;
    
    try {
        await api.delete(`/admin/time-entries/${deletingEntry.value.id}`);
        success.value = 'Time entry deleted successfully and synced to ClickUp';
        closeDeleteModal();
        await loadTimeEntries();
        setTimeout(() => {
            success.value = null;
        }, 3000);
    } catch (e: any) {
        error.value = e.response?.data?.message || 'Failed to delete time entry';
    }
};

const formatDateTime = (dateTime: string | null): string => {
    if (!dateTime) return '--';
    const date = new Date(dateTime);
    return date.toLocaleString('en-US', {
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit',
    });
};

const formatDate = (date: string): string => {
    return new Date(date).toLocaleDateString('en-US', {
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
    });
};

const formatTime = (dateTime: string | null): string => {
    if (!dateTime) return '--';
    const date = new Date(dateTime);
    return date.toLocaleTimeString('en-US', {
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit',
    });
};

const formatTotalHours = (hours: number | string | null | undefined): string => {
    if (hours === null || hours === undefined) return '0.00h';
    const numHours = typeof hours === 'string' ? parseFloat(hours) : hours;
    if (isNaN(numHours)) return '0.00h';
    return `${numHours.toFixed(2)}h`;
};

const totalPages = computed(() => Math.ceil(total.value / perPage.value));

const applyFilters = () => {
    currentPage.value = 1;
    loadTimeEntries();
};

onMounted(() => {
    loadUsers();
    loadTimeEntries();
});
</script>

<template>
    <Head title="Time Entry Management" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">
                Time Entry Management
            </h2>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-7xl sm:px-6 lg:px-8">
                <!-- Success Message -->
                <div v-if="success" class="mb-4 rounded-md bg-green-50 p-4">
                    <div class="flex">
                        <div class="ml-3">
                            <p class="text-sm font-medium text-green-800">{{ success }}</p>
                        </div>
                    </div>
                </div>

                <!-- Error Message -->
                <div v-if="error" class="mb-4 rounded-md bg-red-50 p-4">
                    <div class="flex">
                        <div class="ml-3">
                            <p class="text-sm font-medium text-red-800">{{ error }}</p>
                        </div>
                    </div>
                </div>

                <div class="overflow-hidden bg-white shadow sm:rounded-lg">
                    <div class="p-6">
                        <!-- Filters -->
                        <div class="mb-6 grid grid-cols-1 gap-4 md:grid-cols-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700">Date From</label>
                                <input
                                    v-model="dateFrom"
                                    type="date"
                                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                />
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700">Date To</label>
                                <input
                                    v-model="dateTo"
                                    type="date"
                                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                />
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700">User</label>
                                <select
                                    v-model="userId"
                                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                >
                                    <option :value="null">All Users</option>
                                    <option v-for="user in users" :key="user.id" :value="user.id">
                                        {{ user.name }} ({{ user.email }})
                                    </option>
                                </select>
                            </div>
                            <div class="flex items-end">
                                <button
                                    @click="applyFilters"
                                    class="w-full rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2"
                                >
                                    Apply Filters
                                </button>
                            </div>
                        </div>

                        <!-- Time Entries Table -->
                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">
                                            ID
                                        </th>
                                        <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">
                                            User
                                        </th>
                                        <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">
                                            Date
                                        </th>
                                        <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">
                                            Task
                                        </th>
                                        <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">
                                            Clock In
                                        </th>
                                        <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">
                                            Clock Out
                                        </th>
                                        <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">
                                            Total Hours
                                        </th>
                                        <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">
                                            Actions
                                        </th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-200 bg-white">
                                    <tr v-if="loading">
                                        <td colspan="8" class="px-6 py-4 text-center">
                                            <div class="inline-block h-8 w-8 animate-spin rounded-full border-4 border-solid border-indigo-600 border-r-transparent"></div>
                                        </td>
                                    </tr>
                                    <tr v-else-if="timeEntries.length === 0">
                                        <td colspan="8" class="px-6 py-4 text-center text-sm text-gray-500">
                                            No time entries found
                                        </td>
                                    </tr>
                                    <tr v-else v-for="entry in timeEntries" :key="entry.id">
                                        <td class="whitespace-nowrap px-6 py-4 text-sm text-gray-900">
                                            {{ entry.id }}
                                        </td>
                                        <td class="whitespace-nowrap px-6 py-4 text-sm text-gray-900">
                                            {{ entry.user?.name || 'N/A' }}
                                        </td>
                                        <td class="whitespace-nowrap px-6 py-4 text-sm text-gray-900">
                                            {{ formatDate(entry.date) }}
                                        </td>
                                        <td class="whitespace-nowrap px-6 py-4 text-sm text-gray-900">
                                            {{ entry.task?.title || 'N/A' }}
                                        </td>
                                        <td class="whitespace-nowrap px-6 py-4 text-sm text-gray-900">
                                            {{ formatTime(entry.clock_in) }}
                                        </td>
                                        <td class="whitespace-nowrap px-6 py-4 text-sm text-gray-900">
                                            {{ formatTime(entry.clock_out) }}
                                        </td>
                                        <td class="whitespace-nowrap px-6 py-4 text-sm font-medium text-gray-900">
                                            {{ formatTotalHours(entry.total_hours) }}
                                        </td>
                                        <td class="whitespace-nowrap px-6 py-4 text-sm font-medium">
                                            <button
                                                @click="openEditModal(entry)"
                                                class="mr-2 text-indigo-600 hover:text-indigo-900"
                                            >
                                                Edit
                                            </button>
                                            <button
                                                @click="openDeleteModal(entry)"
                                                class="text-red-600 hover:text-red-900"
                                            >
                                                Delete
                                            </button>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <!-- Pagination -->
                        <div v-if="totalPages > 1" class="mt-4 flex items-center justify-between">
                            <div class="text-sm text-gray-700">
                                Showing {{ (currentPage - 1) * perPage + 1 }} to
                                {{ Math.min(currentPage * perPage, total) }} of {{ total }} results
                            </div>
                            <div class="flex gap-2">
                                <button
                                    @click="currentPage--; loadTimeEntries()"
                                    :disabled="currentPage === 1"
                                    class="rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 disabled:opacity-50"
                                >
                                    Previous
                                </button>
                                <button
                                    @click="currentPage++; loadTimeEntries()"
                                    :disabled="currentPage >= totalPages"
                                    class="rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 disabled:opacity-50"
                                >
                                    Next
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Edit Modal -->
        <div v-if="showEditModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black bg-opacity-50">
            <div class="w-full max-w-2xl rounded-lg bg-white p-6 shadow-xl">
                <h3 class="mb-4 text-lg font-medium text-gray-900">Edit Time Entry</h3>
                
                <div v-if="error" class="mb-4 rounded-md bg-red-50 p-4">
                    <p class="text-sm font-medium text-red-800">{{ error }}</p>
                </div>

                <div v-if="editingEntry" class="space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700">Start Time</label>
                        <input
                            v-model="editingEntry.clock_in"
                            type="text"
                            pattern="\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}"
                            placeholder="YYYY-MM-DDTHH:mm:ss"
                            class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                        />
                        <p class="mt-1 text-xs text-gray-500">Format: YYYY-MM-DDTHH:mm:ss (e.g., 2025-11-10T11:26:30)</p>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700">End Time</label>
                        <input
                            v-model="editingEntry.clock_out"
                            type="text"
                            pattern="\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}"
                            placeholder="YYYY-MM-DDTHH:mm:ss"
                            class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                        />
                        <p class="mt-1 text-xs text-gray-500">Format: YYYY-MM-DDTHH:mm:ss (e.g., 2025-11-10T11:26:30)</p>
                    </div>
                </div>

                <div class="mt-6 flex justify-end gap-3">
                    <button
                        @click="closeEditModal"
                        class="rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50"
                    >
                        Cancel
                    </button>
                    <button
                        @click="saveEntry"
                        class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700"
                    >
                        Save Changes
                    </button>
                </div>
            </div>
        </div>

        <!-- Delete Modal -->
        <div v-if="showDeleteModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black bg-opacity-50">
            <div class="w-full max-w-md rounded-lg bg-white p-6 shadow-xl">
                <h3 class="mb-4 text-lg font-medium text-gray-900">Delete Time Entry</h3>
                
                <div v-if="error" class="mb-4 rounded-md bg-red-50 p-4">
                    <p class="text-sm font-medium text-red-800">{{ error }}</p>
                </div>

                <p class="mb-4 text-sm text-gray-600">
                    Are you sure you want to delete this time entry? This action cannot be undone and will sync to ClickUp.
                </p>

                <div v-if="deletingEntry" class="mb-4 rounded-md bg-gray-50 p-4">
                    <p class="text-sm text-gray-700">
                        <strong>ID:</strong> {{ deletingEntry.id }}<br>
                        <strong>User:</strong> {{ deletingEntry.user?.name || 'N/A' }}<br>
                        <strong>Date:</strong> {{ formatDate(deletingEntry.date) }}<br>
                        <strong>Total Hours:</strong> {{ formatTotalHours(deletingEntry.total_hours) }}
                    </p>
                </div>

                <div class="flex justify-end gap-3">
                    <button
                        @click="closeDeleteModal"
                        class="rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50"
                    >
                        Cancel
                    </button>
                    <button
                        @click="deleteEntry"
                        class="rounded-md bg-red-600 px-4 py-2 text-sm font-medium text-white hover:bg-red-700"
                    >
                        Delete
                    </button>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>

