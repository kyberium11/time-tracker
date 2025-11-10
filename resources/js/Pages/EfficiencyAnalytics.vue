<script setup lang="ts">
import { ref, onMounted, computed } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head } from '@inertiajs/vue3';
import api from '@/api';
import { Bar, Line } from 'vue-chartjs';
import {
    Chart as ChartJS,
    CategoryScale,
    LinearScale,
    BarElement,
    PointElement,
    LineElement,
    Title,
    Tooltip,
    Legend,
} from 'chart.js';

ChartJS.register(
    CategoryScale,
    LinearScale,
    BarElement,
    PointElement,
    LineElement,
    Title,
    Tooltip,
    Legend
);

interface Task {
    id: number;
    task_name: string;
    assigned_to: string;
    project: string;
    estimated_time: number;
    tracked_time: number;
    efficiency: number | null;
    status: string;
}

interface Summary {
    average_efficiency: number;
    total_estimated_time: number;
    total_tracked_time: number;
    overrun_rate: number;
}

interface UserEfficiency {
    user: string;
    efficiency: number;
}

interface TrendData {
    week: string;
    efficiency: number | null;
}

interface User {
    id: number;
    name: string;
    email: string;
}

const loading = ref(false);
const summary = ref<Summary | null>(null);
const tasks = ref<Task[]>([]);
const userEfficiency = ref<UserEfficiency[]>([]);
const trendData = ref<TrendData[]>([]);

// Filters
const selectedUserId = ref<number | null>(null);
const startDate = ref('');
const endDate = ref('');
const users = ref<User[]>([]);

const loadUsers = async () => {
    try {
        const response = await api.get('/admin/users', { params: { per_page: 1000 } });
        if (response.data?.data) {
            users.value = response.data.data;
        } else if (Array.isArray(response.data)) {
            users.value = response.data;
        }
    } catch (error) {
        console.error('Error loading users:', error);
    }
};

const loadData = async () => {
    loading.value = true;
    try {
        const params: any = {};
        if (selectedUserId.value) {
            params.user_id = selectedUserId.value;
        }
        if (startDate.value) {
            params.start_date = startDate.value;
        }
        if (endDate.value) {
            params.end_date = endDate.value;
        }
        
        const response = await api.get('/admin/analytics/efficiency', { params });
        summary.value = response.data.summary;
        tasks.value = response.data.tasks;
        userEfficiency.value = response.data.user_efficiency;
        trendData.value = response.data.trend;
    } catch (error: any) {
        console.error('Error loading efficiency data:', error);
        alert(error.response?.data?.error || 'Failed to load efficiency data');
    } finally {
        loading.value = false;
    }
};

const applyFilters = () => {
    loadData();
};

const clearFilters = () => {
    selectedUserId.value = null;
    // Reset to default date range (last 30 days)
    const today = new Date();
    const thirtyDaysAgo = new Date(today);
    thirtyDaysAgo.setDate(today.getDate() - 30);
    endDate.value = today.toISOString().split('T')[0];
    startDate.value = thirtyDaysAgo.toISOString().split('T')[0];
    loadData();
};

onMounted(() => {
    // Set default date range to last 30 days
    const today = new Date();
    const thirtyDaysAgo = new Date(today);
    thirtyDaysAgo.setDate(today.getDate() - 30);
    endDate.value = today.toISOString().split('T')[0];
    startDate.value = thirtyDaysAgo.toISOString().split('T')[0];
    
    loadUsers();
    loadData();
});

const getEfficiencyColor = (efficiency: number | null): string => {
    if (efficiency === null) return 'text-gray-500';
    if (efficiency >= 100) return 'text-green-600';
    if (efficiency >= 90) return 'text-yellow-600';
    return 'text-red-600';
};

const getEfficiencyBgColor = (efficiency: number | null): string => {
    if (efficiency === null) return 'bg-gray-100';
    if (efficiency >= 100) return 'bg-green-100';
    if (efficiency >= 90) return 'bg-yellow-100';
    return 'bg-red-100';
};

const getStatusColor = (status: string): string => {
    switch (status) {
        case 'Efficient':
            return 'bg-green-100 text-green-800';
        case 'At Risk':
            return 'bg-yellow-100 text-yellow-800';
        case 'Overrun':
            return 'bg-red-100 text-red-800';
        default:
            return 'bg-gray-100 text-gray-800';
    }
};

