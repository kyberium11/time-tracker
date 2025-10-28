<script setup lang="ts">
import { ref, onMounted, computed, onUnmounted } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head } from '@inertiajs/vue3';
import api from '@/api';

interface TimeEntry {
    id?: number;
    clock_in?: string;
    clock_out?: string;
    break_start?: string;
    break_end?: string;
    lunch_start?: string;
    lunch_end?: string;
    total_hours?: number;
}

interface TaskItem {
    id: number;
    title: string;
    status: string | null;
    clickup_task_id: string;
}

interface TimeEvent {
    type: string;
    start: string | null;
    end: string | null;
    duration: string;
    status: string;
}

const currentEntry = ref<TimeEntry | null>(null);
const loading = ref(false);
const status = ref('');
const currentTime = ref(new Date());
const userRole = ref<'admin' | 'manager' | 'employee'>('employee');
const tasks = ref<TaskItem[]>([]);
const selectedTaskId = ref<number | null>(null); // deprecated UI, will be removed
const runningTaskId = ref<number | null>(null);
const showDailyLogs = ref(false);
const todayEntries = ref<any[]>([]);
const taskEntries = ref<any[]>([]);
const taskDetails = ref<any | null>(null);
const showTaskModal = ref(false);

let timeInterval: number | null = null;

const isClockedIn = computed(() => Boolean(currentEntry.value?.clock_in) && !currentEntry.value?.clock_out);
const isOnBreak = computed(() => Boolean(currentEntry.value?.break_start) && !currentEntry.value?.break_end);
const isOnLunch = computed(() => currentEntry.value?.lunch_start && !currentEntry.value?.lunch_end);

// Format time for display
const formatTime = (time: Date | string | null): string => {
    if (!time) return '--';
    const date = typeof time === 'string' ? new Date(time) : time;
    return date.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
};

// Calculate duration between two times
const calculateDuration = (start: string | null, end: string | null): string => {
    if (!start || !end) return '--';
    const startTime = new Date(start);
    const endTime = new Date(end);
    const diff = endTime.getTime() - startTime.getTime();
    const hours = Math.floor(diff / 3600000);
    const minutes = Math.floor((diff % 3600000) / 60000);
    const seconds = Math.floor((diff % 60000) / 1000);
    return `${hours}h ${minutes}m ${seconds}s`;
};

// Generate time events from current entry
const timeEvents = computed<TimeEvent[]>(() => {
    const events: TimeEvent[] = [];
    
    if (!currentEntry.value) return events;
    
    const entry = currentEntry.value;
    
    // Clock In
    events.push({
        type: 'Clock In',
        start: entry.clock_in || null,
        end: null,
        duration: '--',
        status: entry.clock_in ? 'Completed' : 'Not started'
    });
    
    // Break
    if (entry.break_start || entry.break_end) {
        events.push({
            type: 'Break',
            start: entry.break_start || null,
            end: entry.break_end || null,
            duration: entry.break_start && entry.break_end 
                ? calculateDuration(entry.break_start, entry.break_end)
                : entry.break_start ? 'In progress' : '--',
            status: entry.break_start && entry.break_end ? 'Completed' : entry.break_start ? 'Active' : 'Scheduled'
        });
    }
    
    // Lunch
    if (entry.lunch_start || entry.lunch_end) {
        events.push({
            type: 'Lunch',
            start: entry.lunch_start || null,
            end: entry.lunch_end || null,
            duration: entry.lunch_start && entry.lunch_end 
                ? calculateDuration(entry.lunch_start, entry.lunch_end)
                : entry.lunch_start ? 'In progress' : '--',
            status: entry.lunch_start && entry.lunch_end ? 'Completed' : entry.lunch_start ? 'Active' : 'Scheduled'
        });
    }
    
    // Clock Out
    if (entry.clock_out) {
        events.push({
            type: 'Clock Out',
            start: entry.clock_out || null,
            end: null,
            duration: '--',
            status: entry.clock_out ? 'Completed' : 'Pending'
        });
    }
    
    return events;
});

onMounted(() => {
    fetchCurrentEntry();
    fetchUserRole();
    fetchMyTasks();
    fetchTodayTaskEntries();
    // Update clock every second
    timeInterval = window.setInterval(() => {
        currentTime.value = new Date();
    }, 1000);
});

onUnmounted(() => {
    if (timeInterval) {
        window.clearInterval(timeInterval);
    }
});

