<script setup lang="ts">
import { ref, onMounted, computed } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head } from '@inertiajs/vue3';
import api from '@/api';
import { Bar } from 'vue-chartjs';
import {
    Chart as ChartJS,
    CategoryScale,
    LinearScale,
    BarElement,
    Tooltip,
    Legend,
} from 'chart.js';
import type { ChartDataset, ChartData } from 'chart.js';

ChartJS.register(CategoryScale, LinearScale, BarElement, Tooltip, Legend);

type RangeDataPoint = {
    x: [number, number];
    y: string;
    title?: string;
};

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

const fetchData = async () => {
    loading.value = true;
    error.value = null;
    try {
        const { data } = await api.get<TimeGraphResponse>('/my/time-graph', {
            params: { days: 5 },
        });
        days.value = data.days || [];
        timezone.value = data.timezone || 'Asia/Manila';
    } catch (e: any) {
        console.error(e);
        error.value =
            e?.response?.data?.error ||
            'Unable to load time graph data. Please try again.';
    } finally {
        loading.value = false;
    }
};

onMounted(fetchData);

const labels = computed(() => days.value.map((d) => d.label));

const shiftDataset = computed<ChartDataset<'bar', RangeDataPoint[]>>(() => {
    const data: RangeDataPoint[] = days.value.map((d) => {
        if (!d.shift) {
            return {
                x: [0, 0],
                y: d.label,
            };
        }

        return {
            x: [d.shift.start_hour, d.shift.end_hour],
            y: d.label,
        };
    });

    return {
        label: 'Shift',
        data,
        backgroundColor: 'rgba(59, 130, 246, 0.4)', // blue-500 with opacity
        borderColor: 'rgba(59, 130, 246, 1)',
        borderWidth: 1,
        borderSkipped: false,
        parsing: false,
    } as unknown as ChartDataset<'bar', RangeDataPoint[]>;
});

const taskDataset = computed<ChartDataset<'bar', RangeDataPoint[]>>(() => {
    const points: RangeDataPoint[] = [];
    days.value.forEach((day) => {
        day.tasks.forEach((t) => {
            points.push({
                x: [t.start_hour, t.end_hour],
                y: day.label,
                title: t.title,
            });
        });
    });

    return {
        label: 'Tasks',
        data: points,
        backgroundColor: 'rgba(16, 185, 129, 0.7)', // emerald-500
        borderColor: 'rgba(5, 150, 105, 1)',
        borderWidth: 1,
        borderSkipped: false,
        parsing: false,
    } as unknown as ChartDataset<'bar', RangeDataPoint[]>;
});

const chartData = computed(() => ({
    labels: labels.value,
    datasets: [shiftDataset.value, taskDataset.value],
})) as unknown as ChartData<'bar'>;

const hourToLabel = (value: number) => {
    const wrapped = ((value % 24) + 24) % 24;
    const hours = Math.floor(wrapped);
    const suffix = hours >= 12 ? 'pm' : 'am';
    const displayHour = ((hours + 11) % 12) + 1;
    return `${displayHour}:00 ${suffix}`;
};

const chartOptions = computed(() => ({
    indexAxis: 'y' as const,
    responsive: true,
    maintainAspectRatio: false,
    plugins: {
        legend: {
            position: 'top' as const,
        },
        tooltip: {
            callbacks: {
                label: (ctx: any) => {
                    const raw = ctx.raw as { x: [number, number]; y: string; title?: string };
                    const [start, end] = raw.x;
                    const title = raw.title || ctx.dataset.label || '';
                    return `${title}: ${hourToLabel(start)} – ${hourToLabel(end)}`;
                },
            },
        },
    },
    scales: {
        x: {
            type: 'linear' as const,
            min: 0,
            max: 24,
            title: {
                display: true,
                text: 'Hours',
            },
            ticks: {
                stepSize: 2,
                callback: (val: any) => hourToLabel(Number(val)),
            },
        },
        y: {
            title: {
                display: true,
                text: 'Dates',
            },
        },
    },
}));
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
                    <div class="border-b border-gray-200 px-6 py-4 flex items-center justify-between">
                        <div>
                            <h3 class="text-lg font-medium text-gray-900">
                                Shift and Task Schedule
                            </h3>
                            <p class="mt-1 text-sm text-gray-500">
                                Last 5 days · Timezone:
                                <span class="font-mono">{{ timezone }}</span>
                            </p>
                        </div>
                        <button
                            type="button"
                            class="inline-flex items-center rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2"
                            @click="fetchData"
                            :disabled="loading"
                        >
                            <span v-if="!loading">Refresh</span>
                            <span v-else>Loading…</span>
                        </button>
                    </div>

                    <div class="px-6 py-4">
                        <div v-if="error" class="mb-4 rounded-md bg-red-50 p-4 text-sm text-red-700">
                            {{ error }}
                        </div>

                        <div v-if="loading" class="flex items-center justify-center py-16">
                            <div class="inline-block h-10 w-10 animate-spin rounded-full border-b-2 border-indigo-600"></div>
                        </div>

                        <div v-else class="space-y-4">
                            <div v-if="days.length === 0" class="py-10 text-center text-sm text-gray-500">
                                No data available for the selected period.
                            </div>

                            <div v-else class="h-96 w-full">
                                <Bar :data="chartData" :options="chartOptions" />
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


