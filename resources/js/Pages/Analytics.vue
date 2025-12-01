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

const activeTab = ref<'overview' | 'summary' | 'activity' | 'user-hours'>('overview');
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

// ------------------------------------------------------------
// User Hours Matrix (per user per day)
// ------------------------------------------------------------

interface UserHoursCell {
    taskHours: number;
    workHours: number;
    timeIn?: string | null;
    timeOut?: string | null;
}

interface UserHoursRow {
    date: string;
    cells: Record<string, UserHoursCell>;
}

interface SimpleTeam {
    id: number;
    name: string;
}

const userHoursStartDate = ref<string>('');
const userHoursEndDate = ref<string>('');
const userHoursUsers = ref<string[]>([]);
const userHoursRows = ref<UserHoursRow[]>([]);
const userHoursUserTotals = ref<Record<string, UserHoursCell>>({});
const userHoursTeams = ref<SimpleTeam[]>([]);
const userHoursSelectedTeamId = ref<number | null>(null);
const userHoursLoading = ref(false);
const userHoursError = ref<string | null>(null);

const initUserHoursDates = () => {
    const end = new Date();
    const start = new Date();
    start.setDate(end.getDate() - 13); // default last 14 days
    userHoursStartDate.value = start.toISOString().split('T')[0];
    userHoursEndDate.value = end.toISOString().split('T')[0];
};

const loadUserHoursTeams = async () => {
    try {
        const { data } = await api.get<SimpleTeam[]>('/admin/teams', { params: { per_page: 1000 } });
        if (Array.isArray(data)) {
            userHoursTeams.value = data.map((t: any) => ({
                id: t.id,
                name: t.name,
            }));
        } else {
            userHoursTeams.value = [];
        }
    } catch (e) {
        // This endpoint is admin-only; managers may get 403 which we can safely ignore.
        console.error('Error loading teams for user hours matrix', e);
        userHoursTeams.value = [];
    }
};

const formatUserHoursDate = (value: string) => {
    if (!value) return '';
    // API returns full ISO string (e.g. 2025-11-17T00:00:00.000000Z); we just want the calendar date.
    return value.split('T')[0] || value;
};

const buildUserHoursTooltip = (row: UserHoursRow, user: string): string => {
    const cell = row.cells[user];
    if (!cell) return '';

    const parts: string[] = [];
    if (cell.timeIn) {
        parts.push(`Time in: ${formatTime(cell.timeIn)}`);
    }
    if (cell.timeOut) {
        parts.push(`Time out: ${formatTime(cell.timeOut)}`);
    }

    return parts.join('\n');
};

