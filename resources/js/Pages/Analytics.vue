<script setup lang="ts">
import { ref, onMounted, computed, watch } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head } from '@inertiajs/vue3';
import api from '@/api';

interface SummaryResponse {
    generated_at: string;
    dates: {
        today: string;
        week_start: string;
        week_end: string;
    };
    totals: {
        hours_today: number;
        hours_week: number;
        active_users_today: number;
        active_users_week: number;
        average_hours_per_user_week: number;
        open_entries: number;
        late_clock_ins_today: number;
        total_entries_today: number;
    };
    open_entries: Array<{
        entry_id: number;
        user: {
            id?: number | null;
            name?: string | null;
            email?: string | null;
            team?: string | null;
        };
        clock_in: string | null;
        minutes_running: number | null;
    }>;
}

interface MetricCard {
    title: string;
    value: string;
    subtitle: string;
    accent: string;
}

const summary = ref<SummaryResponse | null>(null);
const loading = ref(true);
const error = ref<string | null>(null);
const refreshing = ref(false);

const fetchSummary = async () => {
    try {
        error.value = null;
        const { data } = await api.get<SummaryResponse>('/admin/analytics/summary');
        summary.value = data;
    } catch (e: any) {
        console.error(e);
        error.value = e?.response?.data?.error || 'Unable to load analytics summary.';
        summary.value = null;
    } finally {
        loading.value = false;
        refreshing.value = false;
    }
};

const refresh = async () => {
    refreshing.value = true;
    await fetchSummary();
};

const formatHours = (value: number) => `${value.toFixed(2)} h`;
const formatCount = (value: number) => value.toLocaleString();
const formatDate = (date: string) =>
    new Date(date).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
