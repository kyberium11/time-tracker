<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head } from '@inertiajs/vue3';
import { onMounted, ref, watch } from 'vue';
import axios from 'axios';

type UserOption = { id: number; name: string; role: string };
type ActivityLog = {
    id: number;
    description?: string | null;
    path?: string | null;
    metadata?: Record<string, any> | null;
    created_at: string;
    user?: UserOption | null;
};

const logs = ref<ActivityLog[]>([]);
const users = ref<UserOption[]>([]);
const loading = ref(false);
const meta = ref<{ current_page: number; last_page: number; total: number; per_page: number } | null>(null);

const filters = ref({
    user_id: '',
    start_date: '',
    end_date: '',
});

const fetchLogs = async (page = 1) => {
    loading.value = true;
    try {
        const { data } = await axios.get('/api/admin/session-logs', {
            params: {
                ...filters.value,
                page,
            },
        });
        logs.value = data.data ?? [];
        users.value = data.filters?.users ?? [];
        meta.value = data.meta ?? null;
    } catch (error) {
        console.error('Failed to load session logs', error);
    } finally {
        loading.value = false;
    }
};

const resetFilters = () => {
    filters.value = { user_id: '', start_date: '', end_date: '' };
    fetchLogs();
};

const goToPage = (page: number) => {
    if (!meta.value) return;
    if (page < 1 || page > meta.value.last_page) return;
    fetchLogs(page);
};

const formatDateTime = (value?: string) => {
    if (!value) return '-';
    const date = new Date(value);
    return date.toLocaleString();
};

const readableMetadata = (metadata?: Record<string, any> | null) => {
    if (!metadata || Object.keys(metadata).length === 0) return '-';

    // Prefer explicit task name if present in dataset
    const dataset = metadata.dataset || {};
    const taskName = dataset.taskName || dataset.task || metadata.taskName || metadata.task;
    if (taskName) return taskName;

    // Fallback to a compact JSON string
    try {
        const json = JSON.stringify(metadata);
        return json.length > 120 ? json.slice(0, 117) + '...' : json;
    } catch (e) {
        return '-';
    }
};

onMounted(() => {
    fetchLogs();
});

// Auto refresh when filters change (debounced by 300ms)
let filterTimeout: number | undefined;
watch(
    () => ({ ...filters.value }),
    () => {
        window.clearTimeout(filterTimeout);
        filterTimeout = window.setTimeout(() => fetchLogs(), 300);
    },
    { deep: true },
);
</script>

<template>
    <Head title="Session Logs" />
    <AuthenticatedLayout>
        <template #header>
            <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h2 class="text-xl font-semibold leading-tight text-gray-800">
                        Session Logs
                    </h2>
                    <p class="text-sm text-gray-500">
                        Developer-only view of captured user clicks and events.
                    </p>
                </div>
                <div class="flex items-center gap-2">
                    <button
                        type="button"
                        class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white shadow hover:bg-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2"
                        @click="fetchLogs(meta?.current_page ?? 1)"
                        :disabled="loading"
                    >
                        {{ loading ? 'Refreshing...' : 'Refresh' }}
                    </button>
                    <button
                        type="button"
                        class="rounded-md border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2"
                        @click="resetFilters"
                        :disabled="loading"
                    >
                        Clear Filters
                    </button>
                </div>
            </div>
        </template>

        <div class="mx-auto max-w-7xl py-6 sm:px-6 lg:px-8">
            <div class="rounded-lg bg-white p-4 shadow">
                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <div class="space-y-1">
                        <label class="text-sm font-medium text-gray-700">User</label>
                        <select
                            v-model="filters.user_id"
                            class="block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                        >
                            <option value="">All users</option>
                            <option v-for="user in users" :key="user.id" :value="user.id">
                                {{ user.name }} ({{ user.role }})
                            </option>
                        </select>
                    </div>
                    <div class="space-y-1">
                        <label class="text-sm font-medium text-gray-700">Start date</label>
                        <input
                            type="date"
                            v-model="filters.start_date"
                            class="block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                        />
                    </div>
                    <div class="space-y-1">
                        <label class="text-sm font-medium text-gray-700">End date</label>
                        <input
                            type="date"
                            v-model="filters.end_date"
                            class="block w-full rounded-md border-gray-300 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                        />
                    </div>
                </div>

                <div class="mt-6 overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-3 text-left font-medium text-gray-500">Timestamp</th>
                                <th class="px-4 py-3 text-left font-medium text-gray-500">User</th>
                                <th class="px-4 py-3 text-left font-medium text-gray-500">Path</th>
                                <th class="px-4 py-3 text-left font-medium text-gray-500">Description</th>
                                <th class="px-4 py-3 text-left font-medium text-gray-500">Metadata</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200">
                            <tr v-if="loading">
                                <td colspan="5" class="px-4 py-6 text-center text-gray-500">
                                    Loading logs...
                                </td>
                            </tr>
                            <tr v-else-if="logs.length === 0">
                                <td colspan="5" class="px-4 py-6 text-center text-gray-500">
                                    No logs found for the selected filters.
                                </td>
                            </tr>
                            <tr v-else v-for="log in logs" :key="log.id" class="hover:bg-gray-50">
                                <td class="px-4 py-3 text-gray-900 whitespace-nowrap">
                                    {{ formatDateTime(log.created_at) }}
                                </td>
                                <td class="px-4 py-3 text-gray-900">
                                    <div class="font-medium">{{ log.user?.name ?? 'Unknown' }}</div>
                                    <div class="text-xs text-gray-500">{{ log.user?.role ?? 'n/a' }}</div>
                                </td>
                                <td class="px-4 py-3 text-gray-900">
                                    <div class="text-xs text-gray-500">{{ log.path || '-' }}</div>
                                </td>
                                <td class="px-4 py-3 text-gray-700 max-w-xs">
                                    <p class="line-clamp-3 whitespace-pre-wrap">
                                        {{ log.description || '-' }}
                                    </p>
                                </td>
                                <td class="px-4 py-3 text-gray-700 max-w-sm">
                                    <p class="line-clamp-3 break-words">
                                        {{ readableMetadata(log.metadata) }}
                                    </p>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div
                    v-if="meta"
                    class="mt-4 flex items-center justify-between text-sm text-gray-600"
                >
                    <div>
                        Page {{ meta.current_page }} of {{ meta.last_page }} ({{ meta.total }} records)
                    </div>
                    <div class="flex items-center gap-2">
                        <button
                            class="rounded border border-gray-300 px-3 py-1 hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-50"
                            :disabled="meta.current_page <= 1 || loading"
                            @click="goToPage(meta.current_page - 1)"
                        >
                            Previous
                        </button>
                        <button
                            class="rounded border border-gray-300 px-3 py-1 hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-50"
                            :disabled="meta.current_page >= meta.last_page || loading"
                            @click="goToPage(meta.current_page + 1)"
                        >
                            Next
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>

