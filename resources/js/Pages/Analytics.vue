<script setup lang="ts">
import { ref, onMounted, computed } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head } from '@inertiajs/vue3';
import api from '@/api';

interface TimeEntry {
    id: number;
    date: string;
    clock_in: string | null;
    clock_out: string | null;
    break_start: string | null;
    break_end: string | null;
    lunch_start: string | null;
    lunch_end: string | null;
    total_hours: number;
    user?: { id: number; name: string; email: string; team?: { id: number; name: string; } };
    task?: { id: number; name: string; clickup_id: string | null; };
}

interface User {
    id: number;
    name: string;
    email: string;
}

interface Team {
    id: number;
    name: string;
    description?: string;
}

interface ActivityLog {
    id: number;
    user_id: number;
    action: string;
    description: string;
    metadata: any;
    created_at: string;
    user?: { id: number; name: string; email: string; };
}

const activeTab = ref('overview');
const loading = ref(false);

// Overview Tab Data
const overviewPeriod = ref('month');
const overviewData = ref<any>(null);
const overviewLoading = ref(false);

// Individual Entries Tab Data
const selectedUser = ref<any>(null);
const selectedTeam = ref<any>(null);
const startDate = ref('');
const endDate = ref('');
const statusFilter = ref('');
const allUsers = ref<User[]>([]);
const allTeams = ref<Team[]>([]);
const entries = ref<TimeEntry[]>([]);
const entriesLoading = ref(false);

// User Summary Tab Data
const userSummary = ref<any[]>([]);

// Activity Log Tab Data
const activityLogs = ref<ActivityLog[]>([]);
const activityLogsLoading = ref(false);
const selectedActionFilter = ref('');
const lastPollTimestamp = ref<number>(Date.now());

onMounted(() => {
    const today = new Date();
    const firstDay = new Date(today.getFullYear(), today.getMonth(), 1);
    const lastDay = new Date(today.getFullYear(), today.getMonth() + 1, 0);
    
    startDate.value = firstDay.toISOString().split('T')[0];
    endDate.value = lastDay.toISOString().split('T')[0];
    
    loadOverviewData();
    loadUsers();
    loadTeams();
    loadEntries();
    loadActivityLogs();
    
    // Set up polling for activity logs every 5 seconds
    setInterval(() => {
        if (activeTab.value === 'activity') {
            loadActivityLogs(true);
        }
    }, 5000);
});

const loadOverviewData = async () => {
    overviewLoading.value = true;
    try {
        const response = await api.get('/admin/analytics/overview', {
            params: { period: overviewPeriod.value }
        });
        overviewData.value = response.data;
    } catch (error) {
        console.error('Error loading overview:', error);
        alert('Failed to load overview data');
    } finally {
        overviewLoading.value = false;
    }
};

const loadUsers = async () => {
    try {
        const response = await api.get('/admin/analytics/users');
        allUsers.value = response.data;
    } catch (error) {
        console.error('Error loading users:', error);
    }
};

const loadTeams = async () => {
    try {
        const response = await api.get('/teams');
        allTeams.value = response.data.data || response.data;
    } catch (error) {
        console.error('Error loading teams:', error);
    }
};

const loadEntries = async () => {
    entriesLoading.value = true;
    try {
        const params: any = {
            start_date: startDate.value,
            end_date: endDate.value,
        };
        if (selectedUser.value) {
            params.user_id = selectedUser.value;
        }
        if (selectedTeam.value) {
            params.team_id = selectedTeam.value;
        }
        if (statusFilter.value) {
            params.status = statusFilter.value;
        }
        
        const response = await api.get('/admin/analytics/individual-entries', { params });
        entries.value = response.data.data;
    } catch (error) {
        console.error('Error loading entries:', error);
        alert('Failed to load entries');
    } finally {
        entriesLoading.value = false;
    }
};

const loadActivityLogs = async (silent = false) => {
    if (!silent) {
        activityLogsLoading.value = true;
    }
    try {
        const params: any = {
            limit: 100,
        };
        if (selectedActionFilter.value) {
            params.action = selectedActionFilter.value;
        }
        
        const response = await api.get('/admin/analytics/activity-logs', { params });
        activityLogs.value = response.data.data;
        lastPollTimestamp.value = Date.now();
    } catch (error) {
        console.error('Error loading activity logs:', error);
        if (!silent) {
            alert('Failed to load activity logs');
        }
    } finally {
        activityLogsLoading.value = false;
    }
};