const formatTimestamp = (iso: string) =>
    new Date(iso).toLocaleString('en-US', {
        month: 'short',
        day: 'numeric',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
const formatMinutes = (minutes: number | null) => {
    if (minutes === null) return '--';
    const hrs = Math.floor(minutes / 60);
    const mins = minutes % 60;
    if (hrs === 0) return `${mins}m`;
    return `${hrs}h ${mins}m`;
};
const formatClockIn = (iso: string | null) => {
    if (!iso) return '--';
    return new Date(iso).toLocaleString('en-US', { hour: 'numeric', minute: '2-digit' });
};

const metricCards = computed<MetricCard[]>(() => {
    if (!summary.value) return [];
    const totals = summary.value.totals;
    return [
        {
            title: 'Hours Logged Today',
            value: formatHours(totals.hours_today),
            subtitle: 'Completed work hours',
            accent: 'border-blue-500 bg-blue-50 text-blue-700',
        },
        {
            title: 'Hours Logged This Week',
            value: formatHours(totals.hours_week),
            subtitle: 'Mon–Sun total',
            accent: 'border-indigo-500 bg-indigo-50 text-indigo-700',
        },
        {
            title: 'Active Users Today',
            value: formatCount(totals.active_users_today),
            subtitle: 'Unique employees with activity',
            accent: 'border-emerald-500 bg-emerald-50 text-emerald-700',
        },
        {
            title: 'Active Users This Week',
            value: formatCount(totals.active_users_week),
            subtitle: 'Unique employees this week',
            accent: 'border-teal-500 bg-teal-50 text-teal-700',
        },
        {
            title: 'Avg Hours / User (Week)',
            value: formatHours(totals.average_hours_per_user_week),
            subtitle: 'Hours per active user',
            accent: 'border-purple-500 bg-purple-50 text-purple-700',
        },
        {
            title: 'Open Clock-ins',
            value: formatCount(totals.open_entries),
            subtitle: 'Users still on the clock',
            accent: 'border-amber-500 bg-amber-50 text-amber-700',
        },
    ];
});

const detailList = computed(() => {
    if (!summary.value) return [];
    const totals = summary.value.totals;
    return [
        { label: 'Late clock-ins today', value: formatCount(totals.late_clock_ins_today) },
        { label: 'Completed entries today', value: formatCount(totals.total_entries_today) },
        {
            label: 'Week window',
            value: `${formatDate(summary.value.dates.week_start)} → ${formatDate(summary.value.dates.week_end)}`,
        },
        { label: 'Report generated', value: formatTimestamp(summary.value.generated_at) },
    ];
});

const hasOpenEntries = computed(() => (summary.value?.open_entries.length ?? 0) > 0);

// ------------------------------------------------------------
// Legacy tabs (Overview stats, User Summary, Activity Log)
// ------------------------------------------------------------

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
    clock_in_formatted?: string | null;
    clock_out_formatted?: string | null;
    duration_hms?: string;
    duration_hms_colon?: string;
    duration_seconds?: number;
    entry_type?: string;
    is_break?: boolean;
    task?: { id: number; name?: string; title?: string };
    user?: { id: number; name: string; email: string; team?: { id: number; name: string } };
}

interface UserSummary {
    id: number;
    name: string;
    email: string;
    role: string;
    total_hours: number;
    entries_count: number;
}

interface ActivityLog {
    id: number;
    user_id: number;
    action: string;
    description: string;
    metadata: any;
    created_at: string;
    user?: { id: number; name: string; email: string };
}

const activeTab = ref<'overview' | 'summary' | 'activity'>('overview');
const overviewPeriod = ref('month');
const overviewData = ref<any>(null);
const overviewLoading = ref(false);
const selectedUser = ref<number | null>(null);
const summaryDate = ref('');
const allUsers = ref<Array<{ id: number; name: string }>>([]);
const sessions = ref<TimeEntry[]>([]);
const sessionsLoading = ref(false);
const summaryRows = ref<
    Array<{
        name: string;
        start: string | null;
        end: string | null;
        durationSeconds: number;
        breakDurationSeconds: number;
        notes: string;
    }>
>([]);
const dailyTotals = ref({
    workSeconds: 0,
    breakSeconds: 0,
    lunchSeconds: 0,
    tasksCount: 0,
    status: 'No Entry',
    overtimeSeconds: 0,
});
const userSummary = ref<UserSummary[]>([]);
const activityLogs = ref<ActivityLog[]>([]);
const activityLogsLoading = ref(false);
const selectedActionFilter = ref('');
const lastPollTimestamp = ref<number>(Date.now());

const formatHoursToHMS = (hours: number | string) => {
    if (typeof hours === 'string' && /h\s+\d{2}m\s+\d{2}s/.test(hours)) {
        return hours;
    }
    const parsed = Number(hours);
    if (!isFinite(parsed)) return '00h 00m 00s';
    const totalSeconds = Math.round(parsed * 3600);
    const h = Math.floor(totalSeconds / 3600);
    const m = Math.floor((totalSeconds % 3600) / 60);
    const s = totalSeconds % 60;
    const pad = (n: number) => (n < 10 ? `0${n}` : `${n}`);
    return `${pad(h)}h ${pad(m)}m ${pad(s)}s`;
};

const formatSecondsToHHMMSS = (sec: number | null | undefined) => {
    const total = typeof sec === 'number' && isFinite(sec) ? Math.max(0, Math.floor(sec)) : 0;
    const h = Math.floor(total / 3600);
    const m = Math.floor((total % 3600) / 60);
    const s = total % 60;
    const pad = (n: number) => (n < 10 ? `0${n}` : `${n}`);
    return `${pad(h)}:${pad(m)}:${pad(s)}`;
};

const PH_TZ = 'Asia/Manila';

const formatTime = (time: string | null) => {
    if (!time) return '--';
    try {
        const d = new Date(time);
        if (!isNaN(d.getTime())) {
            return d.toLocaleTimeString('en-US', {
                hour: '2-digit',
                minute: '2-digit',
                hour12: true,
                timeZone: PH_TZ,
            });
        }
    } catch {
        // ignore
    }
    const match = time.match(/\b(\d{1,2}):(\d{2})(?::(\d{2}))?/);
    if (match) {
        let hh = parseInt(match[1], 10);
        const mm = match[2];
        const suffix = hh >= 12 ? 'PM' : 'AM';
        hh = hh % 12;
        if (hh === 0) hh = 12;
        return `${hh}:${mm} ${suffix}`;
    }
    return '--';
};

const parseDateTime = (s: string | null): Date | null => {
    if (!s) return null;
    const sql = s.match(/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})(?::(\d{2}))?/);
    if (sql) {
        const y = Number(sql[1]);
        const mo = Number(sql[2]) - 1;
        const d = Number(sql[3]);
        const hh = Number(sql[4]);
        const mm = Number(sql[5]);
        const ss = Number(sql[6] || '0');
        return new Date(Date.UTC(y, mo, d, hh - 8, mm, ss));
    }
    const norm = s.includes('T') ? s : s.replace(' ', 'T');
    const d2 = new Date(norm);
    return isNaN(d2.getTime()) ? null : d2;
};

