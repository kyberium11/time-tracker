<script setup lang="ts">
import { reactive, onMounted, computed, watch } from 'vue';
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

type MetricsTab = 'efficiency' | 'work-hour-gaps' | 'utilization' | 'attendance';

const tabs: Array<{ key: MetricsTab; label: string }> = [
    { key: 'efficiency', label: 'Efficiency' },
    { key: 'work-hour-gaps', label: 'Work Hour Gaps' },
    { key: 'utilization', label: 'Utilization' },
    { key: 'attendance', label: 'Attendance' },
];

const state = reactive({
    activeTab: 'efficiency' as MetricsTab,
    loading: false,
    summary: null as Summary | null,
    tasks: [] as Task[],
    userEfficiency: [] as UserEfficiency[],
    trendData: [] as TrendData[],
    selectedUserId: null as number | null,
    startDate: '',
    endDate: '',
    users: [] as User[],
    gaps: {
        loading: false,
        data: [] as Array<{ user: string; date: string; worked_hours: number; task_hours: number }>,
    },
    utilization: {
        loading: false,
        data: [] as Array<{ user: string; percent: number }>,
    },
    attendance: {
        loading: false,
        data: [] as Array<{ user: string; perfect_days: number; late_days: number; absence_days: number }>,
    },
});

const activeTab = computed({
    get: () => state.activeTab,
    set: (value: MetricsTab) => {
        state.activeTab = value;
    },
});

const selectedUserId = computed({
    get: () => state.selectedUserId,
    set: (value: number | null) => {
        state.selectedUserId = value;
    },
});

const startDate = computed({
    get: () => state.startDate,
    set: (value: string) => {
        state.startDate = value;
    },
});

const endDate = computed({
    get: () => state.endDate,
    set: (value: string) => {
        state.endDate = value;
    },
});

const users = computed(() => state.users);
const isEfficiencyLoading = computed(() => state.loading);
const efficiencySummary = computed(() => state.summary);
const efficiencyTasks = computed(() => state.tasks);
const efficiencyUserEfficiency = computed(() => state.userEfficiency);
const efficiencyTrend = computed(() => state.trendData);

const gapData = computed(() => state.gaps.data);
const isGapLoading = computed(() => state.gaps.loading);
const utilizationData = computed(() => state.utilization.data);
const isUtilizationLoading = computed(() => state.utilization.loading);
const attendanceData = computed(() => state.attendance.data);
const isAttendanceLoading = computed(() => state.attendance.loading);

const loadUsers = async () => {
    try {
        const response = await api.get('/admin/users', { params: { per_page: 1000 } });
        const payload = response.data?.data ?? response.data;
        if (Array.isArray(payload)) {
            state.users = payload;
        }
    } catch (error) {
        console.error('Error loading users:', error);
    }
};

const buildQueryParams = () => {
    const params: Record<string, string | number> = {};
    if (state.selectedUserId) params.user_id = state.selectedUserId;
    if (state.startDate) params.start_date = state.startDate;
    if (state.endDate) params.end_date = state.endDate;
    return params;
};

const loadEfficiencyData = async () => {
    state.loading = true;
    try {
        const response = await api.get('/admin/analytics/efficiency', { params: buildQueryParams() });
        state.summary = response.data.summary;
        state.tasks = response.data.tasks;
        state.userEfficiency = response.data.user_efficiency;
        state.trendData = response.data.trend;
    } catch (error: any) {
        console.error('Error loading efficiency data:', error);
        alert(error?.response?.data?.error || 'Failed to load efficiency data');
    } finally {
        state.loading = false;
    }
};

const loadWorkHourGaps = async () => {
    state.gaps.loading = true;
    try {
        const response = await api.get('/admin/analytics/utilization/gaps', { params: buildQueryParams() });
        state.gaps.data = response.data.data ?? response.data ?? [];
    } catch (error: any) {
        console.error('Error loading gap data:', error);
        alert(error?.response?.data?.error || 'Failed to load work hour gaps');
    } finally {
        state.gaps.loading = false;
    }
};

const loadUtilization = async () => {
    state.utilization.loading = true;
    try {
        const response = await api.get('/admin/analytics/utilization/summary', { params: buildQueryParams() });
        state.utilization.data = response.data.data ?? response.data ?? [];
    } catch (error: any) {
        console.error('Error loading utilization data:', error);
        alert(error?.response?.data?.error || 'Failed to load utilization metrics');
    } finally {
        state.utilization.loading = false;
    }
};