const formatDate = (date: string) => {
    return new Date(date).toLocaleDateString('en-US', { 
        year: 'numeric', 
        month: 'short', 
        day: 'numeric' 
    });
};

const formatTime = (time: string | null) => {
    if (!time) return '--';
    return new Date(time).toLocaleTimeString('en-US', { 
        hour: '2-digit', 
        minute: '2-digit'
    });
};

const formatHoursToHMS = (hours: number | string) => {
    const totalSeconds = Math.round(Number(hours) * 3600);
    const h = Math.floor(totalSeconds / 3600);
    const m = Math.floor((totalSeconds % 3600) / 60);
    const s = totalSeconds % 60;
    const pad = (n: number) => (n < 10 ? `0${n}` : `${n}`);
    return `${pad(h)}h ${pad(m)}m ${pad(s)}s`;
};

const exportCsv = () => {
    const params = new URLSearchParams({
        period: overviewPeriod.value,
        start_date: startDate.value,
        end_date: endDate.value,
    });
    window.location.href = `/api/admin/analytics/export/csv?${params.toString()}`;
};

const exportPdf = () => {
    const params = new URLSearchParams({
        period: overviewPeriod.value,
        start_date: startDate.value,
        end_date: endDate.value,
    });
    window.location.href = `/api/admin/analytics/export/pdf?${params.toString()}`;
};
</script>

