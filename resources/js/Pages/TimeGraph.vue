<script setup lang="ts">
import { ref, onMounted, computed } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head } from '@inertiajs/vue3';
import api from '@/api';

interface TimeGraphTask {
    title: string;
    start_hour: number;
    end_hour: number;
}

interface TimeGraphDay {
    date: string;
    label: string;
    shift: { start_hour: number; end_hour: number } | null;
    tasks: TimeGraphTask[];
}

interface TimeGraphResponse {
    timezone: string;
    start_date: string;
    end_date: string;
    days: TimeGraphDay[];
}

const loading = ref(true);
const error = ref<string | null>(null);
const days = ref<TimeGraphDay[]>([]);
const timezone = ref<string>('Asia/Manila');
const rangeSummary = ref<{ start: string; end: string }>({ start: '', end: '' });

const filters = ref({
    startDate: '',
    endDate: '',
});

const formatInputDate = (date: Date) => date.toISOString().slice(0, 10);

const initializeFilters = () => {
    const end = new Date();
    const start = new Date();
    start.setDate(start.getDate() - 4);
    filters.value.startDate = formatInputDate(start);
    filters.value.endDate = formatInputDate(end);
};

const fetchData = async () => {
    loading.value = true;
    error.value = null;
    try {
        const params: Record<string, string> = {};
        if (filters.value.startDate) params.start_date = filters.value.startDate;
        if (filters.value.endDate) params.end_date = filters.value.endDate;

        const { data } = await api.get<TimeGraphResponse>('/my/time-graph', {
            params,
        });
        days.value = data.days || [];
        timezone.value = data.timezone || 'Asia/Manila';
        rangeSummary.value = {
            start: data.start_date,
            end: data.end_date,
        };
    } catch (e: any) {
        console.error(e);
        error.value =
            e?.response?.data?.error ||
            'Unable to load time graph data. Please try again.';
    } finally {
        loading.value = false;
    }
};

const applyFilters = () => {
    if (filters.value.startDate && filters.value.endDate) {
        const start = new Date(filters.value.startDate);
        const end = new Date(filters.value.endDate);
        if (end < start) {
            error.value = 'End date must be on or after the start date.';
            return;
        }
        if ((end.getTime() - start.getTime()) / (1000 * 60 * 60 * 24) > 30) {
            error.value = 'Please limit the range to 31 days or less.';
            return;
        }
    }
    fetchData();
};

const resetFilters = () => {
    initializeFilters();
    fetchData();
};

onMounted(() => {
    initializeFilters();
    fetchData();
});

const wrapHour = (hour: number) => {
    let value = hour;
    while (value < 0) value += 24;
    while (value >= 24) value -= 24;
    return value;
};

const splitRange = (start: number, end: number) => {
    const totalSpan = end - start;
    if (totalSpan >= 24) {
        return [{ start: 0, end: 24 }];
    }

    const normalizedStart = wrapHour(start);
    let normalizedEnd = wrapHour(end);

    if (end - start <= 0) {
        return [
            { start: normalizedStart, end: 24 },
            { start: 0, end: normalizedEnd },
        ];
    }

    if (normalizedEnd <= normalizedStart && end > start) {
        normalizedEnd += 24;
    }

    if (normalizedEnd > 24) {
        return [
            { start: normalizedStart, end: 24 },
            { start: 0, end: normalizedEnd - 24 },
        ];
    }

    return [{ start: normalizedStart, end: normalizedEnd }];
};

const HOURS_IN_DAY = 24;
const zoomLevels = [4, 2, 1, 0.5]; // hours per grid interval
const BASE_STEP = 2; // corresponds to default zoom index 1
const zoomIndex = ref(1); // default 2-hour ticks

const canZoomOut = computed(() => zoomIndex.value > 0);
const canZoomIn = computed(() => zoomIndex.value < zoomLevels.length - 1);

const zoomOut = () => {
    if (canZoomOut.value) {
        zoomIndex.value -= 1;
    }
};

const zoomIn = () => {
    if (canZoomIn.value) {
        zoomIndex.value += 1;
    }
};

const currentStep = computed(() => zoomLevels[zoomIndex.value]);
const zoomLabel = computed(() => {
    const step = currentStep.value;
    if (step >= 1) {
        return `${step} hr`;
    }
    return `${Math.round(step * 60)} min`;
});

const zoomFactor = computed(() => BASE_STEP / currentStep.value);
const timelineWidth = computed(() => `${Math.max(zoomFactor.value, 1) * 100}%`);

