<script setup lang="ts">
import { ref, onMounted } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head } from '@inertiajs/vue3';
import api from '@/api';

interface LogItem {
    id: number;
    event: string | null;
    task_id: string | null;
    status_code: number | null;
    message: string | null;
    payload: any;
    created_at: string;
}

const logs = ref<LogItem[]>([]);
const loading = ref(false);
const webhooks = ref<any[]>([]);

const fetchLogs = async () => {
    loading.value = true;
    try {
        const res = await api.get('/admin/clickup/webhook-logs');
        logs.value = res.data?.logs || [];
        webhooks.value = res.data?.webhooks || [];
    } catch (e) {
        alert('Failed to load ClickUp logs');
    } finally {
        loading.value = false;
    }
};

onMounted(fetchLogs);
</script>

<template>
    <Head title="ClickUp Logs" />
    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <h2 class="text-xl font-semibold leading-tight text-gray-800">ClickUp Webhook Logs</h2>
                <button @click="fetchLogs" :disabled="loading" class="rounded-md bg-indigo-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500">
                    Refresh
                </button>
            </div>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-7xl sm:px-6 lg:px-8">
                <div class="mb-4 overflow-hidden bg-white shadow sm:rounded-lg">
                    <div class="px-4 py-5 sm:p-6">
                        <h3 class="text-md font-semibold mb-2">Active ClickUp Webhooks</h3>
                        <div v-if="!webhooks.length" class="text-sm text-gray-500">No webhooks found (or CLICKUP_TEAM_ID not set).</div>
                        <ul v-else class="list-disc pl-5 space-y-1 text-sm text-gray-700">
                            <li v-for="w in webhooks" :key="w.id">
                                <span class="font-medium">{{ w.id }}</span> → {{ w.endpoint }} (events: {{ (w.events || []).join(', ') }})
                                <span class="ml-2 text-xs" :class="w.health?.status === 'active' ? 'text-green-600' : 'text-red-600'">{{ w.health?.status }}</span>
                            </li>
                        </ul>
                    </div>
                </div>
                <div class="overflow-hidden bg-white shadow sm:rounded-lg">
                    <div class="px-4 py-5 sm:p-6 overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Time</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Event</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Task ID</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Status</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Message</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Payload</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200 bg-white">
                                <tr v-for="item in logs" :key="item.id">
                                    <td class="whitespace-nowrap px-6 py-4 text-sm text-gray-700">{{ new Date(item.created_at).toLocaleString() }}</td>
                                    <td class="whitespace-nowrap px-6 py-4 text-sm text-gray-900">{{ item.event || '—' }}</td>
                                    <td class="whitespace-nowrap px-6 py-4 text-sm text-gray-700">{{ item.task_id || '—' }}</td>
                                    <td class="whitespace-nowrap px-6 py-4 text-sm text-gray-700">{{ item.status_code ?? '—' }}</td>
                                    <td class="whitespace-nowrap px-6 py-4 text-sm text-gray-700">{{ item.message || '—' }}</td>
                                    <td class="px-6 py-4 text-xs text-gray-500"><pre class="whitespace-pre-wrap">{{ JSON.stringify(item.payload, null, 2) }}</pre></td>
                                </tr>
                                <tr v-if="!logs.length && !loading">
                                    <td colspan="6" class="px-6 py-6 text-center text-sm text-gray-500">No logs yet.</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>

</template>