const fetchCurrentEntry = async () => {
    try {
        const response = await api.get('/time-entries/current');
        currentEntry.value = response.data;
        updateStatus();
    } catch (error) {
        currentEntry.value = null;
        status.value = 'No entry today';
        console.error('Error fetching current entry:', error);
    }
};

const fetchUserRole = async () => {
    try {
        const me = await api.get('/admin/users');
        // Fallback: infer from first page listing current user if available
        const current = (me.data?.data || []).find((u: any) => u?.id && u?.email);
        if (current?.role) userRole.value = current.role;
    } catch (e) {
        // ignore; default employee
    }
};

const fetchMyTasks = async () => {
    try {
        const response = await api.get('/my/tasks');
        tasks.value = response.data;
        if (tasks.value.length && selectedTaskId.value === null) {
            selectedTaskId.value = tasks.value[0].id;
        }
        // If currently clocked in and we don't have a running task set, default to first
        if (isClockedIn.value && runningTaskId.value === null && tasks.value.length) {
            runningTaskId.value = tasks.value[0].id;
        }
    } catch (e) {
        // ignore if none
    }
};

// High-level actions mapping to existing endpoints
const timeIn = async () => {
    try {
        await clockIn();
    } catch (e) {}
};
const timeOut = async () => {
    try {
        await clockOut();
    } catch (e) {}
};
const breakIn = async () => {
    try {
        await startBreak();
    } catch (e) {}
};
const breakOut = async () => {
    try {
        await endBreak();
    } catch (e) {}
};

const fetchTodayTaskEntries = async () => {
    try {
        const res = await api.get('/tasks/today-entries');
        taskEntries.value = res.data || [];
    } catch (e) {
        taskEntries.value = [];
    }
};

const openTaskDetails = async (localTaskId: number) => {
    try {
        const res = await api.get(`/tasks/${localTaskId}/sync`);
        taskDetails.value = res.data;
        showTaskModal.value = true;
    } catch (e) {
        showTaskModal.value = false;
    }
};

// Toggle helpers for buttons
const toggleWork = async () => {
    // If on break, clicking Time In ends break (resume work)
    if (isOnBreak.value) {
        await breakOut();
        return;
    }
    if (isClockedIn.value) {
        // If clocked in (not on break), time out
        await timeOut();
    } else {
        await timeIn();
    }
};

const toggleBreak = async () => {
    if (runningTaskId.value) {
        alert('Pause or stop the running task first.');
        return;
    }
    if (isOnBreak.value) {
        await breakOut();
    } else {
        if (!isClockedIn.value) {
            await timeIn();
        }
        await breakIn();
    }
};

// Running timer (hh:mm:ss) based on active phase start
const pad = (n: number) => (n < 10 ? `0${n}` : `${n}`);
const activeStart = computed<Date | null>(() => {
    if (isOnBreak.value && currentEntry.value?.break_start) return new Date(currentEntry.value.break_start);
    if (isClockedIn.value && currentEntry.value?.clock_in) return new Date(currentEntry.value.clock_in);
    return null;
});

// Running task start (open task entry)
const runningTaskStart = computed<Date | null>(() => {
    const open = taskEntries.value.find((t: any) => t.clock_in && !t.clock_out);
    return open ? new Date(open.clock_in) : null;
});

const runningDisplay = computed(() => {
    const start = activeStart.value;
    if (!start) return '00:00:00';
    const diffMs = currentTime.value.getTime() - start.getTime();
    const totalSeconds = Math.max(0, Math.floor(diffMs / 1000));
    const h = Math.floor(totalSeconds / 3600);
    const m = Math.floor((totalSeconds % 3600) / 60);
    const s = totalSeconds % 60;
    return `${pad(h)}:${pad(m)}:${pad(s)}`;
});

const runningTaskDisplay = computed(() => {
    const start = runningTaskStart.value;
    if (!start) return '00:00:00';
    const diffMs = currentTime.value.getTime() - start.getTime();
    const totalSeconds = Math.max(0, Math.floor(diffMs / 1000));
    const h = Math.floor(totalSeconds / 3600);
    const m = Math.floor((totalSeconds % 3600) / 60);
    const s = totalSeconds % 60;
    return `${pad(h)}:${pad(m)}:${pad(s)}`;
});