const hourToLabel = (value: number) => {
    const wrapped = ((value % 24) + 24) % 24;
    const hours = Math.floor(wrapped);
    const minutes = Math.round((wrapped % 1) * 60);
    const suffix = hours >= 12 ? 'PM' : 'AM';
    const displayHour = ((hours + 11) % 12) + 1;
    const minuteStr = minutes === 0 ? '00' : minutes.toString().padStart(2, '0');
    return `${displayHour}:${minuteStr} ${suffix}`;
};

const hourTicks = computed(() => {
    const ticks: Array<{ label: string; value: number }> = [];
    const step = currentStep.value;
    for (let i = 0; i <= HOURS_IN_DAY; i += step) {
        ticks.push({ label: hourToLabel(i), value: i });
    }
    return ticks;
});

const formatDurationFromHours = (hours: number) => {
    const totalSeconds = Math.round(hours * 3600);
    const h = Math.floor(totalSeconds / 3600);
    const m = Math.floor((totalSeconds % 3600) / 60);
    const s = totalSeconds % 60;
    const pad = (n: number) => (n < 10 ? `0${n}` : `${n}`);
    return `${pad(h)}:${pad(m)}:${pad(s)}`;
};

const displayDays = computed(() =>
    days.value.map((day) => {
        const shiftSegments = day.shift
            ? splitRange(day.shift.start_hour, day.shift.end_hour)
            : [];

        const taskSegments = day.tasks.flatMap((task) => {
            const segments = splitRange(task.start_hour, task.end_hour);
            return segments.map((segment) => {
                const duration = segment.end - segment.start;
                const startLabel = hourToLabel(segment.start);
                const endLabel = hourToLabel(segment.end);
                return {
                    ...segment,
                    title: task.title,
                    tooltip: `${startLabel} - ${endLabel} (Total Time: ${formatDurationFromHours(
                        duration
                    )})`,
                };
            });
        });

        return {
            ...day,
            shiftSegments,
            taskSegments,
        };
    })
);

const segmentStyle = (segment: { start: number; end: number }) => {
    const width = ((segment.end - segment.start) / HOURS_IN_DAY) * 100;
    return {
        left: `${(segment.start / HOURS_IN_DAY) * 100}%`,
        width: `${Math.max(width, 0)}%`,
    };
};

const formattedRange = computed(() => {
    if (!rangeSummary.value.start || !rangeSummary.value.end) return '';
    const start = new Date(rangeSummary.value.start);
    const end = new Date(rangeSummary.value.end);
    const options: Intl.DateTimeFormatOptions = {
        month: 'long',
        day: 'numeric',
        year: 'numeric',
    };
    return `${start.toLocaleDateString(undefined, options)} → ${end.toLocaleDateString(
        undefined,
        options
    )}`;
});

const hasData = computed(() =>
    displayDays.value.some(
        (day) => day.shiftSegments.length > 0 || day.taskSegments.length > 0
    )
);

const gridLines = computed(() =>
    hourTicks.value.map((tick) => ({
        ...tick,
        style: {
            left: `${(tick.value / HOURS_IN_DAY) * 100}%`,
        },
    }))
);

</script>