const computeEntryHHMMSS = (entry: TimeEntry) => {
    if (entry.duration_hms_colon && entry.duration_hms_colon !== '00:00:00') return entry.duration_hms_colon;
    if (typeof entry.duration_seconds === 'number' && entry.duration_seconds > 0)
        return formatSecondsToHHMMSS(entry.duration_seconds);
    if (!entry.clock_in || !entry.clock_out) return '--';
    const cin = parseDateTime(entry.clock_in);
    const cout = parseDateTime(entry.clock_out);
    if (!cin || !cout) return '00:00:00';
    let seconds = Math.max(0, Math.floor((cout.getTime() - cin.getTime()) / 1000));
    const bs = parseDateTime(entry.break_start);
    const be = parseDateTime(entry.break_end);
    if (bs && be) seconds -= Math.max(0, Math.floor((be.getTime() - bs.getTime()) / 1000));
    const ls = parseDateTime(entry.lunch_start);
    const le = parseDateTime(entry.lunch_end);
    if (ls && le) seconds -= Math.max(0, Math.floor((le.getTime() - ls.getTime()) / 1000));
    return formatSecondsToHHMMSS(Math.max(0, seconds));
};

const loadOverviewData = async () => {
    overviewLoading.value = true;
    try {
        const response = await api.get('/admin/analytics/overview', {
            params: { period: overviewPeriod.value },
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
        if (!selectedUser.value && allUsers.value.length > 0) {
            selectedUser.value = allUsers.value[0].id;
        }
    } catch (error) {
        console.error('Error loading users:', error);
    }
};

const loadUserDaily = async () => {
    if (!selectedUser.value) return;
    sessionsLoading.value = true;
    try {
        const res = await api.get(`/admin/analytics/user/${selectedUser.value}`, {
            params: { start_date: summaryDate.value, end_date: summaryDate.value },
        });
        const list: TimeEntry[] = res.data?.entries || res.data || [];
        sessions.value = list;

        summaryRows.value = [];
        let rawWorkSeconds = 0;
        let totalBreakSeconds = 0;
        let firstIn: Date | null = null;

        list.forEach((entry) => {
            if (entry.entry_type === 'break' || entry.is_break) {
                const cin = parseDateTime(entry.clock_in);
                const cout = parseDateTime(entry.clock_out);
                if (cin && cout) {
                    const breakDur = Math.max(0, Math.floor((cout.getTime() - cin.getTime()) / 1000));
                    totalBreakSeconds += breakDur;
                    summaryRows.value.push({
                        name: 'Break',
                        start: entry.clock_in_formatted || entry.clock_in,
                        end: entry.clock_out_formatted || entry.clock_out,
                        durationSeconds: breakDur,
                        breakDurationSeconds: breakDur,
                        notes: '-',
                    });
                }
                return;
            }

            const cin = parseDateTime(entry.clock_in);
            const cout = parseDateTime(entry.clock_out);
            if (cin && (!firstIn || cin < firstIn)) firstIn = cin;
            const hasTask = !!entry.task && (entry.task.title || entry.task.name);
            
            if (cin && cout && !hasTask) {
                const workDur = Math.max(0, Math.floor((cout.getTime() - cin.getTime()) / 1000));
                rawWorkSeconds += workDur;
                const ls = parseDateTime(entry.lunch_start);
                const le = parseDateTime(entry.lunch_end);
                let lunchDur = 0;
                if (ls && le) lunchDur = Math.max(0, Math.floor((le.getTime() - ls.getTime()) / 1000));
                const netWorkDur = Math.max(0, workDur - lunchDur);
                summaryRows.value.push({
                    name: 'Work Hours',
                    start: entry.clock_in_formatted || entry.clock_in,
                    end: entry.clock_out_formatted || entry.clock_out,
                    durationSeconds: netWorkDur,
                    breakDurationSeconds: 0,
                    notes: '-',
                });
            }
            
            if (hasTask && cin && cout) {
                    const taskDur = Math.max(0, Math.floor((cout.getTime() - cin.getTime()) / 1000));
                    summaryRows.value.push({
                    name: entry.task?.title || entry.task?.name || 'Task',
                    start: entry.clock_in_formatted || entry.clock_in,
                    end: entry.clock_out_formatted || entry.clock_out,
                        durationSeconds: taskDur,
                        breakDurationSeconds: 0,
                    notes: '-',
                    });
            }
        });

        summaryRows.value.sort((a, b) => {
            const da = new Date(a.start ?? '').getTime();
            const db = new Date(b.start ?? '').getTime();
            return (isNaN(db) ? 0 : db) - (isNaN(da) ? 0 : da);
        });

        const workHoursSum = summaryRows.value
            .filter((row) => row.name === 'Work Hours')
            .reduce((sum, row) => sum + (row.durationSeconds || 0), 0);
        const totalBreakSecondsFromRows = summaryRows.value
            .filter((row) => row.name === 'Break')
            .reduce((sum, row) => sum + (row.durationSeconds || 0), 0);

        const eight = 8 * 3600;
        const overtime = Math.max(0, workHoursSum - eight);
        let status = 'No Entry';
        if (firstIn) {
            const firstInDate = firstIn as Date;
            const manilaHours = (firstInDate.getUTCHours() + 8) % 24;
            const manilaMinutes = firstInDate.getUTCMinutes();
            status = manilaHours < 8 || (manilaHours === 8 && manilaMinutes <= 30) ? 'Perfect' : 'Late';
        }

        dailyTotals.value = {
            workSeconds: workHoursSum,
            breakSeconds: totalBreakSecondsFromRows,
            lunchSeconds: 0,
            tasksCount: summaryRows.value.length,
            status,
            overtimeSeconds: overtime,
        };
    } catch (e) {
        console.error('Error loading user daily analytics', e);
        sessions.value = [];
        dailyTotals.value = {
            workSeconds: 0,
            breakSeconds: 0,
            lunchSeconds: 0,
            tasksCount: 0,
            status: 'No Entry',
            overtimeSeconds: 0,
        };
    } finally {
        sessionsLoading.value = false;
    }
};

const loadActivityLogs = async (silent = false) => {
    if (!silent) {
        activityLogsLoading.value = true;
    }
    try {
        const params: Record<string, unknown> = { limit: 100 };
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

const exportCsv = () => {
    const params = new URLSearchParams({
        period: overviewPeriod.value,
        start_date: summaryDate.value,
        end_date: summaryDate.value,
    });
    window.location.href = `/api/admin/analytics/export/csv?${params.toString()}`;
};

const exportPdf = () => {
    const params = new URLSearchParams({
        period: overviewPeriod.value,
        start_date: summaryDate.value,
        end_date: summaryDate.value,
    });
    window.location.href = `/api/admin/analytics/export/pdf?${params.toString()}`;
};

const exportUserSummaryCsv = () => {
    if (!selectedUser.value) {
        alert('Please select a user first');
        return;
    }
    const params = new URLSearchParams({
        user_id: String(selectedUser.value),
        start_date: summaryDate.value,
        end_date: summaryDate.value,
    });
    window.location.href = `/api/admin/analytics/user-summary/export/csv?${params.toString()}`;
};

const exportUserSummaryPdf = () => {
    if (!selectedUser.value) {
        alert('Please select a user first');
        return;
    }
    const params = new URLSearchParams({
        user_id: String(selectedUser.value),
        start_date: summaryDate.value,
        end_date: summaryDate.value,
    });
    window.location.href = `/api/admin/analytics/user-summary/export/pdf?${params.toString()}`;
};

onMounted(() => {
    const today = new Date();
    summaryDate.value = today.toISOString().split('T')[0];
    loadOverviewData();
    loadUsers();
    loadActivityLogs();
    fetchSummary();
});

watch(activeTab, (newTab) => {
    if (newTab === 'overview') {
        fetchSummary();
        loadOverviewData();
    } else if (newTab === 'summary') {
        if (!selectedUser.value && allUsers.value.length > 0) {
            selectedUser.value = allUsers.value[0].id;
        }
        if (selectedUser.value) {
            loadUserDaily();
        }
    } else if (newTab === 'activity') {
        loadActivityLogs(true);
    }
});

watch(selectedUser, (value) => {
    if (activeTab.value === 'summary' && value) {
        loadUserDaily();
    }
});

watch(summaryDate, () => {
    if (activeTab.value === 'summary' && selectedUser.value) {
        loadUserDaily();
    }
});

watch(selectedActionFilter, () => {
    if (activeTab.value === 'activity') {
        loadActivityLogs(true);
    }
});
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
                            @click="activeTab = 'activity'"
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

                <div v-if="activeTab === 'overview'" class="space-y-8">
                    <section class="space-y-6">
                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <div>
                                <h3 class="text-lg font-semibold text-gray-900">Company Health Overview</h3>
                                <p v-if="summary" class="text-sm text-gray-500">
                                    As of {{ formatTimestamp(summary.generated_at) }}
                                </p>
                            </div>
                            <div class="flex items-center gap-2">
                                <button
                                    type="button"
                                    @click="refresh"
                                    :disabled="refreshing || loading"
                                    class="inline-flex items-center gap-2 rounded-md border border-indigo-200 bg-white px-4 py-2 text-sm font-medium text-indigo-600 shadow-sm transition hover:bg-indigo-50 disabled:cursor-not-allowed disabled:border-gray-200 disabled:text-gray-400"
                                >
                                    <span
                                        v-if="refreshing"
                                        class="h-3 w-3 animate-spin rounded-full border-2 border-indigo-500 border-t-transparent"
                                    ></span>
                                    <span>{{ refreshing ? 'Refreshing…' : 'Refresh' }}</span>
                                </button>
                            </div>
                        </div>

                        <div v-if="loading" class="bg-white shadow rounded-lg p-10 text-center text-sm text-gray-500">
                            <div class="mx-auto mb-3 h-8 w-8 animate-spin rounded-full border-b-2 border-indigo-600"></div>
                            Loading analytics summary…
                        </div>

                        <div
                            v-else-if="error"
                            class="bg-red-50 border border-red-200 text-red-700 rounded-lg p-6 flex flex-col gap-4"
                        >
                            <div>
                                <h4 class="font-semibold text-red-800">We couldn’t load the analytics overview.</h4>
                                <p class="text-sm">{{ error }}</p>
                            </div>
                            <div>
                                <button
                                    type="button"
                                    @click="refresh"
                                    class="inline-flex items-center rounded-md bg-red-600 px-4 py-2 text-sm font-medium text-white shadow-sm transition hover:bg-red-500"
                                >
                                    Try again
                                </button>
                            </div>
                        </div>

                        <div v-else-if="summary" class="space-y-8">
                            <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                                <div
                                    v-for="card in metricCards"
                                    :key="card.title"
                                    class="rounded-xl border bg-white p-6 shadow-sm transition hover:-translate-y-1 hover:shadow-md"
                                    :class="card.accent"
                                >
                                    <h4 class="text-sm font-semibold uppercase tracking-wide text-gray-500">{{ card.title }}</h4>
                                    <p class="mt-2 text-3xl font-bold">{{ card.value }}</p>
                                    <p class="mt-2 text-sm text-gray-600">{{ card.subtitle }}</p>
                                </div>
                            </div>

                            <div class="grid gap-6 lg:grid-cols-2">
                                <div class="rounded-xl bg-white shadow-sm">
                                    <div class="border-b border-gray-100 px-5 py-4">
                                        <h4 class="text-base font-semibold text-gray-800">Who’s still on the clock</h4>
                                        <p class="text-sm text-gray-500">
                                            {{ hasOpenEntries ? 'Top 10 current sessions' : 'No one is clocked in right now.' }}
                                        </p>
                                    </div>

                                    <div v-if="hasOpenEntries" class="divide-y divide-gray-100">
                                        <div
                                            v-for="entry in summary.open_entries"
                                            :key="entry.entry_id"
                                            class="flex items-center justify-between gap-3 px-5 py-4"
                                        >
                                            <div>
                                                <p class="font-medium text-gray-900">{{ entry.user.name || 'Unknown User' }}</p>
                                                <p class="text-sm text-gray-500">
                                                    {{ entry.user.email || 'No email listed' }}
                                                    <span v-if="entry.user.team" class="ml-1 text-gray-400">· {{ entry.user.team }}</span>
                                                </p>
                                            </div>
                                            <div class="text-right">
                                                <p class="text-sm font-semibold text-indigo-600">
                                                    {{ formatMinutes(entry.minutes_running) }}
                                                </p>
                                                <p class="text-xs text-gray-500">since {{ formatClockIn(entry.clock_in) }}</p>
                                            </div>
                                        </div>
                                    </div>

                                    <div v-else class="px-5 py-6 text-sm text-gray-500">
                                        All clocks are cleared for today. Great job!
                                    </div>
                                </div>

                                <div class="rounded-xl bg-white shadow-sm">
                                    <div class="border-b border-gray-100 px-5 py-4">
                                        <h4 class="text-base font-semibold text-gray-800">At a glance</h4>
                                        <p class="text-sm text-gray-500">Quick context for today and the week.</p>
                                    </div>
                                    <dl class="space-y-4 px-5 py-6">
                                        <div
                                            v-for="detail in detailList"
                                            :key="detail.label"
                                            class="flex items-center justify-between gap-4"
                                        >
                                            <dt class="text-sm font-medium text-gray-600">{{ detail.label }}</dt>
                                            <dd class="text-sm font-semibold text-gray-900 text-right">{{ detail.value }}</dd>
                                        </div>
                                    </dl>
                                </div>
                            </div>
                        </div>
                    </section>

                    <section class="space-y-6">
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
                                                <dd class="text-2xl font-semibold text-gray-900">
                                                    {{ overviewData.statistics.total_hms || formatHoursToHMS(overviewData.statistics.total_hours) }}
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
                            <p class="mt-2 text-sm text-gray-500">Loading…</p>
                        </div>
                    </section>
                </div>

                <div v-else-if="activeTab === 'summary'" class="space-y-6">
                    <div class="bg-white shadow rounded-lg p-4">
                        <div class="grid gap-4 sm:grid-cols-3">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">User</label>
                                <select v-model="selectedUser" class="w-full rounded-md border-gray-300 shadow-sm">
                                    <option :value="null">Select User</option>
                                    <option v-for="user in allUsers" :key="user.id" :value="user.id">{{ user.name }}</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Date</label>
                                <input v-model="summaryDate" type="date" class="w-full rounded-md border-gray-300 shadow-sm" />
                            </div>
                            <div class="flex items-end gap-2">
                                <button
                                    @click="exportUserSummaryCsv"
                                    :disabled="!selectedUser"
                                    class="flex-1 rounded-md bg-green-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-green-500 disabled:bg-gray-400 disabled:cursor-not-allowed"
                                >
                                    Export CSV
                                </button>
                                <button
                                    @click="exportUserSummaryPdf"
                                    :disabled="!selectedUser"
                                    class="flex-1 rounded-md bg-red-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-red-500 disabled:bg-gray-400 disabled:cursor-not-allowed"
                                >
                                    Export PDF
                                </button>
                            </div>
                        </div>
                    </div>

                    <div class="bg-white shadow rounded-lg p-5">
                        <h3 class="text-lg font-medium leading-6 text-gray-900 mb-4">Daily Summary</h3>
                        <div class="grid gap-4 sm:grid-cols-3 lg:grid-cols-5 text-sm">
                            <div>
                                <div class="text-gray-500">🕒 Time In Hours</div>
                                <div class="font-semibold">{{ formatSecondsToHHMMSS(dailyTotals.workSeconds) }}</div>
                            </div>
                            <div>
                                <div class="text-gray-500">☕ Total Breaks</div>
                                <div class="font-semibold">{{ formatSecondsToHHMMSS(dailyTotals.breakSeconds) }}</div>
                            </div>
                            <div>
                                <div class="text-gray-500">📋 Entries</div>
                                <div class="font-semibold">{{ dailyTotals.tasksCount }}</div>
                            </div>
                            <div>
                                <div class="text-gray-500">⏰ Status</div>
                                <div
                                    class="font-semibold"
                                    :class="{
                                        'text-green-700': dailyTotals.status === 'Perfect',
                                        'text-yellow-700': dailyTotals.status === 'Late',
                                        'text-gray-700': dailyTotals.status === 'No Entry'
                                    }"
                                >
                                    {{ dailyTotals.status }}
                                </div>
                            </div>
                            <div>
                                <div class="text-gray-500">⚡ Overtime</div>
                                <div class="font-semibold">{{ formatSecondsToHHMMSS(dailyTotals.overtimeSeconds) }}</div>
                            </div>
                        </div>
                    </div>

                    <div class="bg-white shadow rounded-lg">
                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Task</th>
                                        <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Start Time</th>
                                        <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">End Time</th>
                                        <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Duration</th>
                                        <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Break Duration</th>
                                        <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Notes</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-200">
                                    <tr v-for="(row, idx) in summaryRows" :key="idx">
                                        <td class="px-4 py-4 text-sm text-gray-900">{{ row.name }}</td>
                                        <td class="px-4 py-4 text-sm text-gray-500">{{ formatTime(row.start) }}</td>
                                        <td class="px-4 py-4 text-sm text-gray-500">{{ formatTime(row.end) }}</td>
                                        <td class="px-4 py-4 text-sm text-gray-900 font-semibold">{{ formatSecondsToHHMMSS(row.durationSeconds) }}</td>
                                        <td class="px-4 py-4 text-sm text-gray-500">{{ formatSecondsToHHMMSS(row.breakDurationSeconds || 0) }}</td>
                                        <td class="px-4 py-4 text-sm text-gray-500">{{ row.notes || '-' }}</td>
                                    </tr>
                                    <tr v-if="summaryRows.length === 0 && !sessionsLoading">
                                        <td colspan="6" class="px-4 py-4 text-center text-sm text-gray-500">No entries for selected day</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <div v-if="sessionsLoading" class="text-center py-8">
                            <div class="inline-block animate-spin rounded-full h-8 w-8 border-b-2 border-indigo-600"></div>
                            <p class="mt-2 text-sm text-gray-500">Loading...</p>
                        </div>
                    </div>
                </div>

                <div v-else class="space-y-6">
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
                                <select v-model="selectedActionFilter" class="w-full rounded-md border-gray-300 shadow-sm">
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
                                            <span
                                                class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full"
                                                :class="{
                                                    'bg-green-100 text-green-800': log.action === 'clock_in',
                                                    'bg-red-100 text-red-800': log.action === 'clock_out',
                                                    'bg-yellow-100 text-yellow-800': log.action.includes('break'),
                                                    'bg-blue-100 text-blue-800': log.action.includes('lunch')
                                                }"
                                            >
                                                {{ log.action.replace('_', ' ').replace(/\b\w/g, (l) => l.toUpperCase()) }}
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