// Total working hours today for 0/8 Hours display
const workingHoursToday = computed(() => {
    const entry = currentEntry.value;
    if (!entry || !entry.clock_in) return 0;
    const clockIn = new Date(entry.clock_in);
    const end = entry.clock_out ? new Date(entry.clock_out) : currentTime.value;
    let minutes = Math.max(0, Math.floor((end.getTime() - clockIn.getTime()) / 60000));

    // Subtract break duration
    if (entry.break_start && entry.break_end) {
        const bs = new Date(entry.break_start);
        const be = new Date(entry.break_end);
        minutes -= Math.max(0, Math.floor((be.getTime() - bs.getTime()) / 60000));
    } else if (entry.break_start && !entry.break_end) {
        const bs = new Date(entry.break_start);
        minutes -= Math.max(0, Math.floor((currentTime.value.getTime() - bs.getTime()) / 60000));
    }

    // Subtract lunch duration
    if (entry.lunch_start && entry.lunch_end) {
        const ls = new Date(entry.lunch_start);
        const le = new Date(entry.lunch_end);
        minutes -= Math.max(0, Math.floor((le.getTime() - ls.getTime()) / 60000));
    } else if (entry.lunch_start && !entry.lunch_end) {
        const ls = new Date(entry.lunch_start);
        minutes -= Math.max(0, Math.floor((currentTime.value.getTime() - ls.getTime()) / 60000));
    }

    return (Math.max(0, minutes) / 60).toFixed(2);
});

const openDailyLogs = async () => {
    try {
        // Reuse myEntries? We only have /time-entries/my-entries paginated; fetch current day by currentEntry
        showDailyLogs.value = true;
        // Compose a single-entry array for now based on currentEntry and its segments
        todayEntries.value = [currentEntry.value].filter(Boolean);
    } catch (e) {
        showDailyLogs.value = true;
        todayEntries.value = [];
    }
};

const updateStatus = () => {
    if (!currentEntry.value || !currentEntry.value.clock_in) {
        status.value = 'Not clocked in';
    } else if (currentEntry.value.clock_out) {
        status.value = 'Clocked out';
    } else if (isOnLunch.value) {
        status.value = 'On lunch';
    } else if (isOnBreak.value) {
        status.value = 'On break';
    } else {
        status.value = 'Clocked in';
    }
};

const clockIn = async (taskId?: number | null) => {
    loading.value = true;
    try {
        const payload: any = {};
        if (typeof taskId === 'number') payload.task_id = taskId;
        else if (typeof selectedTaskId.value === 'number') payload.task_id = selectedTaskId.value;
        await api.post('/time-entries/clock-in', payload);
        await fetchCurrentEntry();
        await fetchMyTasks();
    } catch (error: any) {
        alert(error.response?.data?.message || 'Error clocking in');
    } finally {
        loading.value = false;
    }
};

const clockOut = async () => {
    loading.value = true;
    try {
        if (runningTaskId.value) {
            alert('Pause or stop the running task first.');
            loading.value = false;
            return;
        }
        await api.post('/time-entries/clock-out');
        await fetchCurrentEntry();
        await fetchMyTasks();
    } catch (error: any) {
        alert(error.response?.data?.message || 'Error clocking out');
    } finally {
        loading.value = false;
    }
};

const startBreak = async () => {
    loading.value = true;
    try {
        await api.post('/time-entries/break-start');
        await fetchCurrentEntry();
        await fetchMyTasks();
    } catch (error: any) {
        alert(error.response?.data?.message || 'Error starting break');
    } finally {
        loading.value = false;
    }
};

const endBreak = async () => {
    loading.value = true;
    try {
        await api.post('/time-entries/break-end');
        await fetchCurrentEntry();
        await fetchMyTasks();
    } catch (error: any) {
        alert(error.response?.data?.message || 'Error ending break');
    } finally {
        loading.value = false;
    }
};

const startLunch = async () => {
    loading.value = true;
    try {
        await api.post('/time-entries/lunch-start');
        await fetchCurrentEntry();
    } catch (error: any) {
        alert(error.response?.data?.message || 'Error starting lunch');
    } finally {
        loading.value = false;
    }
};

// Task row controls
const play = async (taskId: number) => {
    if (!isClockedIn.value) {
        alert('Please Time In first before starting a task.');
        return;
    }
    // If currently on break, resume first
    if (isOnBreak.value) {
        await breakOut();
    }
    // If already clocked in, switch task by clocking out then in on new task
    if (isClockedIn.value) {
        if (runningTaskId.value && runningTaskId.value !== taskId) {
            // Stop current task instead of timing out whole day
            try { await api.post('/tasks/stop'); } catch (e) {}
        }
    }
    await clockIn(taskId);
    runningTaskId.value = taskId;
    await fetchTodayTaskEntries();
};