// Chart data for user efficiency (bar chart)
const userChartData = computed(() => {
    return {
        labels: userEfficiency.value.map(u => u.user),
        datasets: [
            {
                label: 'Efficiency (%)',
                data: userEfficiency.value.map(u => u.efficiency),
                backgroundColor: userEfficiency.value.map(u => {
                    if (u.efficiency >= 100) return 'rgba(34, 197, 94, 0.8)';
                    if (u.efficiency >= 90) return 'rgba(234, 179, 8, 0.8)';
                    return 'rgba(239, 68, 68, 0.8)';
                }),
                borderColor: userEfficiency.value.map(u => {
                    if (u.efficiency >= 100) return 'rgba(34, 197, 94, 1)';
                    if (u.efficiency >= 90) return 'rgba(234, 179, 8, 1)';
                    return 'rgba(239, 68, 68, 1)';
                }),
                borderWidth: 1,
            },
        ],
    };
});

const userChartOptions = {
    responsive: true,
    maintainAspectRatio: false,
    plugins: {
        legend: {
            display: false,
        },
        title: {
            display: true,
            text: 'Efficiency by User',
        },
        tooltip: {
            callbacks: {
                label: (context: any) => `${context.parsed.y.toFixed(2)}%`,
            },
        },
    },
    scales: {
        y: {
            beginAtZero: true,
            title: {
                display: true,
                text: 'Efficiency (%)',
            },
        },
    },
};

// Chart data for trend (line chart)
const trendChartData = computed(() => {
    return {
        labels: trendData.value.map(t => t.week),
        datasets: [
            {
                label: 'Average Efficiency (%)',
                data: trendData.value.map(t => t.efficiency),
                borderColor: 'rgba(59, 130, 246, 1)',
                backgroundColor: 'rgba(59, 130, 246, 0.1)',
                borderWidth: 2,
                fill: true,
                tension: 0.4,
            },
        ],
    };
});

const trendChartOptions = {
    responsive: true,
    maintainAspectRatio: false,
    plugins: {
        legend: {
            display: true,
        },
        title: {
            display: true,
            text: 'Average Efficiency Trend (Last 12 Weeks)',
        },
        tooltip: {
            callbacks: {
                label: (context: any) => {
                    const value = context.parsed.y;
                    return value !== null ? `${value.toFixed(2)}%` : 'No data';
                },
            },
        },
    },
    scales: {
        y: {
            beginAtZero: true,
            title: {
                display: true,
                text: 'Efficiency (%)',
            },
        },
    },
};
</script>