const loadAttendance = async () => {
    state.attendance.loading = true;
    try {
        const response = await api.get('/admin/analytics/attendance/overview', { params: buildQueryParams() });
        state.attendance.data = response.data.data ?? response.data ?? [];
    } catch (error: any) {
        console.error('Error loading attendance data:', error);
        alert(error?.response?.data?.error || 'Failed to load attendance metrics');
    } finally {
        state.attendance.loading = false;
    }
};

const applyFilters = () => {
    if (state.activeTab === 'efficiency') {
        loadEfficiencyData();
    } else if (state.activeTab === 'work-hour-gaps') {
        loadWorkHourGaps();
    } else if (state.activeTab === 'utilization') {
        loadUtilization();
    } else if (state.activeTab === 'attendance') {
        loadAttendance();
    }
};

const clearFilters = () => {
    state.selectedUserId = null;
    const today = new Date();
    const thirtyDaysAgo = new Date(today);
    thirtyDaysAgo.setDate(today.getDate() - 30);
    state.endDate = today.toISOString().split('T')[0];
    state.startDate = thirtyDaysAgo.toISOString().split('T')[0];
    applyFilters();
};

onMounted(() => {
    const today = new Date();
    const thirtyDaysAgo = new Date(today);
    thirtyDaysAgo.setDate(today.getDate() - 30);
    state.endDate = today.toISOString().split('T')[0];
    state.startDate = thirtyDaysAgo.toISOString().split('T')[0];
    loadUsers();
    loadEfficiencyData();
});

watch(
    () => state.activeTab,
    (tab) => {
        if (tab === 'efficiency') {
            if (!state.summary) {
                loadEfficiencyData();
            }
        } else if (tab === 'work-hour-gaps') {
            if (state.gaps.data.length === 0) {
                loadWorkHourGaps();
            }
        } else if (tab === 'utilization') {
            if (state.utilization.data.length === 0) {
                loadUtilization();
            }
        } else if (tab === 'attendance') {
            if (state.attendance.data.length === 0) {
                loadAttendance();
            }
        }
    }
);

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
const userChartData = computed(() => ({
    labels: state.userEfficiency.map((u) => u.user),
    datasets: [
        {
            label: 'Efficiency (%)',
            data: state.userEfficiency.map((u) => u.efficiency),
            backgroundColor: state.userEfficiency.map((u) => {
                if (u.efficiency >= 100) return 'rgba(34, 197, 94, 0.8)';
                if (u.efficiency >= 90) return 'rgba(234, 179, 8, 0.8)';
                return 'rgba(239, 68, 68, 0.8)';
            }),
            borderColor: state.userEfficiency.map((u) => {
                if (u.efficiency >= 100) return 'rgba(34, 197, 94, 1)';
                if (u.efficiency >= 90) return 'rgba(234, 179, 8, 1)';
                return 'rgba(239, 68, 68, 1)';
            }),
            borderWidth: 1,
        },
    ],
}));

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
const trendChartData = computed(() => ({
    labels: state.trendData.map((t) => t.week),
    datasets: [
        {
            label: 'Average Efficiency (%)',
            data: state.trendData.map((t) => t.efficiency),
            borderColor: 'rgba(59, 130, 246, 1)',
            backgroundColor: 'rgba(59, 130, 246, 0.1)',
            borderWidth: 2,
            fill: true,
            tension: 0.4,
        },
    ],
}));

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

const formatDateLabel = (date: string) =>
    new Date(date).toLocaleDateString('en-US', { month: 'short', day: '2-digit', year: 'numeric' });

const formatHoursValue = (value: number) => `${value.toFixed(2)} hrs`;

const gapDifference = (worked: number, task: number) => worked - task;

const formatPercent = (value: number) => `${value.toFixed(2)}%`;
</script>