<template>
    <AuthenticatedLayout>
        <Head title="Time Graph" />

        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">
                Time Graph
            </h2>
        </template>

        <div class="py-6">
            <div class="mx-auto max-w-7xl sm:px-6 lg:px-8">
                <div class="overflow-hidden bg-white shadow-sm sm:rounded-lg">
                    <div class="border-b border-gray-200 px-6 py-4">
                        <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                            <div>
                                <h3 class="text-lg font-medium text-gray-900">
                                    Shift and Task Schedule
                                </h3>
                                <p class="mt-1 text-sm text-gray-500">
                                    <span v-if="formattedRange">{{ formattedRange }}</span>
                                    <span v-else>Last 5 days</span>
                                    · Timezone:
                                    <span class="font-mono">{{ timezone }}</span>
                                </p>
                            </div>
                            <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:gap-4">
                                <div class="flex flex-wrap items-end gap-4">
                                    <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">
                                        Start Date
                                    </label>
                                    <input
                                        type="date"
                                        v-model="filters.startDate"
                                        class="rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                    />
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">
                                        End Date
                                    </label>
                                    <input
                                        type="date"
                                        v-model="filters.endDate"
                                        class="rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                    />
                                </div>
                                <div class="flex gap-2">
                                    <button
                                        type="button"
                                        class="inline-flex items-center rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 disabled:opacity-50"
                                        @click="applyFilters"
                                        :disabled="loading"
                                    >
                                        Apply
                                    </button>
                                    <button
                                        type="button"
                                        class="inline-flex items-center rounded-md border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 shadow-sm hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2"
                                        @click="resetFilters"
                                        :disabled="loading"
                                    >
                                        Reset
                                    </button>
                                </div>
                                </div>
                                <div class="flex items-center gap-2">
                                    <span class="text-xs font-medium text-gray-500">Time Unit</span>
                                    <button
                                        type="button"
                                        class="inline-flex h-8 w-8 items-center justify-center rounded-md border border-gray-300 bg-white text-gray-600 hover:bg-gray-50 disabled:opacity-40"
                                        @click="zoomOut"
                                        :disabled="!canZoomOut"
                                        aria-label="Zoom out"
                                    >
                                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="h-4 w-4">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M5 12h14" />
                                        </svg>
                                    </button>
                                    <span class="px-2 text-xs font-semibold text-gray-700 border border-gray-200 rounded-md bg-white">
                                        {{ zoomLabel }}
                                    </span>
                                    <button
                                        type="button"
                                        class="inline-flex h-8 w-8 items-center justify-center rounded-md border border-gray-300 bg-white text-gray-600 hover:bg-gray-50 disabled:opacity-40"
                                        @click="zoomIn"
                                        :disabled="!canZoomIn"
                                        aria-label="Zoom in"
                                    >
                                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="h-4 w-4">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 5v14m-7-7h14" />
                                        </svg>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="px-6 py-4">
                        <div v-if="error" class="mb-4 rounded-md bg-red-50 p-4 text-sm text-red-700">
                            {{ error }}
                        </div>

                        <div v-if="loading" class="flex items-center justify-center py-16">
                            <div class="inline-block h-10 w-10 animate-spin rounded-full border-b-2 border-indigo-600"></div>
                        </div>

                        <div v-else class="space-y-6">
                            <div v-if="!hasData" class="py-10 text-center text-sm text-gray-500">
                                No shift or task data for this period.
                            </div>

                            <div v-else>
                                <div class="mb-3 flex items-center text-xs text-gray-500">
                                    <div class="w-36"></div>
                                    <div class="flex-1 overflow-x-auto">
                                        <div
                                            class="flex justify-between text-[11px] font-medium min-w-full"
                                            :style="{ width: timelineWidth }"
                                        >
                                            <span v-for="tick in hourTicks" :key="tick.value">
                                                {{ tick.label }}
                                            </span>
                                        </div>
                                    </div>
                                </div>

                                <div class="space-y-4">
                                    <div
                                        v-for="day in displayDays"
                                        :key="day.date"
                                        class="flex items-center gap-4"
                                    >
                                        <div class="w-36 text-sm font-medium text-gray-700">
                                            {{ day.label }}
                                        </div>
                                        <div class="flex-1 overflow-x-auto">
                                            <div
                                                class="relative h-12 rounded-md border border-gray-200 bg-gray-50 min-w-full"
                                                :style="{ width: timelineWidth }"
                                            >
                                                <div
                                                    v-for="tick in gridLines"
                                                    :key="tick.value"
                                                    class="absolute inset-y-0 border-l border-gray-200 last:border-r-0"
                                                    :style="tick.style"
                                                ></div>
                                                <div
                                                    v-for="(segment, idx) in day.shiftSegments"
                                                    :key="`shift-${day.date}-${idx}`"
                                                    class="absolute inset-y-3 rounded-md bg-sky-200/70 border border-sky-400"
                                                    :style="segmentStyle(segment)"
                                                ></div>
                                                <div
                                                    v-for="(segment, idx) in day.taskSegments"
                                                    :key="`task-${day.date}-${idx}`"
                                                    class="absolute inset-1 rounded-md bg-emerald-400 border border-emerald-600 text-[11px] font-medium text-emerald-900 flex items-center justify-center px-2 shadow"
                                                    :style="segmentStyle(segment)"
                                                    :title="segment.tooltip"
                                                >
                                                    <span class="truncate">{{ segment.title }}</span>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="flex items-center gap-4 text-sm text-gray-600">
                                <div class="flex items-center gap-2">
                                    <span class="inline-block h-3 w-6 rounded-sm bg-sky-400 border border-sky-600" />
                                    <span>Shift</span>
                                </div>
                                <div class="flex items-center gap-2">
                                    <span class="inline-block h-3 w-6 rounded-sm bg-emerald-500 border border-emerald-700" />
                                    <span>Task</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>