<template>
    <Head title="Efficiency Analytics" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">
                Efficiency Analytics
            </h2>
        </template>

        <div class="py-12">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
                <div v-if="loading" class="text-center py-12">
                    <div class="inline-block animate-spin rounded-full h-12 w-12 border-b-2 border-indigo-600"></div>
                    <p class="mt-4 text-gray-600">Loading efficiency data...</p>
                </div>

                <div v-else class="space-y-6">
                    <!-- Filters -->
                    <div class="bg-white shadow rounded-lg p-6">
                        <h3 class="text-lg font-medium text-gray-900 mb-4">Filters</h3>
                        <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">User</label>
                                <select
                                    v-model="selectedUserId"
                                    class="block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                >
                                    <option :value="null">All Users</option>
                                    <option v-for="user in users" :key="user.id" :value="user.id">
                                        {{ user.name }}
                                    </option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">Start Date</label>
                                <input
                                    v-model="startDate"
                                    type="date"
                                    class="block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                />
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">End Date</label>
                                <input
                                    v-model="endDate"
                                    type="date"
                                    class="block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                />
                            </div>
                            <div class="flex items-end gap-2">
                                <button
                                    @click="applyFilters"
                                    class="px-4 py-2 bg-indigo-600 text-white rounded-md hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-500"
                                >
                                    Apply Filters
                                </button>
                                <button
                                    @click="clearFilters"
                                    class="px-4 py-2 bg-gray-200 text-gray-700 rounded-md hover:bg-gray-300 focus:outline-none focus:ring-2 focus:ring-gray-500"
                                >
                                    Clear
                                </button>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Summary Cards -->
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
                        <div class="bg-white overflow-hidden shadow rounded-lg">
                            <div class="p-5">
                                <div class="flex items-center">
                                    <div class="flex-shrink-0">
                                        <svg class="h-6 w-6 text-indigo-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" />
                                        </svg>
                                    </div>
                                    <div class="ml-5 w-0 flex-1">
                                        <dl>
                                            <dt class="text-sm font-medium text-gray-500 truncate">Average Efficiency</dt>
                                            <dd class="text-2xl font-semibold text-gray-900">
                                                {{ summary?.average_efficiency?.toFixed(2) || '0.00' }}%
                                            </dd>
                                        </dl>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="bg-white overflow-hidden shadow rounded-lg">
                            <div class="p-5">
                                <div class="flex items-center">
                                    <div class="flex-shrink-0">
                                        <svg class="h-6 w-6 text-blue-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                                        </svg>
                                    </div>
                                    <div class="ml-5 w-0 flex-1">
                                        <dl>
                                            <dt class="text-sm font-medium text-gray-500 truncate">Total Estimated Time</dt>
                                            <dd class="text-2xl font-semibold text-gray-900">
                                                {{ summary?.total_estimated_time?.toFixed(2) || '0.00' }} hrs
                                            </dd>
                                        </dl>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="bg-white overflow-hidden shadow rounded-lg">
                            <div class="p-5">
                                <div class="flex items-center">
                                    <div class="flex-shrink-0">
                                        <svg class="h-6 w-6 text-green-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                                        </svg>
                                    </div>
                                    <div class="ml-5 w-0 flex-1">
                                        <dl>
                                            <dt class="text-sm font-medium text-gray-500 truncate">Total Tracked Time</dt>
                                            <dd class="text-2xl font-semibold text-gray-900">
                                                {{ summary?.total_tracked_time?.toFixed(2) || '0.00' }} hrs
                                            </dd>
                                        </dl>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="bg-white overflow-hidden shadow rounded-lg">
                            <div class="p-5">
                                <div class="flex items-center">
                                    <div class="flex-shrink-0">
                                        <svg class="h-6 w-6 text-red-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                                        </svg>
                                    </div>
                                    <div class="ml-5 w-0 flex-1">
                                        <dl>
                                            <dt class="text-sm font-medium text-gray-500 truncate">Overrun Rate</dt>
                                            <dd class="text-2xl font-semibold text-gray-900">
                                                {{ summary?.overrun_rate?.toFixed(2) || '0.00' }}%
                                            </dd>
                                        </dl>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Table View -->
                    <div class="bg-white shadow rounded-lg overflow-hidden">
                        <div class="px-4 py-5 sm:p-6">
                            <h3 class="text-lg font-medium text-gray-900 mb-4">Task Efficiency Details</h3>
                            <div class="overflow-x-auto">
                                <table class="min-w-full divide-y divide-gray-200">
                                    <thead class="bg-gray-50">
                                        <tr>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Task Name</th>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Assigned To</th>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Project</th>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Estimated Time</th>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Tracked Time</th>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Efficiency</th>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                                        </tr>
                                    </thead>
                                    <tbody class="bg-white divide-y divide-gray-200">
                                        <tr v-for="task in tasks" :key="task.id">
                                            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                                {{ task.task_name }}
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                {{ task.assigned_to }}
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                {{ task.project }}
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                {{ task.estimated_time.toFixed(2) }} hrs
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                {{ task.tracked_time.toFixed(2) }} hrs
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm">
                                                <span :class="['font-semibold', getEfficiencyColor(task.efficiency)]">
                                                    {{ task.efficiency !== null ? task.efficiency.toFixed(2) + '%' : 'N/A' }}
                                                </span>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap">
                                                <span :class="['px-2 inline-flex text-xs leading-5 font-semibold rounded-full', getStatusColor(task.status)]">
                                                    {{ task.status }}
                                                </span>
                                            </td>
                                        </tr>
                                        <tr v-if="tasks.length === 0">
                                            <td colspan="7" class="px-6 py-4 text-center text-sm text-gray-500">
                                                No tasks with estimated time found.
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <!-- Charts Section -->
                    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                        <!-- Bar Chart: Efficiency per User -->
                        <div class="bg-white shadow rounded-lg overflow-hidden">
                            <div class="px-4 py-5 sm:p-6">
                                <div style="height: 400px;">
                                    <Bar
                                        v-if="userEfficiency.length > 0"
                                        :data="userChartData"
                                        :options="userChartOptions"
                                    />
                                    <div v-else class="flex items-center justify-center h-full text-gray-500">
                                        No user efficiency data available
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Line Chart: Average Efficiency Trend -->
                        <div class="bg-white shadow rounded-lg overflow-hidden">
                            <div class="px-4 py-5 sm:p-6">
                                <div style="height: 400px;">
                                    <Line
                                        v-if="trendData.length > 0"
                                        :data="trendChartData"
                                        :options="trendChartOptions"
                                    />
                                    <div v-else class="flex items-center justify-center h-full text-gray-500">
                                        No trend data available
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>