<template>
    <Head title="Metrics" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">Metrics</h2>
        </template>

        <div class="py-12">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
                <!-- Global Filters -->
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

                <!-- Tab Switcher -->
                <div class="bg-white shadow rounded-lg px-4 py-3">
                    <nav class="flex flex-wrap gap-2">
                        <button
                            v-for="tabOption in tabs"
                            :key="tabOption.key"
                            @click="activeTab = tabOption.key"
                            class="px-4 py-2 rounded-md text-sm font-medium transition"
                            :class="activeTab === tabOption.key
                                ? 'bg-indigo-600 text-white'
                                : 'bg-gray-100 text-gray-700 hover:bg-gray-200'"
                        >
                            {{ tabOption.label }}
                        </button>
                    </nav>
                </div>

                <!-- Efficiency Tab -->
                <section v-if="activeTab === 'efficiency'" class="space-y-6">
                    <div v-if="isEfficiencyLoading" class="bg-white shadow rounded-lg p-6 text-center text-gray-500">
                        <div class="inline-block animate-spin rounded-full h-12 w-12 border-b-2 border-indigo-600"></div>
                        <p class="mt-4">Loading efficiency data…</p>
                    </div>
                    <template v-else>
                        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
                            <div class="bg-white overflow-hidden shadow rounded-lg">
                                <div class="p-5">
                                    <dt class="text-sm font-medium text-gray-500">Average Efficiency</dt>
                                    <dd class="text-2xl font-semibold text-gray-900 mt-1">
                                        {{ efficiencySummary?.average_efficiency?.toFixed(2) || '0.00' }}%
                                    </dd>
                                </div>
                            </div>
                            <div class="bg-white overflow-hidden shadow rounded-lg">
                                <div class="p-5">
                                    <dt class="text-sm font-medium text-gray-500">Total Estimated Time</dt>
                                    <dd class="text-2xl font-semibold text-gray-900 mt-1">
                                        {{ efficiencySummary?.total_estimated_time?.toFixed(2) || '0.00' }} hrs
                                    </dd>
                                </div>
                            </div>
                            <div class="bg-white overflow-hidden shadow rounded-lg">
                                <div class="p-5">
                                    <dt class="text-sm font-medium text-gray-500">Total Tracked Time</dt>
                                    <dd class="text-2xl font-semibold text-gray-900 mt-1">
                                        {{ efficiencySummary?.total_tracked_time?.toFixed(2) || '0.00' }} hrs
                                    </dd>
                                </div>
                            </div>
                            <div class="bg-white overflow-hidden shadow rounded-lg">
                                <div class="p-5">
                                    <dt class="text-sm font-medium text-gray-500">Overrun Rate</dt>
                                    <dd class="text-2xl font-semibold text-gray-900 mt-1">
                                        {{ efficiencySummary?.overrun_rate?.toFixed(2) || '0.00' }}%
                                    </dd>
                                </div>
                            </div>
                        </div>

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
                                            <tr v-for="task in efficiencyTasks" :key="task.id">
                                                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">{{ task.task_name }}</td>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">{{ task.assigned_to }}</td>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">{{ task.project }}</td>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">{{ task.estimated_time.toFixed(2) }} hrs</td>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">{{ task.tracked_time.toFixed(2) }} hrs</td>
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
                                            <tr v-if="efficiencyTasks.length === 0">
                                                <td colspan="7" class="px-6 py-4 text-center text-sm text-gray-500">No tasks with estimated time found.</td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>

                        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                            <div class="bg-white shadow rounded-lg overflow-hidden">
                                <div class="px-4 py-5 sm:p-6" style="height: 400px;">
                                    <Bar v-if="efficiencyUserEfficiency.length > 0" :data="userChartData" :options="userChartOptions" />
                                    <div v-else class="flex items-center justify-center h-full text-gray-500">No user efficiency data available</div>
                                </div>
                            </div>
                            <div class="bg-white shadow rounded-lg overflow-hidden">
                                <div class="px-4 py-5 sm:p-6" style="height: 400px;">
                                    <Line v-if="efficiencyTrend.length > 0" :data="trendChartData" :options="trendChartOptions" />
                                    <div v-else class="flex items-center justify-center h-full text-gray-500">No trend data available</div>
                                </div>
                            </div>
                        </div>
                    </template>
                </section>

                <!-- Work Hour Gaps Tab -->
                <section v-else-if="activeTab === 'work-hour-gaps'" class="space-y-6">
                    <div class="bg-white shadow rounded-lg p-6">
                        <h3 class="text-lg font-medium text-gray-900">Work Hour Gaps</h3>
                        <p class="text-sm text-gray-500 mb-4">
                            Compare total time tracked via clock-ins against task-tracked hours to highlight potential idle time.
                        </p>
                        <div v-if="isGapLoading" class="text-center text-gray-500 py-12">
                            <div class="inline-block animate-spin rounded-full h-10 w-10 border-b-2 border-indigo-600"></div>
                            <p class="mt-3">Loading work hour gaps…</p>
                        </div>
                        <template v-else>
                            <div v-if="gapData.length === 0" class="text-sm text-gray-500">No gaps recorded for the selected range.</div>
                            <div v-else class="overflow-x-auto">
                                <table class="min-w-full divide-y divide-gray-200">
                                    <thead class="bg-gray-50">
                                        <tr>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Date</th>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">User</th>
                                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Worked Hours</th>
                                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Task Hours</th>
                                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Gap</th>
                                        </tr>
                                    </thead>
                                    <tbody class="bg-white divide-y divide-gray-200">
                                        <tr v-for="row in gapData" :key="`${row.user}-${row.date}`">
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">{{ formatDateLabel(row.date) }}</td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">{{ row.user }}</td>
                                            <td class="px-6 py-4 whitespace-nowrap text-right text-sm text-gray-700">{{ formatHoursValue(row.worked_hours) }}</td>
                                            <td class="px-6 py-4 whitespace-nowrap text-right text-sm text-gray-700">{{ formatHoursValue(row.task_hours) }}</td>
                                            <td class="px-6 py-4 whitespace-nowrap text-right text-sm" :class="gapDifference(row.worked_hours, row.task_hours) >= 0 ? 'text-green-600' : 'text-red-600'">
                                                {{ formatHoursValue(gapDifference(row.worked_hours, row.task_hours)) }}
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </template>
                    </div>
                </section>

                <!-- Utilization Tab -->
                <section v-else-if="activeTab === 'utilization'" class="space-y-6">
                    <div class="bg-white shadow rounded-lg p-6">
                        <h3 class="text-lg font-medium text-gray-900">Utilization</h3>
                        <p class="text-sm text-gray-500 mb-4">
                            Measures the percentage of available hours spent on productive, task-tracked work.
                        </p>
                        <div v-if="isUtilizationLoading" class="text-center text-gray-500 py-12">
                            <div class="inline-block animate-spin rounded-full h-10 w-10 border-b-2 border-indigo-600"></div>
                            <p class="mt-3">Loading utilization metrics…</p>
                        </div>
                        <template v-else>
                            <div v-if="utilizationData.length === 0" class="text-sm text-gray-500">No utilization data for the selected filters.</div>
                            <div v-else class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                                <div
                                    v-for="row in utilizationData"
                                    :key="row.user"
                                    class="rounded-lg border border-indigo-100 bg-indigo-50 p-4"
                                >
                                    <p class="text-sm font-medium text-indigo-700">{{ row.user }}</p>
                                    <p class="text-2xl font-semibold text-indigo-900 mt-2">{{ formatPercent(row.percent) }}</p>
                                </div>
                            </div>
                        </template>
                    </div>
                </section>

                <!-- Attendance Tab -->
                <section v-else class="space-y-6">
                    <div class="bg-white shadow rounded-lg p-6">
                        <h3 class="text-lg font-medium text-gray-900">Attendance Metrics</h3>
                        <p class="text-sm text-gray-500 mb-4">
                            Snapshot of perfect attendance days, late arrivals, and absences within the selected timeframe.
                        </p>
                        <div v-if="isAttendanceLoading" class="text-center text-gray-500 py-12">
                            <div class="inline-block animate-spin rounded-full h-10 w-10 border-b-2 border-indigo-600"></div>
                            <p class="mt-3">Loading attendance metrics…</p>
                        </div>
                        <template v-else>
                            <div v-if="attendanceData.length === 0" class="text-sm text-gray-500">No attendance metrics available.</div>
                            <div v-else class="overflow-x-auto">
                                <table class="min-w-full divide-y divide-gray-200">
                                    <thead class="bg-gray-50">
                                        <tr>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">User</th>
                                            <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Perfect Days</th>
                                            <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Late Days</th>
                                            <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Absences</th>
                                        </tr>
                                    </thead>
                                    <tbody class="bg-white divide-y divide-gray-200">
                                        <tr v-for="row in attendanceData" :key="row.user">
                                            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">{{ row.user }}</td>
                                            <td class="px-6 py-4 whitespace-nowrap text-center text-sm text-green-600 font-semibold">{{ row.perfect_days }}</td>
                                            <td class="px-6 py-4 whitespace-nowrap text-center text-sm text-yellow-600 font-semibold">{{ row.late_days }}</td>
                                            <td class="px-6 py-4 whitespace-nowrap text-center text-sm text-red-600 font-semibold">{{ row.absence_days }}</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </template>
                    </div>
                </section>
            </div>
        </div>
    </AuthenticatedLayout>
</template>