const pause = async () => {
    // Pause current task
    try { await api.post('/tasks/stop'); } catch (e) {}
    runningTaskId.value = null;
    await fetchTodayTaskEntries();
};

const stop = async () => {
    if (!isClockedIn.value) return;
    // Stop task timer if running, else time out
    try {
        await api.post('/tasks/stop');
    } catch (e) {
        await timeOut();
    }
    runningTaskId.value = null;
    await fetchTodayTaskEntries();
};

const endLunch = async () => {
    loading.value = true;
    try {
        await api.post('/time-entries/lunch-end');
        await fetchCurrentEntry();
    } catch (error: any) {
        alert(error.response?.data?.message || 'Error ending lunch');
    } finally {
        loading.value = false;
    }
};
</script>

<template>
    <Head title="Dashboard" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">
                Time Tracker Dashboard
            </h2>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-7xl sm:px-6 lg:px-8">
                <!-- Header Cards: Work Day Timer, Daily Logs -->
                <div class="mb-6 grid gap-4 md:grid-cols-3">
                    <!-- Work Day Timer -->
                    <div class="overflow-hidden bg-white shadow sm:rounded-lg">
                        <div class="px-4 py-5 sm:p-6">
                            <h3 class="text-xs font-semibold uppercase tracking-wide text-gray-500">Work Day Timer</h3>
                            <p class="mt-2 text-3xl font-bold text-gray-900">{{ runningDisplay }}</p>
                            <p class="text-xs text-gray-500">{{ isOnBreak ? 'On Break' : isClockedIn ? 'Working' : 'Idle' }}</p>
                            <div class="mt-4 flex flex-wrap gap-2" v-if="userRole !== 'admin'">
                                <button @click="toggleWork" :disabled="loading" :class="['rounded-md px-3 py-1.5 text-xs font-semibold text-white', isClockedIn ? 'bg-red-600 hover:bg-red-700' : 'bg-green-600 hover:bg-green-700']">
                                    {{ isClockedIn ? 'Time Out' : 'Time In' }}
                                </button>
                                <button @click="toggleBreak" :disabled="loading" :class="['rounded-md px-3 py-1.5 text-xs font-semibold text-white', isOnBreak ? 'bg-orange-600 hover:bg-orange-700' : 'bg-yellow-600 hover:bg-yellow-700']">
                                    {{ isOnBreak ? 'Break Out' : 'Break In' }}
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Running Task Widget -->
                    <div class="overflow-hidden bg-white shadow sm:rounded-lg">
                        <div class="px-4 py-5 sm:p-6">
                            <h3 class="text-xs font-semibold uppercase tracking-wide text-gray-500">Running Task</h3>
                            <div v-if="runningTaskId" class="mt-2">
                                <button @click="openTaskDetails(runningTaskId)" class="text-indigo-600 hover:underline text-sm">
                                    {{ tasks.find(t => t.id === runningTaskId)?.title || ('#' + runningTaskId) }}
                                </button>
                                <p class="text-2xl font-bold text-gray-900 mt-1">{{ runningTaskDisplay }}</p>
                            </div>
                            <div v-else class="mt-2 text-sm text-gray-500">No task running</div>
                        </div>
                    </div>

                    <!-- Daily Logs (summary with modal) -->
                    <div class="overflow-hidden bg-white shadow sm:rounded-lg">
                        <div class="px-4 py-5 sm:p-6">
                            <h3 class="text-xs font-semibold uppercase tracking-wide text-gray-500">Daily Logs</h3>
                            <p class="mt-2 text-3xl font-bold text-gray-900">{{ workingHoursToday }}<span class="text-sm font-normal text-gray-500"> / 8 Hours</span></p>
                            <div class="mt-4 flex space-x-2">
                                <button @click="openDailyLogs" class="rounded-md bg-indigo-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-indigo-700">Daily Logs</button>
                                <button class="rounded-md bg-yellow-500 px-3 py-1.5 text-xs font-semibold text-white hover:bg-yellow-600">Send Report</button>
                            </div>
                        </div>
                    </div>
                    
                </div>

                <!-- Removed Running Task and Status widgets per request -->

                <!-- Manager/Employee: Task List with Play/Pause/Stop -->
                <div v-if="userRole !== 'admin'" class="mb-6 overflow-hidden bg-white shadow sm:rounded-lg">
                    <div class="px-4 py-5 sm:p-6">
                        <div class="flex items-center justify-between mb-3">
                            <h3 class="text-lg font-medium leading-6 text-gray-900">My Tasks</h3>
                            <!-- dropdown removed as requested -->
                        </div>
                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Task</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Actions</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-200 bg-white">
                                    <tr v-for="t in tasks" :key="t.id">
                                        <td class="whitespace-nowrap px-6 py-4 text-sm text-indigo-600">
                                            <button @click="openTaskDetails(t.id)" class="hover:underline">{{ t.title }}</button>
                                        </td>
                                        <td class="whitespace-nowrap px-6 py-4 text-sm text-gray-900">
                                            <div class="flex items-center space-x-2">
                                                <button @click="runningTaskId === t.id ? pause() : play(t.id)" :disabled="loading" class="rounded-full p-2 text-white" :class="runningTaskId === t.id ? 'bg-yellow-600 hover:bg-yellow-700' : 'bg-green-600 hover:bg-green-700'" :aria-label="runningTaskId === t.id ? 'Pause' : 'Play'">
                                                    <svg v-if="runningTaskId !== t.id" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="h-4 w-4"><path d="M8 5v14l11-7z"/></svg>
                                                    <svg v-else xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="h-4 w-4"><path d="M6 5h4v14H6zM14 5h4v14h-4z"/></svg>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Admin: show only time and logs (events table below still applies) -->
                <div v-else class="mb-6 overflow-hidden bg-white shadow sm:rounded-lg">
                    <div class="px-4 py-5 sm:p-6 text-sm text-gray-600">
                        Admin view: see current time above and the real-time events table below.
                    </div>
                </div>

                <!-- Time Events Table -->
                <div class="overflow-hidden bg-white shadow sm:rounded-lg">
                    <div class="px-4 py-5 sm:p-6">
                        <h3 class="text-lg font-medium leading-6 text-gray-900 mb-4">Today's Time Events</h3>
                        
                        <div v-if="timeEvents.length === 0" class="text-center py-8 text-gray-500">
                            No time entries recorded today. Clock in to start tracking time.
                        </div>
                        
                        <div v-else class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">
                                            Event Type
                                        </th>
                                        <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">
                                            Start Time
                                        </th>
                                        <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">
                                            End Time
                                        </th>
                                        <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">
                                            Duration
                                        </th>
                                        <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">
                                            Status
                                        </th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-200 bg-white">
                                    <tr v-for="(event, index) in timeEvents" :key="index">
                                        <td class="whitespace-nowrap px-6 py-4 text-sm font-medium text-gray-900">
                                            {{ event.type }}
                                        </td>
                                        <td class="whitespace-nowrap px-6 py-4 text-sm text-gray-500">
                                            {{ formatTime(event.start) }}
                                        </td>
                                        <td class="whitespace-nowrap px-6 py-4 text-sm text-gray-500">
                                            {{ formatTime(event.end) }}
                                        </td>
                                        <td class="whitespace-nowrap px-6 py-4 text-sm text-gray-500">
                                            {{ event.duration }}
                                        </td>
                                        <td class="whitespace-nowrap px-6 py-4 text-sm text-gray-500">
                                            <div class="flex items-center space-x-2">
                                                <button @click="timeIn" :disabled="loading || isClockedIn" class="rounded-md bg-green-600 px-2 py-1 text-xs font-semibold text-white hover:bg-green-700">Time In</button>
                                                <button @click="breakIn" :disabled="loading || !isClockedIn || isOnBreak" class="rounded-md bg-yellow-600 px-2 py-1 text-xs font-semibold text-white hover:bg-yellow-700">Break In</button>
                                                <button @click="breakOut" :disabled="loading || !isOnBreak" class="rounded-md bg-orange-600 px-2 py-1 text-xs font-semibold text-white hover:bg-orange-700">Break Out</button>
                                                <button @click="timeOut" :disabled="loading || !isClockedIn" class="rounded-md bg-red-600 px-2 py-1 text-xs font-semibold text-white hover:bg-red-700">Time Out</button>
                                            </div>
                                        </td>
                                    </tr>
                                    <!-- Task entries -->
                                    <tr v-for="t in taskEntries" :key="`task-${t.id}`">
                                        <td class="whitespace-nowrap px-6 py-4 text-sm font-medium text-gray-900">
                                            Task: {{ t.task?.title || `#${t.task_id}` }}
                                        </td>
                                        <td class="whitespace-nowrap px-6 py-4 text-sm text-gray-500">
                                            {{ formatTime(t.clock_in) }}
                                        </td>
                                        <td class="whitespace-nowrap px-6 py-4 text-sm text-gray-500">
                                            {{ formatTime(t.clock_out) }}
                                        </td>
                                        <td class="whitespace-nowrap px-6 py-4 text-sm text-gray-500">
                                            {{ t.clock_in && t.clock_out ? calculateDuration(t.clock_in, t.clock_out) : (t.clock_in ? 'In progress' : '--') }}
                                        </td>
                                        <td class="whitespace-nowrap px-6 py-4 text-sm text-gray-500">
                                            <div class="flex items-center space-x-2">
                                                <button @click="play(t.task_id)" :disabled="loading" class="rounded-md bg-green-600 px-2 py-1 text-xs font-semibold text-white hover:bg-green-700">Play</button>
                                                <button @click="stop()" :disabled="loading" class="rounded-md bg-red-600 px-2 py-1 text-xs font-semibold text-white hover:bg-red-700">Stop</button>
                                            </div>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
            <!-- Daily Logs Modal -->
            <div v-if="showDailyLogs" class="fixed inset-0 z-50 flex items-center justify-center bg-black bg-opacity-50">
                <div class="w-full max-w-2xl rounded-lg bg-white shadow-lg">
                    <div class="flex items-center justify-between border-b px-4 py-3">
                        <h4 class="text-md font-semibold">Daily Logs</h4>
                        <button @click="showDailyLogs = false" class="rounded-md bg-gray-100 px-2 py-1 text-xs">Close</button>
                    </div>
                    <div class="p-4">
                        <div v-if="!currentEntry" class="text-gray-500 text-sm">No entry today.</div>
                        <div v-else class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th class="px-4 py-2 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Event</th>
                                        <th class="px-4 py-2 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Start Time</th>
                                        <th class="px-4 py-2 text-left text-xs font-medium uppercase tracking-wider text-gray-500">End Time</th>
                                        <th class="px-4 py-2 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Duration</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-200 bg-white">
                                    <tr v-for="(event, idx) in timeEvents" :key="idx">
                                        <td class="px-4 py-2 text-sm text-gray-900">{{ event.type }}</td>
                                        <td class="px-4 py-2 text-sm text-gray-700">{{ formatTime(event.start) }}</td>
                                        <td class="px-4 py-2 text-sm text-gray-700">{{ formatTime(event.end) }}</td>
                                        <td class="px-4 py-2 text-sm text-gray-700">{{ event.duration }}</td>
                                    </tr>
                                    <tr v-for="t in taskEntries" :key="`modal-task-${t.id}`">
                                        <td class="px-4 py-2 text-sm text-gray-900">Task: {{ t.task?.title || `#${t.task_id}` }}</td>
                                        <td class="px-4 py-2 text-sm text-gray-700">{{ formatTime(t.clock_in) }}</td>
                                        <td class="px-4 py-2 text-sm text-gray-700">{{ formatTime(t.clock_out) }}</td>
                                        <td class="px-4 py-2 text-sm text-gray-700">{{ t.clock_in && t.clock_out ? calculateDuration(t.clock_in, t.clock_out) : (t.clock_in ? 'In progress' : '--') }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Task Details Modal -->
            <div v-if="showTaskModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black bg-opacity-50">
                <div class="w-full max-w-lg rounded-lg bg-white shadow-lg">
                    <div class="flex items-center justify-between border-b px-4 py-3">
                        <h4 class="text-md font-semibold">Task Details</h4>
                        <button @click="showTaskModal = false" class="rounded-md bg-gray-100 px-2 py-1 text-xs">Close</button>
                    </div>
                    <div class="p-4 text-sm text-gray-700" v-if="taskDetails">
                        <p class="font-medium mb-1">{{ taskDetails.task?.title }}</p>
                        <p class="mb-2">Status: {{ taskDetails.task?.status }}</p>
                        <p class="mb-2" v-if="taskDetails.clickup?.url">
                            <a :href="taskDetails.clickup.url" target="_blank" class="text-indigo-600 hover:underline">Open in ClickUp</a>
                        </p>
                        <div class="prose max-w-none" v-if="taskDetails.clickup?.text_content">
                            {{ taskDetails.clickup.text_content }}
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
