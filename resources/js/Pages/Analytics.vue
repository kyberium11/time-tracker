<script setup lang="ts">
import { ref, onMounted, computed } from 'vue';
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

onMounted(fetchSummary);

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
</script>

<template>
    <Head title="Analytics" />

    <AuthenticatedLayout>
        <template #header>
            <div>
                <h2 class="text-xl font-semibold leading-tight text-gray-800">Company Health Overview</h2>
                <p v-if="summary" class="text-sm text-gray-500">As of {{ formatTimestamp(summary.generated_at) }}</p>
            </div>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-7xl sm:px-6 lg:px-8">
                <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h3 class="text-lg font-semibold text-gray-900">Time Tracking Summary</h3>
                        <p v-if="summary" class="text-sm text-gray-500">
                            Today: {{ formatDate(summary.dates.today) }}
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
                                        <p class="font-medium text-gray-900">
                                            {{ entry.user.name || 'Unknown User' }}
                                        </p>
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
            </div>
        </div>
    </AuthenticatedLayout>
</template>