<template>
    <Head title="Analytics" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">
                Analytics & Reports
            </h2>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-7xl sm:px-6 lg:px-8">
                <!-- Tab Navigation -->
                <div class="border-b border-gray-200 mb-6">
                    <nav class="-mb-px flex space-x-8">
                        <button
                            @click="activeTab = 'overview'"
                            :class="[
                                'whitespace-nowrap border-b-2 py-4 px-1 text-sm font-medium',
                                activeTab === 'overview'
                                    ? 'border-indigo-500 text-indigo-600'
                                    : 'border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700'
                            ]"
                        >
                            Overview
                        </button>
                        <button
                            @click="activeTab = 'individual'"
                            :class="[
                                'whitespace-nowrap border-b-2 py-4 px-1 text-sm font-medium',
                                activeTab === 'individual'
                                    ? 'border-indigo-500 text-indigo-600'
                                    : 'border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700'
                            ]"
                        >
                            Individual Entries
                        </button>
                        <button
                            @click="activeTab = 'summary'"
                            :class="[
                                'whitespace-nowrap border-b-2 py-4 px-1 text-sm font-medium',
                                activeTab === 'summary'
                                    ? 'border-indigo-500 text-indigo-600'
                                    : 'border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700'
                            ]"
                        >
                            User Summary
                        </button>
                        <button
                            @click="activeTab = 'activity'; loadActivityLogs()"
                            :class="[
                                'whitespace-nowrap border-b-2 py-4 px-1 text-sm font-medium',
                                activeTab === 'activity'
                                    ? 'border-indigo-500 text-indigo-600'
                                    : 'border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700'
                            ]"
                        >
                            Activity Log
                        </button>
                    </nav>
                </div>

                <!-- Overview Tab -->
                <div v-if="activeTab === 'overview'" class="space-y-6">
                    <!-- Period Selector and Export Buttons -->
                    <div class="bg-white shadow rounded-lg p-4">
                        <div class="flex justify-between items-end gap-4">
                            <div class="flex-1">
                                <label class="block text-sm font-medium text-gray-700 mb-2">Period</label>
                                <select 
                                    v-model="overviewPeriod" 
                                    @change="loadOverviewData"
                                    class="rounded-md border-gray-300 shadow-sm"
                                >
                                    <option value="daily">Daily</option>
                                    <option value="weekly">Weekly</option>
                                    <option value="monthly">Monthly</option>
                                    <option value="ytd">Year to Date</option>
                                </select>
                            </div>
                            <div class="flex gap-2">
                                <button
                                    @click="exportCsv"
                                    class="rounded-md bg-green-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-green-500"
                                >
                                    Export CSV
                                </button>
                                <button
                                    @click="exportPdf"
                                    class="rounded-md bg-red-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-red-500"
                                >
                                    Export PDF
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Statistics Cards -->
                    <div v-if="overviewData && !overviewLoading" class="grid gap-6 sm:grid-cols-2 lg:grid-cols-4">
                        <div class="bg-white overflow-hidden shadow rounded-lg">
                            <div class="p-5">
                                <div class="flex items-center">
                                    <div class="flex-shrink-0">
                                        <div class="h-8 w-8 rounded-full bg-blue-500 flex items-center justify-center">
                                            <svg class="h-5 w-5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z" />
                                            </svg>
                                        </div>
                                    </div>
                                    <div class="ml-5 w-0 flex-1">
                                        <dl>
                                            <dt class="text-sm font-medium text-gray-500 truncate">Total Employees</dt>
                                            <dd class="text-2xl font-semibold text-gray-900">{{ overviewData.statistics.total_employees }}</dd>
                                        </dl>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="bg-white overflow-hidden shadow rounded-lg">
                            <div class="p-5">
                                <div class="flex items-center">
                                    <div class="flex-shrink-0">
                                        <div class="h-8 w-8 rounded-full bg-green-500 flex items-center justify-center">
                                            <svg class="h-5 w-5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                                            </svg>
                                        </div>
                                    </div>
                                    <div class="ml-5 w-0 flex-1">
                                        <dl>
                                            <dt class="text-sm font-medium text-gray-500 truncate">Total Hours</dt>
                                            <dd class="text-2xl font-semibold text-gray-900">{{ formatHoursToHMS(overviewData.statistics.total_hours) }}</dd>
                                        </dl>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="bg-white overflow-hidden shadow rounded-lg">
                            <div class="p-5">
                                <div class="flex items-center">
                                    <div class="flex-shrink-0">
                                        <div class="h-8 w-8 rounded-full bg-yellow-500 flex items-center justify-center">
                                            <svg class="h-5 w-5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" />
                                            </svg>
                                        </div>
                                    </div>
                                    <div class="ml-5 w-0 flex-1">
                                        <dl>
                                            <dt class="text-sm font-medium text-gray-500 truncate">Lates</dt>
                                            <dd class="text-2xl font-semibold text-gray-900">{{ overviewData.statistics.lates_count }}</dd>
                                        </dl>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="bg-white overflow-hidden shadow rounded-lg">
                            <div class="p-5">
                                <div class="flex items-center">
                                    <div class="flex-shrink-0">
                                        <div class="h-8 w-8 rounded-full bg-purple-500 flex items-center justify-center">
                                            <svg class="h-5 w-5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                                            </svg>
                                        </div>
                                    </div>
                                    <div class="ml-5 w-0 flex-1">
                                        <dl>
                                            <dt class="text-sm font-medium text-gray-500 truncate">Overtime</dt>
                                            <dd class="text-2xl font-semibold text-gray-900">{{ overviewData.statistics.overtime_count }}</dd>
                                        </dl>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Additional Metrics -->
                    <div v-if="overviewData && !overviewLoading" class="grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
                        <div class="bg-white overflow-hidden shadow rounded-lg p-5">
                            <h4 class="text-sm font-medium text-gray-500 mb-2">Average Hours</h4>
                            <p class="text-3xl font-semibold text-gray-900">{{ formatHoursToHMS(overviewData.statistics.average_hours) }}</p>
                        </div>
                        <div class="bg-white overflow-hidden shadow rounded-lg p-5">
                            <h4 class="text-sm font-medium text-gray-500 mb-2">Perfect Attendance</h4>
                            <p class="text-3xl font-semibold text-gray-900">{{ overviewData.statistics.perfect_attendance_count }}</p>
                        </div>
                        <div class="bg-white overflow-hidden shadow rounded-lg p-5">
                            <h4 class="text-sm font-medium text-gray-500 mb-2">Undertime</h4>
                            <p class="text-3xl font-semibold text-gray-900">{{ overviewData.statistics.undertime_count }}</p>
                        </div>
                    </div>

                    <!-- Top Employees -->
                    <div v-if="overviewData && !overviewLoading" class="bg-white shadow rounded-lg">
                        <div class="px-4 py-5 sm:p-6">
                            <h3 class="text-lg font-medium leading-6 text-gray-900 mb-4">Top Employees</h3>
                            <table class="min-w-full divide-y divide-gray-200">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Name</th>
                                        <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Total Hours</th>
                                        <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Entries</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-200">
                                    <tr v-for="employee in overviewData.top_employees" :key="employee.id">
                                        <td class="px-4 py-4 text-sm text-gray-900">{{ employee.name }}</td>
                                        <td class="px-4 py-4 text-sm text-gray-500">{{ formatHoursToHMS(employee.total_hours) }}</td>
                                        <td class="px-4 py-4 text-sm text-gray-500">{{ employee.entries_count }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div v-if="overviewLoading" class="text-center py-12">
                        <div class="inline-block animate-spin rounded-full h-8 w-8 border-b-2 border-indigo-600"></div>
                        <p class="mt-2 text-sm text-gray-500">Loading...</p>
                    </div>
                </div>

                <!-- Individual Entries Tab -->
                <div v-if="activeTab === 'individual'" class="space-y-6">
                    <!-- Filters -->
                    <div class="bg-white shadow rounded-lg p-4">
                        <div class="flex justify-between items-center mb-4">
                            <h3 class="text-lg font-medium text-gray-900">Filters</h3>
                            <div class="flex gap-2">
                                <button
                                    @click="exportCsv"
                                    class="rounded-md bg-green-600 px-3 py-1.5 text-xs font-medium text-white shadow-sm hover:bg-green-500"
                                >
                                    Export CSV
                                </button>
                                <button
                                    @click="exportPdf"
                                    class="rounded-md bg-red-600 px-3 py-1.5 text-xs font-medium text-white shadow-sm hover:bg-red-500"
                                >
                                    Export PDF
                                </button>
                            </div>
                        </div>
                        <div class="grid gap-4 sm:grid-cols-5">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Team</label>
                                <select v-model="selectedTeam" @change="loadEntries" class="w-full rounded-md border-gray-300 shadow-sm">
                                    <option :value="null">All Teams</option>
                                    <option v-for="team in allTeams" :key="team.id" :value="team.id">{{ team.name }}</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">User</label>
                                <select v-model="selectedUser" @change="loadEntries" class="w-full rounded-md border-gray-300 shadow-sm">
                                    <option :value="null">All Users</option>
                                    <option v-for="user in allUsers" :key="user.id" :value="user.id">{{ user.name }}</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Start Date</label>
                                <input v-model="startDate" @change="loadEntries" type="date" class="w-full rounded-md border-gray-300 shadow-sm" />
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">End Date</label>
                                <input v-model="endDate" @change="loadEntries" type="date" class="w-full rounded-md border-gray-300 shadow-sm" />
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Status</label>
                                <select v-model="statusFilter" @change="loadEntries" class="w-full rounded-md border-gray-300 shadow-sm">
                                    <option value="">All</option>
                                    <option value="late">Late</option>
                                    <option value="undertime">Undertime</option>
                                    <option value="overtime">Overtime</option>
                                    <option value="perfect">Perfect</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <!-- Entries Table -->
                    <div class="bg-white shadow rounded-lg">
                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Employee</th>
                                        <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Team</th>
                                        <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Task</th>
                                        <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Date</th>
                                        <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Clock In</th>
                                        <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Clock Out</th>
                                        <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Total Hours</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-200">
                                    <tr v-for="entry in entries" :key="entry.id">
                                        <td class="px-4 py-4 text-sm text-gray-900">{{ entry.user?.name }}</td>
                                        <td class="px-4 py-4 text-sm text-gray-500">{{ entry.user?.team?.name || '--' }}</td>
                                        <td class="px-4 py-4 text-sm text-gray-500">{{ entry.task?.name || '--' }}</td>
                                        <td class="px-4 py-4 text-sm text-gray-500">{{ formatDate(entry.date) }}</td>
                                        <td class="px-4 py-4 text-sm text-gray-500">{{ formatTime(entry.clock_in) }}</td>
                                        <td class="px-4 py-4 text-sm text-gray-500">{{ formatTime(entry.clock_out) }}</td>
                                        <td class="px-4 py-4 text-sm font-semibold text-gray-900">{{ formatHoursToHMS(entry.total_hours) }}</td>
                                    </tr>
                                    <tr v-if="entries.length === 0 && !entriesLoading">
                                        <td colspan="7" class="px-4 py-4 text-center text-sm text-gray-500">No entries found</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <div v-if="entriesLoading" class="text-center py-8">
                            <div class="inline-block animate-spin rounded-full h-8 w-8 border-b-2 border-indigo-600"></div>
                            <p class="mt-2 text-sm text-gray-500">Loading...</p>
                        </div>
                    </div>
                </div>

                <!-- User Summary Tab -->
                <div v-if="activeTab === 'summary'" class="text-center py-12 text-gray-500">
                    <p>User Summary functionality coming soon...</p>
                </div>

                <!-- Activity Log Tab -->
                <div v-if="activeTab === 'activity'" class="space-y-6">
                    <!-- Filters -->
                    <div class="bg-white shadow rounded-lg p-4">
                        <div class="flex justify-between items-center mb-4">
                            <h3 class="text-lg font-medium text-gray-900">Real-Time Activity Log</h3>
                            <div class="flex items-center gap-2">
                                <div class="flex items-center gap-2 text-sm text-gray-600">
                                    <span class="inline-block w-2 h-2 bg-green-500 rounded-full animate-pulse"></span>
                                    <span>Live Updates</span>
                                </div>
                                <button
                                    @click="loadActivityLogs()"
                                    class="rounded-md bg-indigo-600 px-3 py-1.5 text-xs font-medium text-white shadow-sm hover:bg-indigo-500"
                                >
                                    Refresh
                                </button>
                            </div>
                        </div>
                        <div class="grid gap-4 sm:grid-cols-2">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Filter by Action</label>
                                <select v-model="selectedActionFilter" @change="loadActivityLogs()" class="w-full rounded-md border-gray-300 shadow-sm">
                                    <option value="">All Actions</option>
                                    <option value="clock_in">Clock In</option>
                                    <option value="clock_out">Clock Out</option>
                                    <option value="break_start">Break Start</option>
                                    <option value="break_end">Break End</option>
                                    <option value="lunch_start">Lunch Start</option>
                                    <option value="lunch_end">Lunch End</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <!-- Activity Log Table -->
                    <div class="bg-white shadow rounded-lg">
                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">User</th>
                                        <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Action</th>
                                        <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Description</th>
                                        <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Time</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-200 bg-white">
                                    <tr v-for="log in activityLogs" :key="log.id" class="hover:bg-gray-50">
                                        <td class="px-4 py-4 whitespace-nowrap">
                                            <div class="flex items-center">
                                                <div class="flex-shrink-0 h-10 w-10">
                                                    <div class="h-10 w-10 rounded-full bg-indigo-100 flex items-center justify-center">
                                                        <span class="text-indigo-600 font-medium">{{ log.user?.name ? log.user.name.charAt(0).toUpperCase() : '?' }}</span>
                                                    </div>
                                                </div>
                                                <div class="ml-4">
                                                    <div class="text-sm font-medium text-gray-900">{{ log.user?.name || 'Unknown User' }}</div>
                                                    <div class="text-sm text-gray-500">{{ log.user?.email }}</div>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="px-4 py-4 whitespace-nowrap">
                                            <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full" :class="{
                                                'bg-green-100 text-green-800': log.action === 'clock_in',
                                                'bg-red-100 text-red-800': log.action === 'clock_out',
                                                'bg-yellow-100 text-yellow-800': log.action.includes('break'),
                                                'bg-blue-100 text-blue-800': log.action.includes('lunch')
                                            }">
                                                {{ log.action.replace('_', ' ').replace(/\b\w/g, l => l.toUpperCase()) }}
                                            </span>
                                        </td>
                                        <td class="px-4 py-4">
                                            <div class="text-sm text-gray-900">{{ log.description }}</div>
                                        </td>
                                        <td class="px-4 py-4 whitespace-nowrap text-sm text-gray-500">
                                            {{ formatDate(log.created_at) }}
                                            <div class="text-xs text-gray-400">{{ formatTime(log.created_at) }}</div>
                                        </td>
                                    </tr>
                                    <tr v-if="activityLogs.length === 0 && !activityLogsLoading">
                                        <td colspan="4" class="px-4 py-4 text-center text-sm text-gray-500">No activity logs found</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <div v-if="activityLogsLoading" class="text-center py-8">
                            <div class="inline-block animate-spin rounded-full h-8 w-8 border-b-2 border-indigo-600"></div>
                            <p class="mt-2 text-sm text-gray-500">Loading...</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