const loadUserHours = async () => {
    userHoursLoading.value = true;
    userHoursError.value = null;
    try {
        const params: Record<string, string | number> = {};
        if (userHoursStartDate.value) params.start_date = userHoursStartDate.value;
        if (userHoursEndDate.value) params.end_date = userHoursEndDate.value;
        if (userHoursSelectedTeamId.value) params.team_id = userHoursSelectedTeamId.value;

        const { data } = await api.get<{
            data: Array<{
                user: string;
                date: string;
                worked_hours: number;
                task_hours: number;
                first_clock_in?: string | null;
                last_clock_out?: string | null;
            }>;
        }>(
            '/admin/analytics/utilization/gaps',
            { params }
        );

        const rows = data?.data || [];
        if (!rows.length) {
            userHoursUsers.value = [];
            userHoursRows.value = [];
            userHoursUserTotals.value = {};
            return;
        }

        // Collect unique users and dates
        const userSet = new Set<string>();
        const dateSet = new Set<string>();
        rows.forEach((r) => {
            if (r.user) userSet.add(r.user);
            if (r.date) dateSet.add(r.date);
        });

        const users = Array.from(userSet).sort((a, b) => a.localeCompare(b));
        const dates = Array.from(dateSet).sort((a, b) => a.localeCompare(b));

        const rowMap: Record<string, UserHoursRow> = {};
        const totalsMap: Record<string, UserHoursCell> = {};

        dates.forEach((d) => {
            rowMap[d] = {
                date: d,
                cells: {},
            };
        });

        rows.forEach((r) => {
            const row = rowMap[r.date];
            if (!row) return;
            const userName = r.user || 'Unknown';
            if (!row.cells[userName]) {
                row.cells[userName] = {
                    taskHours: 0,
                    workHours: 0,
                    timeIn: null,
                    timeOut: null,
                };
            }
            const cell = row.cells[userName];
            const worked = Number.isFinite(r.worked_hours) ? r.worked_hours : 0;
            const task = Number.isFinite(r.task_hours) ? r.task_hours : 0;

            cell.workHours += worked;
            cell.taskHours += task;

            if (r.first_clock_in) {
                if (!cell.timeIn || new Date(r.first_clock_in) < new Date(cell.timeIn)) {
                    cell.timeIn = r.first_clock_in;
                }
            }
            if (r.last_clock_out) {
                if (!cell.timeOut || new Date(r.last_clock_out) > new Date(cell.timeOut)) {
                    cell.timeOut = r.last_clock_out;
                }
            }

            if (!totalsMap[userName]) {
                totalsMap[userName] = {
                    taskHours: 0,
                    workHours: 0,
                };
            }
            totalsMap[userName].workHours += worked;
            totalsMap[userName].taskHours += task;
        });

        userHoursUsers.value = users;
        userHoursRows.value = dates.map((d) => rowMap[d]);
        userHoursUserTotals.value = totalsMap;
    } catch (e: any) {
        console.error('Error loading user hours matrix', e);
        userHoursError.value = e?.response?.data?.error || 'Unable to load user hours.';
        userHoursUsers.value = [];
        userHoursRows.value = [];
    } finally {
        userHoursLoading.value = false;
    }
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
            
            // Create "Work Hours" entries ONLY for non-task work entries.
            // Task entries will be shown separately below.
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
            
            // If entry has a task, also create a separate task entry row
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
            } else if (hasTask && cin && !cout) {
                // Active task entry
                const now = new Date();
                const runningSeconds = Math.max(0, Math.floor((now.getTime() - cin.getTime()) / 1000));
                summaryRows.value.push({
                    name: entry.task?.title || entry.task?.name || 'Task',
                    start: entry.clock_in_formatted || entry.clock_in,
                    end: null,
                    durationSeconds: runningSeconds,
                    breakDurationSeconds: 0,
                    notes: 'In progress',
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
    loadUsers();
    loadActivityLogs();
    fetchSummary();
    initUserHoursDates();
    loadUserHoursTeams();
});

watch(activeTab, (newTab) => {
    if (newTab === 'overview') {
        fetchSummary();
    } else if (newTab === 'summary') {
        if (!selectedUser.value && allUsers.value.length > 0) {
            selectedUser.value = allUsers.value[0].id;
        }
        if (selectedUser.value) {
            loadUserDaily();
        }
    } else if (newTab === 'activity') {
        loadActivityLogs(true);
    } else if (newTab === 'user-hours') {
        if (!userHoursStartDate.value || !userHoursEndDate.value) {
            initUserHoursDates();
        }
        loadUserHours();
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
                            @click="activeTab = 'user-hours'"
                            :class="[
                                'whitespace-nowrap border-b-2 py-4 px-1 text-sm font-medium',
                                activeTab === 'user-hours'
                                    ? 'border-indigo-500 text-indigo-600'
                                    : 'border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700'
                            ]"
                        >
                            User Hours
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

                <div v-else-if="activeTab === 'activity'" class="space-y-6">
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

                <!-- User Hours Tab -->
                <div v-else-if="activeTab === 'user-hours'" class="space-y-6">
                    <div class="bg-white shadow rounded-lg p-4 flex flex-wrap items-end gap-4">
                        <div>
                            <h3 class="text-lg font-semibold text-gray-900">User Hours Matrix</h3>
                            <p class="text-sm text-gray-500">
                                Each cell shows Task Hours / Work Hours for that user and date. Totals per user are shown in the bottom row.
                            </p>
                        </div>
                        <div class="ml-auto flex flex-wrap items-end gap-3">
                            <div v-if="userHoursTeams.length">
                                <label class="block text-sm font-medium text-gray-700 mb-1">Team</label>
                                <select
                                    v-model.number="userHoursSelectedTeamId"
                                    class="rounded-md border-gray-300 shadow-sm text-sm min-w-[10rem]"
                                >
                                    <option :value="null">All Teams</option>
                                    <option v-for="team in userHoursTeams" :key="team.id" :value="team.id">
                                        {{ team.name }}
                                    </option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Start Date</label>
                                <input
                                    type="date"
                                    v-model="userHoursStartDate"
                                    class="rounded-md border-gray-300 shadow-sm text-sm"
                                />
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">End Date</label>
                                <input
                                    type="date"
                                    v-model="userHoursEndDate"
                                    class="rounded-md border-gray-300 shadow-sm text-sm"
                                />
                            </div>
                            <button
                                type="button"
                                @click="loadUserHours"
                                :disabled="userHoursLoading"
                                class="inline-flex items-center gap-2 rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-indigo-500 disabled:opacity-60 disabled:cursor-not-allowed"
                            >
                                <span
                                    v-if="userHoursLoading"
                                    class="h-3 w-3 animate-spin rounded-full border-2 border-white border-t-transparent"
                                ></span>
                                <span>{{ userHoursLoading ? 'Loading…' : 'Apply' }}</span>
                            </button>
                        </div>
                    </div>

                    <div v-if="userHoursError" class="bg-red-50 border border-red-200 text-red-700 rounded-lg p-4">
                        <p class="text-sm">{{ userHoursError }}</p>
                    </div>

                    <div v-else class="bg-white shadow rounded-lg">
                        <div v-if="userHoursLoading" class="p-8 text-center text-sm text-gray-500">
                            <div class="mx-auto mb-3 h-8 w-8 animate-spin rounded-full border-b-2 border-indigo-600"></div>
                            Loading user hours…
                        </div>
                        <div v-else-if="!userHoursRows.length" class="p-8 text-center text-sm text-gray-500">
                            No data for selected range.
                        </div>
                        <div v-else class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">
                                            Date
                                        </th>
                                        <th
                                            v-for="user in userHoursUsers"
                                            :key="user"
                                            class="px-4 py-3 text-center text-xs font-medium uppercase tracking-wider text-gray-500"
                                        >
                                            {{ user }}
                                        </th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-200 bg-white">
                                    <tr v-for="row in userHoursRows" :key="row.date">
                                        <td class="px-4 py-3 text-sm text-gray-900 whitespace-nowrap">
                                            {{ formatUserHoursDate(row.date) }}
                                        </td>
                                        <td
                                            v-for="user in userHoursUsers"
                                            :key="user + row.date"
                                            class="px-4 py-3 text-center align-top"
                                            :title="buildUserHoursTooltip(row, user)"
                                        >
                                            <div v-if="row.cells[user]" class="space-y-0.5">
                                                <div class="text-sm font-semibold text-gray-900">
                                                    Work: {{ row.cells[user].workHours.toFixed(2) }} h
                                                </div>
                                                <div class="text-xs text-gray-500">
                                                    Task: {{ row.cells[user].taskHours.toFixed(2) }} h
                                                </div>
                                            </div>
                                            <div v-else class="text-xs text-gray-300">—</div>
                                        </td>
                                    </tr>
                                </tbody>
                                <tfoot class="bg-gray-50 border-t border-gray-200">
                                    <tr>
                                        <th class="px-4 py-3 text-sm font-semibold text-gray-900 text-right">
                                            Totals
                                        </th>
                                        <td
                                            v-for="user in userHoursUsers"
                                            :key="user + '-total'"
                                            class="px-4 py-3 text-xs text-gray-900 text-center"
                                        >
                                            <div class="font-semibold text-indigo-700">
                                                Task:
                                                {{
                                                    (userHoursUserTotals[user]?.taskHours ?? 0).toFixed(2)
                                                }}
                                                h
                                            </div>
                                            <div class="text-gray-700">
                                                Work:
                                                {{
                                                    (userHoursUserTotals[user]?.workHours ?? 0).toFixed(2)
                                                }}
                                                h
                                            </div>
                                        </td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
