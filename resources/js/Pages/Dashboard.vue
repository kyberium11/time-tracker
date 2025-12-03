<script setup lang="ts">
import { ref, onMounted, computed, onUnmounted } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, usePage } from '@inertiajs/vue3';
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
    priority?: string | null;
    due_date?: string | null;
    clickup_task_id: string;
    estimated_time?: number | null; // in milliseconds
    clickup_list_name?: string | null;
    parent_task_name?: string | null;
}

interface TimeEvent {
    type: string;
    start: string | null;
    end: string | null;
    duration: string;
    status: string;
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

interface ClickUpSpaceOption {
    id: string;
    name: string;
    team_id?: string | null;
    team_name?: string | null;
    color?: string | null;
}

const currentEntry = ref<TimeEntry | null>(null);
const loading = ref(false);
const status = ref('');
const currentTime = ref(new Date());
const userRole = ref<'admin' | 'manager' | 'employee' | 'developer'>('employee');
const tasks = ref<TaskItem[]>([]);
const selectedTaskId = ref<number | null>(null); // deprecated UI, will be removed
const runningTaskId = ref<number | null>(null);
const showDailyLogs = ref(false);
const todayEntries = ref<any[]>([]);
const todayWorkSeconds = ref<number>(0);
const todayTaskSeconds = ref<number>(0);
const sendingReport = ref(false);
const taskEntries = ref<any[]>([]);
const taskDetails = ref<any | null>(null);
const showTaskModal = ref(false);
const showTaskSyncModal = ref(false);
const taskSyncLoading = ref(false);
const taskSyncResult = ref<{
    summary: { total: number; created: number; updated: number; unchanged: number; skipped: number };
    created: Array<{ id: string; title: string; status: string | null; priority: string | null; due_date: string | null }>;
    updated: Array<{ id: string; title: string; status: string | null; priority: string | null; due_date: string | null }>;
    skipped: Array<{ id: string; reason: string }>;
    sources: string[];
} | null>(null);
const showSpaceSelectionModal = ref(false);
const spaceSelectionLoading = ref(false);
const spaceSelectionError = ref<string | null>(null);
const spaceOptions = ref<ClickUpSpaceOption[]>([]);
const selectedSpaceIds = ref<string[]>([]);
const hasSpaceOptions = computed(() => spaceOptions.value.length > 0);
const allSpacesSelected = computed(() => hasSpaceOptions.value && selectedSpaceIds.value.length === spaceOptions.value.length);

// Sequential space sync state
const sequentialSyncActive = ref(false);
const currentSpaceIndex = ref(0);
const configuredSpaceIds = ref<string[]>([]);
const currentSpaceInfo = ref<{ space_id: string; name: string; is_member: boolean } | null>(null);
const spaceSyncProgress = ref<{
    current: number;
    total: number;
    spaceName: string;
    status: 'checking' | 'syncing' | 'completed' | 'skipped' | 'error';
    result?: any;
} | null>(null);
const spaceSyncResults = ref<Array<{ space_id: string; name: string; result: any }>>([]);

// Tab system for employees
const activeTab = ref<'dashboard' | 'time-entries'>('dashboard');

// Time Entries Tab Data
const timeEntriesDate = ref('');
const timeEntriesData = ref<any[]>([]);
const timeEntriesLoading = ref(false);
const timeEntriesSummary = ref({
    workSeconds: 0,
    breakSeconds: 0,
    lunchSeconds: 0,
    tasksCount: 0,
    status: 'No Entry',
    overtimeSeconds: 0,
});
const timeEntriesRows = ref<any[]>([]);

// My Tasks table controls
const taskSearch = ref('');
const currentTaskPage = ref(1);
const tasksPerPage = ref(10);
const taskStatusFilter = ref<string>('all');
const availableStatuses = computed<string[]>(() => {
    const set = new Set<string>();
    (tasks.value || []).forEach(t => {
        const s = (t.status || '').toString().trim();
        if (s) set.add(s);
    });
    return Array.from(set).sort((a, b) => a.localeCompare(b));
});
const taskPriorityFilter = ref<'all' | 'urgent' | 'high' | 'normal' | 'low' | 'none'>('all');
const dueStart = ref<string>('');
const dueEnd = ref<string>('');

const filteredTasks = computed(() => {
    const q = taskSearch.value.trim().toLowerCase();
    let base = tasks.value;
    if (taskStatusFilter.value !== 'all') {
        const target = taskStatusFilter.value.toLowerCase();
        base = base.filter(t => (t.status || '').toLowerCase() === target);
    }
    if (taskPriorityFilter.value !== 'all') {
        const p = taskPriorityFilter.value;
        base = base.filter(t => (t.priority || 'none').toLowerCase() === p);
    }
    if (dueStart.value) {
        const start = new Date(dueStart.value).getTime();
        base = base.filter(t => t.due_date ? new Date(t.due_date).getTime() >= start : false);
    }
    if (dueEnd.value) {
        const end = new Date(dueEnd.value).getTime();
        base = base.filter(t => t.due_date ? new Date(t.due_date).getTime() <= end : false);
    }
    if (!q) return base;
    return base.filter(t => (t.title || '').toLowerCase().includes(q));
});

const totalTaskPages = computed(() => {
    return Math.max(1, Math.ceil(filteredTasks.value.length / tasksPerPage.value));
});

const paginatedTasks = computed(() => {
    const page = Math.min(currentTaskPage.value, totalTaskPages.value);
    const start = (page - 1) * tasksPerPage.value;
    return filteredTasks.value.slice(start, start + tasksPerPage.value);
});

const goTaskPage = (page: number) => {
    const clamped = Math.max(1, Math.min(page, totalTaskPages.value));
    currentTaskPage.value = clamped;
};


let timeInterval: number | null = null;
let notificationCheckInterval: number | null = null;

// Notification tracking
const breakNotification13Sent = ref(false);
const breakNotification15Sent = ref(false);
const lunchNotificationSent = ref(false);
const hasTimedOutToday = ref(false);

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

// Generate time events from current entry - formatted like user summary
const todayTimeEntries = computed(() => {
    const rows: any[] = [];
    
    if (!currentEntry.value) return rows;
    
    const entry = currentEntry.value;
    
    // Work Hours entry (if clocked in and out)
    if (entry.clock_in && entry.clock_out) {
        const workDur = Math.max(0, Math.floor((new Date(entry.clock_out).getTime() - new Date(entry.clock_in).getTime()) / 1000));
        const bs = entry.break_start ? new Date(entry.break_start) : null;
        const be = entry.break_end ? new Date(entry.break_end) : null;
        let breakDur = 0;
        if (bs && be) {
            breakDur = Math.max(0, Math.floor((be.getTime() - bs.getTime()) / 1000));
        }
        const netWorkDur = Math.max(0, workDur - breakDur);
        
        rows.push({
            name: 'Work Hours',
            start: entry.clock_in,
            end: entry.clock_out,
            durationSeconds: netWorkDur,
            breakDurationSeconds: breakDur,
            notes: '-'
        });
    }
    
    // Break entry (if exists)
    if (entry.break_start && entry.break_end) {
        const breakDur = Math.max(0, Math.floor((new Date(entry.break_end).getTime() - new Date(entry.break_start).getTime()) / 1000));
        rows.push({
            name: 'Break',
            start: entry.break_start,
            end: entry.break_end,
            durationSeconds: breakDur,
            breakDurationSeconds: breakDur,
            notes: '-'
        });
    }
    
    // Add task entries
    taskEntries.value.forEach((t: any) => {
        if (t.clock_in && t.clock_out) {
            const taskDur = Math.max(0, Math.floor((new Date(t.clock_out).getTime() - new Date(t.clock_in).getTime()) / 1000));
            rows.push({
                name: t.task?.title || `Task #${t.task_id}`,
                start: t.clock_in,
                end: t.clock_out,
                durationSeconds: taskDur,
                breakDurationSeconds: 0,
                notes: '-'
            });
        }
    });
    
    // Sort by start time (most recent first)
    rows.sort((a, b) => {
        const da = new Date(a.start).getTime();
        const db = new Date(b.start).getTime();
        return (isNaN(db) ? 0 : db) - (isNaN(da) ? 0 : da);
    });
    
    return rows;
});

// Browser notification functions
const requestNotificationPermission = async () => {
    if ('Notification' in window) {
        if (Notification.permission === 'default') {
            await Notification.requestPermission();
        }
    }
};

const showNotification = (title: string, body: string, options?: NotificationOptions) => {
    if ('Notification' in window && Notification.permission === 'granted') {
        new Notification(title, {
            body,
            icon: '/favicon.ico',
            badge: '/favicon.ico',
            tag: 'time-tracker-notification',
            requireInteraction: false,
            ...options,
        });
    }
};

// Check break duration and send notifications
const checkBreakNotifications = () => {
    if (isOnBreak.value && currentEntry.value?.break_start) {
        const breakStart = new Date(currentEntry.value.break_start);
        const now = currentTime.value;
        const breakSeconds = Math.floor((now.getTime() - breakStart.getTime()) / 1000);
        const breakMinutes = Math.floor(breakSeconds / 60);

        // Notify at 13 minutes (close to 15 minute limit)
        if (breakMinutes >= 13 && !breakNotification13Sent.value) {
            showNotification(
                'Break Reminder',
                'You\'re approaching the 15-minute break limit. Please end your break soon.',
                { tag: 'break-13-min' }
            );
            breakNotification13Sent.value = true;
        }

        // Notify at 15 minutes (overbreak)
        if (breakMinutes >= 15 && !breakNotification15Sent.value) {
            showNotification(
                'Break Over Limit',
                'You\'ve exceeded the 15-minute break limit. Please end your break now.',
                { tag: 'break-15-min', requireInteraction: true }
            );
            breakNotification15Sent.value = true;
        }
    } else {
        // Reset notifications when break ends
        if (!isOnBreak.value) {
            breakNotification13Sent.value = false;
            breakNotification15Sent.value = false;
        }
    }
};

// Check lunch notification on first time out of the day
const checkLunchNotification = async () => {
    // Only check on first time out of the day
    if (hasTimedOutToday.value || lunchNotificationSent.value) {
        return;
    }

    // Only check when user is clocked out (not during active work)
    if (isClockedIn.value) {
        return;
    }

    // Check if user has timed out (has clock_out) and this is the first time out today
    if (currentEntry.value?.clock_out && currentEntry.value?.clock_in) {
        const clockInTime = new Date(currentEntry.value.clock_in);
        const clockOutTime = new Date(currentEntry.value.clock_out);
        const workDurationSeconds = Math.floor((clockOutTime.getTime() - clockInTime.getTime()) / 1000);
        const workDurationHours = workDurationSeconds / 3600;

        // Check if it's been 1 hour and no lunch was taken
        if (workDurationHours >= 1 && !currentEntry.value.lunch_start && !lunchNotificationSent.value) {
            showNotification(
                'Lunch Reminder',
                'You\'ve been working for 1 hour without taking lunch. Please remember to take your lunch break.',
                { tag: 'lunch-reminder', requireInteraction: true }
            );
            lunchNotificationSent.value = true;
            hasTimedOutToday.value = true;
        }
    }
};

// Monitor notifications every minute
const startNotificationMonitoring = () => {
    if (notificationCheckInterval) {
        clearInterval(notificationCheckInterval);
    }
    
    notificationCheckInterval = window.setInterval(() => {
        checkBreakNotifications();
        // Only check lunch notification periodically if not already sent
        if (!lunchNotificationSent.value) {
            checkLunchNotification();
        }
    }, 60000); // Check every minute
};

// Prevent closing/refreshing if clocked in
const handleBeforeUnload = (e: BeforeUnloadEvent) => {
    // Check if user is clocked in (has clock_in but no clock_out)
    if (isClockedIn.value) {
        // Modern browsers require returnValue to be set
        e.preventDefault();
        e.returnValue = ''; // Chrome requires returnValue to be set
        return ''; // For older browsers
    }
};

onMounted(async () => {
    const today = new Date();
    timeEntriesDate.value = today.toISOString().split('T')[0];
    
    // Request notification permission
    await requestNotificationPermission();
    
    fetchCurrentEntry();
    fetchUserRole();
    fetchMyTasks();
    fetchTodayTaskEntries();
    // Preload today's full entries for accurate daily logs and 0/8 hours
    loadTodayEntries();
    // Update clock every second
    timeInterval = window.setInterval(() => {
        currentTime.value = new Date();
    }, 1000);
    
    // Start notification monitoring
    startNotificationMonitoring();
    
    // Add beforeunload event listener to prevent closing if clocked in
    window.addEventListener('beforeunload', handleBeforeUnload);
});

onUnmounted(() => {
    if (timeInterval) {
        window.clearInterval(timeInterval);
    }
    if (notificationCheckInterval) {
        clearInterval(notificationCheckInterval);
    }
    // Remove beforeunload event listener
    window.removeEventListener('beforeunload', handleBeforeUnload);
});

const fetchCurrentEntry = async () => {
    try {
        const response = await api.get('/time-entries/current');
        currentEntry.value = response.data;
        updateStatus();
        
        // Reset break notifications when break state changes
        if (!isOnBreak.value) {
            breakNotification13Sent.value = false;
            breakNotification15Sent.value = false;
        }
        
        // Reset lunch notification at start of new day
        const today = new Date().toISOString().split('T')[0];
        if (timeEntriesDate.value !== today) {
            lunchNotificationSent.value = false;
            hasTimedOutToday.value = false;
        }
    } catch (error) {
        currentEntry.value = null;
        status.value = 'No entry today';
        console.error('Error fetching current entry:', error);
    }
};

const page = usePage();
const fetchUserRole = async () => {
    // Use role from page props (Inertia) - this has the actual role including developer
    // The API returns developers as "employee" for admin views, so we can't use that
    const authUser = page.props.auth?.user;
    if (authUser?.role) {
        const role = authUser.role as 'admin' | 'manager' | 'employee' | 'developer';
        if (['admin', 'manager', 'employee', 'developer'].includes(role)) {
            userRole.value = role;
        }
    } else {
        // Fallback: try to get from API if props not available
        try {
            const me = await api.get('/admin/users');
            const current = (me.data?.data || []).find((u: any) => u?.id && u?.email);
            if (current?.role) {
                const role = current.role as 'admin' | 'manager' | 'employee' | 'developer';
                if (['admin', 'manager', 'employee', 'developer'].includes(role)) {
                    userRole.value = role;
                }
            }
        } catch (e) {
            // ignore; default employee
        }
    }
};

const fetchMyTasks = async () => {
    try {
        const response = await api.get('/my/tasks');
        tasks.value = response.data;
        // Do NOT auto-select or auto-start any task to avoid unintended runs
    } catch (e) {
        // ignore if none
    }
};

const resetTaskSyncModal = () => {
    showTaskSyncModal.value = false;
    taskSyncResult.value = null;
};

const loadAvailableClickUpSpaces = async (forceReload = false) => {
    if (spaceSelectionLoading.value) {
        return;
    }
    if (!forceReload && spaceOptions.value.length > 0) {
        return;
    }
    spaceSelectionLoading.value = true;
    spaceSelectionError.value = null;
    try {
        const res = await api.get('/my/clickup/spaces');
        const fetched = (res.data?.spaces || []).map((space: any) => ({
            id: String(space.id),
            name: space.name || `Space ${space.id}`,
            team_id: space.team_id ?? null,
            team_name: space.team_name ?? null,
            color: space.color ?? null,
        })) as ClickUpSpaceOption[];
        spaceOptions.value = fetched;

        const availableIds = fetched.map(space => space.id);
        if (selectedSpaceIds.value.length === 0) {
            selectedSpaceIds.value = availableIds;
        } else {
            selectedSpaceIds.value = selectedSpaceIds.value.filter(id => availableIds.includes(id));
            if (selectedSpaceIds.value.length === 0) {
                selectedSpaceIds.value = availableIds;
            }
        }
    } catch (e: any) {
        spaceSelectionError.value = e?.response?.data?.error || 'Failed to load ClickUp spaces.';
        spaceOptions.value = [];
        selectedSpaceIds.value = [];
    } finally {
        spaceSelectionLoading.value = false;
    }
};

const openSpaceSelectionModal = async () => {
    if (taskSyncLoading.value || sequentialSyncActive.value) return;
    await startSequentialSpaceSync();
};

const startSequentialSpaceSync = async () => {
    if (sequentialSyncActive.value) return;
    
    sequentialSyncActive.value = true;
    spaceSyncResults.value = [];
    currentSpaceIndex.value = 0;
    spaceSyncProgress.value = null;
    
    try {
        // Get configured space IDs from backend
        const res = await api.get('/my/clickup/configured-spaces');
        configuredSpaceIds.value = res.data?.space_ids || [];
        
        if (configuredSpaceIds.value.length === 0) {
            alert('No ClickUp spaces configured. Please set CLICKUP_SPACE_IDS in your environment variables.');
            sequentialSyncActive.value = false;
            return;
        }
        
        showSpaceSelectionModal.value = true;
        await processNextSpace();
    } catch (e: any) {
        alert(e?.response?.data?.error || 'Failed to start space sync');
        sequentialSyncActive.value = false;
        showSpaceSelectionModal.value = false;
    }
};

const processNextSpace = async () => {
    if (currentSpaceIndex.value >= configuredSpaceIds.value.length) {
        // All spaces processed
        sequentialSyncActive.value = false;
        showSpaceSelectionModal.value = false;
        await fetchMyTasks();
        
        // Show summary
        const totalSpaces = configuredSpaceIds.value.length;
        const syncedSpaces = spaceSyncResults.value.filter(r => r.result?.ok).length;
        const skippedSpaces = spaceSyncResults.value.filter(r => !r.result?.ok && r.result?.skipped).length;
        
        taskSyncResult.value = {
            summary: {
                total: spaceSyncResults.value.reduce((sum, r) => sum + (r.result?.summary?.total || 0), 0),
                created: spaceSyncResults.value.reduce((sum, r) => sum + (r.result?.summary?.created || 0), 0),
                updated: spaceSyncResults.value.reduce((sum, r) => sum + (r.result?.summary?.updated || 0), 0),
                unchanged: spaceSyncResults.value.reduce((sum, r) => sum + (r.result?.summary?.unchanged || 0), 0),
                skipped: spaceSyncResults.value.reduce((sum, r) => sum + (r.result?.summary?.skipped || 0), 0),
            },
            created: spaceSyncResults.value.flatMap(r => r.result?.created || []),
            updated: spaceSyncResults.value.flatMap(r => r.result?.updated || []),
            skipped: spaceSyncResults.value.flatMap(r => r.result?.skipped || []),
            sources: spaceSyncResults.value.map(r => `Space: ${r.name} (${r.space_id})`),
        };
        showTaskSyncModal.value = true;
        return;
    }
    
    const spaceId = configuredSpaceIds.value[currentSpaceIndex.value];
    
    // Update progress
    spaceSyncProgress.value = {
        current: currentSpaceIndex.value + 1,
        total: configuredSpaceIds.value.length,
        spaceName: 'Loading...',
        status: 'checking',
    };
    
    try {
        // Get space info and check membership
        const infoRes = await api.get(`/my/clickup/space/${spaceId}/info`);
        currentSpaceInfo.value = infoRes.data;
        
        if (!currentSpaceInfo.value) {
            spaceSyncProgress.value.status = 'error';
            spaceSyncResults.value.push({
                space_id: spaceId,
                name: 'Unknown',
                result: { ok: false, error: 'Failed to get space info' },
            });
            currentSpaceIndex.value++;
            setTimeout(() => {
                processNextSpace();
            }, 1000);
            return;
        }

        spaceSyncProgress.value = {
            ...spaceSyncProgress.value,
            spaceName: currentSpaceInfo.value.name,
        };
        
        if (!currentSpaceInfo.value.is_member) {
            // User is not a member, skip
            spaceSyncProgress.value.status = 'skipped';
            spaceSyncResults.value.push({
                space_id: spaceId,
                name: currentSpaceInfo.value.name,
                result: { ok: false, skipped: true, reason: 'User is not a member of this space' },
            });
            
            currentSpaceIndex.value++;
            // Auto-advance to next space after a short delay
            setTimeout(() => {
                processNextSpace();
            }, 1000);
            return;
        }
        
        // User is a member - wait for approval
        spaceSyncProgress.value.status = 'checking';
    } catch (e: any) {
        spaceSyncProgress.value.status = 'error';
        spaceSyncResults.value.push({
            space_id: spaceId,
            name: 'Unknown',
            result: { ok: false, error: e?.response?.data?.error || 'Failed to get space info' },
        });
        currentSpaceIndex.value++;
        setTimeout(() => {
            processNextSpace();
        }, 1000);
    }
};

const approveSpaceSync = async () => {
    if (!currentSpaceInfo.value || !spaceSyncProgress.value) return;
    
    const spaceId = currentSpaceInfo.value.space_id;
    if (!spaceId) return;
    spaceSyncProgress.value.status = 'syncing';
    
    try {
        const syncRes = await api.post(`/my/clickup/space/${spaceId}/sync`);
        spaceSyncProgress.value.status = 'completed';
        spaceSyncProgress.value.result = syncRes.data;
        
        spaceSyncResults.value.push({
            space_id: spaceId,
            name: currentSpaceInfo.value.name,
            result: syncRes.data,
        });
        
        currentSpaceIndex.value++;
        // Move to next space after showing completion
        setTimeout(() => {
            processNextSpace();
        }, 1500);
    } catch (e: any) {
        spaceSyncProgress.value.status = 'error';
        spaceSyncResults.value.push({
            space_id: spaceId,
            name: currentSpaceInfo.value.name,
            result: { ok: false, error: e?.response?.data?.error || 'Failed to sync space' },
        });
        currentSpaceIndex.value++;
        setTimeout(() => {
            processNextSpace();
        }, 1500);
    }
};

const skipSpaceSync = () => {
    if (!currentSpaceInfo.value) return;
    
    const spaceId = currentSpaceInfo.value.space_id;
    if (!spaceId) return;
    
    spaceSyncResults.value.push({
        space_id: spaceId,
        name: currentSpaceInfo.value.name,
        result: { ok: false, skipped: true, reason: 'User skipped this space' },
    });
    
    currentSpaceIndex.value++;
    processNextSpace();
};

const cancelSequentialSync = () => {
    sequentialSyncActive.value = false;
    showSpaceSelectionModal.value = false;
    currentSpaceIndex.value = 0;
    spaceSyncProgress.value = null;
    currentSpaceInfo.value = null;
    spaceSyncResults.value = [];
};

const toggleSelectAllSpaces = () => {
    if (!hasSpaceOptions.value) {
        selectedSpaceIds.value = [];
        return;
    }
    if (allSpacesSelected.value) {
        selectedSpaceIds.value = [];
        return;
    }
    selectedSpaceIds.value = spaceOptions.value.map(space => space.id);
};

const confirmSpaceSelection = async () => {
    if (taskSyncLoading.value || spaceSelectionLoading.value) return;
    if (!selectedSpaceIds.value.length) {
        alert('Select at least one space to sync.');
        return;
    }
    showSpaceSelectionModal.value = false;
    await refreshMyTasksFromClickUp([...selectedSpaceIds.value]);
};

const syncAllSpacesFallback = async () => {
    if (taskSyncLoading.value) return;
    showSpaceSelectionModal.value = false;
    await refreshMyTasksFromClickUp();
};

const refreshMyTasksFromClickUp = async (spaceIds: string[] | null = null) => {
    taskSyncLoading.value = true;
    try {
        const payload = spaceIds && spaceIds.length ? { space_ids: spaceIds } : {};
        const res = await api.post('/my/clickup/sync-tasks', payload);
        await fetchMyTasks();

        const summary = res.data?.summary ?? {};
        taskSyncResult.value = {
            summary: {
                total: summary.total ?? 0,
                created: summary.created ?? 0,
                updated: summary.updated ?? 0,
                unchanged: summary.unchanged ?? 0,
                skipped: summary.skipped ?? 0,
            },
            created: res.data?.created ?? [],
            updated: res.data?.updated ?? [],
            skipped: res.data?.skipped ?? [],
            sources: res.data?.sources ?? [],
        };
        showTaskSyncModal.value = true;
    } catch (e: any) {
        alert(e?.response?.data?.error || 'Failed to sync tasks from ClickUp');
    } finally {
        taskSyncLoading.value = false;
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
        const open = (taskEntries.value || []).find((t: any) => t.clock_in && !t.clock_out);
        runningTaskId.value = open ? open.task_id : null;
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
// Work timer - always runs from clock_in, never stops for breaks
const activeStart = computed<Date | null>(() => {
    if (isClockedIn.value && currentEntry.value?.clock_in) return new Date(currentEntry.value.clock_in);
    return null;
});

// Break timer - separate timer for breaks
const breakStart = computed<Date | null>(() => {
    if (isOnBreak.value && currentEntry.value?.break_start) return new Date(currentEntry.value.break_start);
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

// Break timer display
const breakDisplay = computed(() => {
    const start = breakStart.value;
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

// Total working hours today for 0/8 Hours display (real-time)
const workingHoursToday = computed(() => {
    // Start with completed work seconds from API (includes completed work entries and completed task entries)
    let totalSeconds = todayWorkSeconds.value || 0;
    
    // Add current running work entry session if clocked in (work entry without clock_out)
    // This is the general "clocked in" time, not task-specific
    if (isClockedIn.value && currentEntry.value?.clock_in && !currentEntry.value?.clock_out) {
        const clockInTime = new Date(currentEntry.value.clock_in);
        const now = currentTime.value;
        const sessionSeconds = Math.max(0, Math.floor((now.getTime() - clockInTime.getTime()) / 1000));
        
        // Subtract break time if on break or if break was taken
        let breakSeconds = 0;
        if (currentEntry.value.break_start) {
            if (!currentEntry.value.break_end) {
                // Currently on break - subtract current break time
                const breakStart = new Date(currentEntry.value.break_start);
                breakSeconds = Math.max(0, Math.floor((now.getTime() - breakStart.getTime()) / 1000));
            } else {
                // Break completed - subtract total break time
                const breakStart = new Date(currentEntry.value.break_start);
                const breakEnd = new Date(currentEntry.value.break_end);
                breakSeconds = Math.max(0, Math.floor((breakEnd.getTime() - breakStart.getTime()) / 1000));
            }
        }
        
        // Subtract lunch time if on lunch or if lunch was taken
        let lunchSeconds = 0;
        if (currentEntry.value.lunch_start) {
            if (!currentEntry.value.lunch_end) {
                // Currently on lunch - subtract current lunch time
                const lunchStart = new Date(currentEntry.value.lunch_start);
                lunchSeconds = Math.max(0, Math.floor((now.getTime() - lunchStart.getTime()) / 1000));
            } else {
                // Lunch completed - subtract total lunch time
                const lunchStart = new Date(currentEntry.value.lunch_start);
                const lunchEnd = new Date(currentEntry.value.lunch_end);
                lunchSeconds = Math.max(0, Math.floor((lunchEnd.getTime() - lunchStart.getTime()) / 1000));
            }
        }
        
        // Add net working time (session time minus breaks and lunch)
        const netSessionSeconds = Math.max(0, sessionSeconds - breakSeconds - lunchSeconds);
        totalSeconds += netSessionSeconds;
    }
    
    // Also add any running task entries (these are separate independent timers)
    // Task entries are separate time entries with task_id, so they should be added separately
    if (taskEntries.value && taskEntries.value.length > 0) {
        taskEntries.value.forEach((t: any) => {
            if (t.clock_in && !t.clock_out) {
                const taskStart = new Date(t.clock_in);
                const now = currentTime.value;
                const taskSeconds = Math.max(0, Math.floor((now.getTime() - taskStart.getTime()) / 1000));
                totalSeconds += taskSeconds;
            }
        });
    }
    
    const hours = totalSeconds / 3600;
    return hours.toFixed(2);
});

const openDailyLogs = async () => {
    await loadTodayEntries();
    showDailyLogs.value = true;
};

const sendDailyReport = async () => {
    if (sendingReport.value) return;
    
    sendingReport.value = true;
    try {
        // Ensure we have the latest entries
        await loadTodayEntries();
        
        await api.post('/my/daily-report/send');
        alert('Daily report sent successfully to your email!');
    } catch (e: any) {
        alert(e?.response?.data?.message || 'Failed to send daily report');
    } finally {
        sendingReport.value = false;
    }
};

// Load ALL entries for today and build rows/summary (used for modal and 0/8)
const loadTodayEntries = async () => {
    try {
        const iso = new Date().toISOString().split('T')[0];
        const res = await api.get('/my/time-entries', {
            params: { start_date: iso, end_date: iso },
        });
        const list: any[] = res.data?.entries || [];
        const rows: any[] = [];
        let workSeconds = 0;
        let taskSeconds = 0;
        let totalBreakSeconds = 0;

        const now = new Date();
        list.forEach((e: any) => {
            const startStr = e.clock_in_formatted || e.clock_in;
            const endStr = e.clock_out_formatted || e.clock_out;
            const cin = parseDateTime(e.clock_in);
            const cout = parseDateTime(e.clock_out);

            // Break entries
            if (e.is_break || e.entry_type === 'break') {
                if (!cin) {
                    return;
                }

                const isClosed = Boolean(cout);
                let durationSeconds = 0;
                if (isClosed && cout) {
                    durationSeconds = Math.max(0, Math.floor((cout.getTime() - cin.getTime()) / 1000));
                    totalBreakSeconds += durationSeconds;
                } else {
                    durationSeconds = Math.max(0, Math.floor((now.getTime() - cin.getTime()) / 1000));
                }
                rows.push({
                    name: 'Break',
                    start: startStr,
                    end: isClosed ? endStr : null,
                    durationSeconds,
                    breakDurationSeconds: durationSeconds,
                    notes: isClosed ? '-' : 'In progress'
                });
                return;
            }

            if (!cin) {
                return;
            }

            const hasTask = e.task && (e.task.title || e.task.name);
            const isClosed = Boolean(cout);

            // Work hours (no task)
            if (!hasTask) {
                if (isClosed && cout) {
                    const workDur = Math.max(0, Math.floor((cout.getTime() - cin.getTime()) / 1000));
                    // subtract lunch if present
                    const ls = parseDateTime(e.lunch_start);
                    const le = parseDateTime(e.lunch_end);
                    let lunchDur = 0;
                    if (ls && le) {
                        lunchDur = Math.max(0, Math.floor((le.getTime() - ls.getTime()) / 1000));
                    }
                    const net = Math.max(0, workDur - lunchDur);
                    workSeconds += net;
                    rows.push({
                        name: 'Work Hours',
                        start: startStr,
                        end: endStr,
                        durationSeconds: net,
                        breakDurationSeconds: 0,
                        notes: '-'
                    });
                } else {
                    const runningSeconds = Math.max(0, Math.floor((now.getTime() - cin.getTime()) / 1000));
                    rows.push({
                        name: 'Work Hours',
                        start: startStr,
                        end: null,
                        durationSeconds: runningSeconds,
                        breakDurationSeconds: 0,
                        notes: 'In progress'
                    });
                }
            }

            // Task entries
            if (hasTask) {
                let taskDur = 0;
                if (isClosed && cout) {
                    taskDur = Math.max(0, Math.floor((cout.getTime() - cin.getTime()) / 1000));
                } else {
                    taskDur = Math.max(0, Math.floor((now.getTime() - cin.getTime()) / 1000));
                }
                taskSeconds += taskDur;
                rows.push({
                    name: e.task.title || e.task.name,
                    start: startStr,
                    end: isClosed ? endStr : null,
                    durationSeconds: taskDur,
                    breakDurationSeconds: 0,
                    notes: isClosed ? '-' : 'In progress'
                });
            }
        });

        rows.sort((a, b) => {
            const da = new Date(a.start).getTime();
            const db = new Date(b.start).getTime();
            return (isNaN(db) ? 0 : db) - (isNaN(da) ? 0 : da);
        });

        todayEntries.value = rows;
        todayWorkSeconds.value = workSeconds;
        todayTaskSeconds.value = taskSeconds;
    } catch (e) {
        todayEntries.value = [];
        todayWorkSeconds.value = 0;
        todayTaskSeconds.value = 0;
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
    // Optimistic update - update UI immediately
    const now = new Date().toISOString();
    if (!currentEntry.value) {
        currentEntry.value = {
            id: 0,
            clock_in: now,
            clock_out: undefined,
            break_start: undefined,
            break_end: undefined,
            lunch_start: undefined,
            lunch_end: undefined,
        };
    } else {
        currentEntry.value.clock_in = now;
        currentEntry.value.clock_out = undefined;
    }
    
    loading.value = true;
    try {
        const payload: any = {};
        // Only attach task_id when explicitly provided by the user action
        if (typeof taskId === 'number') payload.task_id = taskId;
        await api.post('/time-entries/clock-in', payload);
        // Sync with server response
        await fetchCurrentEntry();
        await fetchMyTasks();
        await loadTodayEntries();
    } catch (error: any) {
        // Rollback on error
        await fetchCurrentEntry();
        alert(error.response?.data?.message || 'Error clocking in');
    } finally {
        loading.value = false;
    }
};

const clockOut = async () => {
    if (runningTaskId.value) {
        alert('Pause or stop the running task first.');
        return;
    }
    
    // Optimistic update - update UI immediately
    if (currentEntry.value) {
        currentEntry.value.clock_out = new Date().toISOString();
    }
    
    loading.value = true;
    try {
        await api.post('/time-entries/clock-out');
        // Sync with server response
        await fetchCurrentEntry();
        await fetchMyTasks();
        await loadTodayEntries();
        
        // Check for lunch notification after time out
        setTimeout(() => {
            checkLunchNotification();
        }, 1000);
    } catch (error: any) {
        // Rollback on error
        await fetchCurrentEntry();
        alert(error.response?.data?.message || 'Error clocking out');
    } finally {
        loading.value = false;
    }
};

const startBreak = async () => {
    // Optimistic update - update UI immediately
    const now = new Date().toISOString();
    if (currentEntry.value) {
        currentEntry.value.break_start = now;
        currentEntry.value.break_end = undefined;
    }
    
    loading.value = true;
    try {
        await api.post('/time-entries/break-start');
        // Sync with server response
        await fetchCurrentEntry();
        await fetchMyTasks();
        await loadTodayEntries();
    } catch (error: any) {
        // Rollback on error
        await fetchCurrentEntry();
        alert(error.response?.data?.message || 'Error starting break');
    } finally {
        loading.value = false;
    }
};

const endBreak = async () => {
    // Optimistic update - update UI immediately
    if (currentEntry.value) {
        currentEntry.value.break_end = new Date().toISOString();
    }
    
    loading.value = true;
    try {
        await api.post('/time-entries/break-end');
        // Sync with server response
        await fetchCurrentEntry();
        await fetchMyTasks();
        await loadTodayEntries();
    } catch (error: any) {
        // Rollback on error
        await fetchCurrentEntry();
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
    
    // Optimistic update - update UI immediately
    const previousTaskId = runningTaskId.value;
    runningTaskId.value = taskId;
    
    // If another task is running, stop it
    if (previousTaskId && previousTaskId !== taskId) {
        try { await api.post('/tasks/stop'); } catch (e) {}
    }
    
    // Start the selected task WITHOUT altering the day clock-in
    try {
        await api.post('/tasks/start', { task_id: taskId });
        // Sync with server response
        await fetchTodayTaskEntries();
    } catch (e: any) {
        // Rollback on error
        runningTaskId.value = previousTaskId;
        await fetchTodayTaskEntries();
        alert(e?.response?.data?.message || 'Failed to start task');
    }
};

const pause = async () => {
    // Optimistic update - update UI immediately
    const previousTaskId = runningTaskId.value;
    runningTaskId.value = null;
    
    // Pause current task
    try {
        await api.post('/tasks/stop');
        // Sync with server response
        await fetchTodayTaskEntries();
    } catch (e) {
        // Rollback on error
        runningTaskId.value = previousTaskId;
        await fetchTodayTaskEntries();
    }
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

const completeTask = async (taskId: number) => {
    if (!confirm('Are you sure you want to complete this task?')) {
        return;
    }
    loading.value = true;
    try {
        await api.post(`/tasks/${taskId}/status`, { status: 'complete' });
        await fetchMyTasks();
    } catch (e: any) {
        alert(e?.response?.data?.message || 'Failed to complete task');
    } finally {
        loading.value = false;
    }
};

const onChangeStatus = async (taskId: number, newStatus: string) => {
    if (!newStatus) return;
    const confirmed = confirm(`Are you sure you want to set this task to "${newStatus}"?`);
    if (!confirmed) {
        // Refresh to revert the select back to the original value
        await fetchMyTasks();
        return;
    }
    loading.value = true;
    try {
        await api.post(`/tasks/${taskId}/status`, { status: newStatus });
        await fetchMyTasks();
    } catch (e: any) {
        alert(e?.response?.data?.message || 'Failed to update task status');
    } finally {
        loading.value = false;
    }
};

// Time Entries Tab Functions
const loadTimeEntries = async () => {
    if (!timeEntriesDate.value) return;
    timeEntriesLoading.value = true;
    try {
        const res = await api.get('/my/time-entries', {
            params: { start_date: timeEntriesDate.value, end_date: timeEntriesDate.value },
        });
        const list: any[] = res.data?.entries || [];
        timeEntriesData.value = list;

        // Build derived rows (Work Hours and Break) and compute totals
        timeEntriesRows.value = [];
        let rawWorkSeconds = 0;
        let totalBreakSeconds = 0;
        let firstIn: Date | null = null;
        let firstInStr: string | null = null;
        
        const now = new Date();
        list.forEach((e: any) => {
            const startStr = e.clock_in_formatted || e.clock_in;
            const endStr = e.clock_out_formatted || e.clock_out;
            const cin = parseDateTime(e.clock_in);
            const cout = parseDateTime(e.clock_out);

            if (cin && (!firstIn || cin < firstIn)) { 
                firstIn = cin; 
                firstInStr = startStr; 
            }

            // Handle break entries separately
            if (e.is_break || e.entry_type === 'break') {
                if (!cin) {
                    return;
                }

                const isClosed = Boolean(cout);
                let breakDur = 0;
                if (isClosed && cout) {
                    breakDur = Math.max(0, Math.floor((cout.getTime() - cin.getTime()) / 1000));
                    totalBreakSeconds += breakDur;
                } else {
                    breakDur = Math.max(0, Math.floor((now.getTime() - cin.getTime()) / 1000));
                }

                timeEntriesRows.value.push({
                    name: 'Break',
                    start: startStr,
                    end: isClosed ? endStr : null,
                    durationSeconds: breakDur,
                    breakDurationSeconds: breakDur,
                    notes: isClosed ? '-' : 'In progress'
                });
                return; // Skip processing as work entry
            }
            
            // This is a work entry
            if (!cin) {
                return;
            }
            
            const hasTask = e.task && (e.task.title || e.task.name);
            const isClosed = Boolean(cout);
            
            // Calculate work duration
            let workDur = 0;
            let runningSeconds = 0;
            if (isClosed && cout) {
                workDur = Math.max(0, Math.floor((cout.getTime() - cin.getTime()) / 1000));
            } else {
                runningSeconds = Math.max(0, Math.floor((now.getTime() - cin.getTime()) / 1000));
            }
            
            // Calculate lunch duration
            const ls = parseDateTime(e.lunch_start);
            const le = parseDateTime(e.lunch_end);
            let lunchDur = 0;
            if (ls && le) {
                // Lunch completed
                lunchDur = Math.max(0, Math.floor((le.getTime() - ls.getTime()) / 1000));
            } else if (ls && !le) {
                // Currently on lunch - subtract current lunch time
                lunchDur = Math.max(0, Math.floor((now.getTime() - ls.getTime()) / 1000));
            }
            
            // Calculate net work duration (work time minus lunch)
            const netWorkDur = isClosed ? Math.max(0, workDur - lunchDur) : Math.max(0, runningSeconds - lunchDur);
            
            // Create "Work Hours" entries ONLY for non-task work entries.
            // If this entry is tied to a task, we will show it only as a Task row below.
            if (!hasTask) {
                if (isClosed && cout) {
                    timeEntriesRows.value.push({
                        name: 'Work Hours',
                        start: startStr,
                        end: endStr,
                        durationSeconds: netWorkDur,
                        breakDurationSeconds: 0, // Breaks are now separate entries
                        notes: '-'
                    });
                } else {
                    timeEntriesRows.value.push({
                        name: 'Work Hours',
                        start: startStr,
                        end: null,
                        durationSeconds: netWorkDur,
                        breakDurationSeconds: 0,
                        notes: 'In progress'
                    });
                }
            }
            
            // If entry has a task, also create a separate task entry row
            // Task entries are separate from Work Hours entries
            if (hasTask) {
                const taskName = e.task.title || e.task.name;
                if (isClosed && cout) {
                    const taskDur = Math.max(0, Math.floor((cout.getTime() - cin.getTime()) / 1000));
                    timeEntriesRows.value.push({
                        name: taskName,
                        start: startStr,
                        end: endStr,
                        durationSeconds: taskDur,
                        breakDurationSeconds: 0,
                        notes: '-'
                    });
                } else {
                    timeEntriesRows.value.push({
                        name: taskName,
                        start: startStr,
                        end: null,
                        durationSeconds: runningSeconds,
                        breakDurationSeconds: 0,
                        notes: 'In progress'
                    });
                }
            }
        });
        
        // Sort by start time (most recent first)
        timeEntriesRows.value.sort((a, b) => {
            const da = new Date(a.start).getTime();
            const db = new Date(b.start).getTime();
            return (isNaN(db) ? 0 : db) - (isNaN(da) ? 0 : da);
        });

        // Calculate workSeconds as sum of all "Work Hours" entries
        const workHoursSum = timeEntriesRows.value
            .filter(row => row.name === 'Work Hours')
            .reduce((sum, row) => sum + (row.durationSeconds || 0), 0);
        
        // Calculate totalBreakSeconds as sum of all "Break" entries
        const totalBreakSecondsFromRows = timeEntriesRows.value
            .filter(row => row.name === 'Break')
            .reduce((sum, row) => sum + (row.durationSeconds || 0), 0);

        // Use API-provided daily totals (these are calculated from filtered entries based on shift)
        if (res.data?.daily_totals) {
            timeEntriesSummary.value = {
                workSeconds: res.data.daily_totals.work_seconds || 0,
                breakSeconds: res.data.daily_totals.break_seconds || 0,
                lunchSeconds: res.data.daily_totals.lunch_seconds || 0,
                tasksCount: res.data.daily_totals.tasks_count || 0,
                status: res.data.daily_totals.status || 'No Entry',
                overtimeSeconds: res.data.daily_totals.overtime_seconds || 0,
            };
        } else {
            const eight = 8 * 3600;
            const overtime = Math.max(0, workHoursSum - eight);
            let status = 'No Entry';
            if (firstIn) {
                const manilaHours = ((firstIn as Date).getUTCHours() + 8) % 24;
                const manilaMinutes = (firstIn as Date).getUTCMinutes();
                status = (manilaHours < 8 || (manilaHours === 8 && manilaMinutes <= 30)) ? 'Perfect' : 'Late';
            }
            timeEntriesSummary.value = { 
                workSeconds: workHoursSum, 
                breakSeconds: totalBreakSecondsFromRows, 
                lunchSeconds: 0, 
                tasksCount: timeEntriesRows.value.length, 
                status, 
                overtimeSeconds: overtime 
            };
        }
    } catch (e) {
        console.error('Error loading time entries', e);
        timeEntriesData.value = [];
        timeEntriesRows.value = [];
        timeEntriesSummary.value = { workSeconds: 0, breakSeconds: 0, lunchSeconds: 0, tasksCount: 0, status: 'No Entry', overtimeSeconds: 0 };
    } finally {
        timeEntriesLoading.value = false;
    }
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

const formatTimeForEntries = (time: string | null) => {
    if (!time) return '--';
    try {
        const d = new Date(time);
        if (!isNaN(d.getTime())) {
            return d.toLocaleTimeString('en-US', { 
                hour: '2-digit', 
                minute: '2-digit',
                hour12: true,
                timeZone: 'Asia/Manila' 
            });
        }
    } catch {}
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

const formatSecondsToHHMMSS = (sec: number | null | undefined) => {
    const total = typeof sec === 'number' && isFinite(sec) ? Math.max(0, Math.floor(sec)) : 0;
    const h = Math.floor(total / 3600);
    const m = Math.floor((total % 3600) / 60);
    const s = total % 60;
    const pad = (n: number) => (n < 10 ? `0${n}` : `${n}`);
    return `${pad(h)}:${pad(m)}:${pad(s)}`;
};

const formatDateLabel = (value: string | null | undefined) => {
    if (!value) return '--';
    const dt = new Date(value);
    if (isNaN(dt.getTime())) {
        return value;
    }
    return dt.toLocaleDateString('en-US', { month: 'short', day: '2-digit', year: 'numeric' });
};

// Format estimated time from milliseconds to HH:MM:SS
const formatEstimatedTime = (ms: number | null | undefined) => {
    if (!ms || ms === 0) return '--';
    const totalSeconds = Math.floor(ms / 1000);
    return formatSecondsToHHMMSS(totalSeconds);
};

// Status pill styling helper
const getStatusClasses = (status: string | null) => {
    if (!status) return '';
    const statusLower = status.toLowerCase().trim();
    
    // "to do" should not be a pill
    if (statusLower === 'to do' || statusLower === 'todo') {
        return 'text-gray-700';
    }
    
    // Color coding for different statuses
    if (statusLower.includes('complete') || statusLower.includes('done') || statusLower.includes('finished')) {
        return 'bg-green-100 text-green-800';
    }
    if (statusLower.includes('progress') || statusLower.includes('doing') || statusLower.includes('in progress') || statusLower.includes('working')) {
        return 'bg-blue-100 text-blue-800';
    }
    if (statusLower.includes('pending') || statusLower.includes('waiting')) {
        return 'bg-yellow-100 text-yellow-800';
    }
    if (statusLower.includes('blocked') || statusLower.includes('stuck')) {
        return 'bg-red-100 text-red-800';
    }
    if (statusLower.includes('review') || statusLower.includes('testing')) {
        return 'bg-purple-100 text-purple-800';
    }
    if (statusLower.includes('cancel') || statusLower.includes('closed')) {
        return 'bg-gray-100 text-gray-800';
    }
    
    // Default for other statuses
    return 'bg-indigo-100 text-indigo-800';
};

// Priority pill styling helper
const getPriorityClasses = (priority: string | null) => {
    if (!priority) return 'bg-gray-100 text-gray-800';
    const priorityLower = priority.toLowerCase().trim();
    
    if (priorityLower === 'urgent' || priorityLower === 'critical') {
        return 'bg-red-100 text-red-800';
    }
    if (priorityLower === 'high') {
        return 'bg-orange-100 text-orange-800';
    }
    if (priorityLower === 'normal' || priorityLower === 'medium') {
        return 'bg-yellow-100 text-yellow-800';
    }
    if (priorityLower === 'low') {
        return 'bg-green-100 text-green-800';
    }
    if (priorityLower === 'none' || priorityLower === '') {
        return 'bg-gray-100 text-gray-800';
    }
    
    // Default
    return 'bg-gray-100 text-gray-800';
};

// Check if status should be a pill
const isStatusPill = (status: string | null) => {
    if (!status) return false;
    const statusLower = status.toLowerCase().trim();
    return statusLower !== 'to do' && statusLower !== 'todo';
};

// Format task content for display
const formatTaskContent = (content: string | null | undefined) => {
    if (!content) return '';
    
    // First, split content into lines for better processing
    const lines = content.split('\n');
    const formattedLines: string[] = [];
    let inList = false;
    let listItems: string[] = [];
    
    for (let i = 0; i < lines.length; i++) {
        const line = lines[i].trim();
        if (!line) {
            // Empty line - close list if open, add paragraph break
            if (inList && listItems.length > 0) {
                formattedLines.push(`<ul class="list-disc ml-6 mb-3 space-y-1">${listItems.join('')}</ul>`);
                listItems = [];
                inList = false;
            }
            if (formattedLines.length > 0 && !formattedLines[formattedLines.length - 1].startsWith('<')) {
                formattedLines.push('</p><p class="mb-2">');
            }
            continue;
        }
        
        // Check for headers
        if (line.match(/^#{1,3}\s+/)) {
            if (inList && listItems.length > 0) {
                formattedLines.push(`<ul class="list-disc ml-6 mb-3 space-y-1">${listItems.join('')}</ul>`);
                listItems = [];
                inList = false;
            }
            if (line.startsWith('###')) {
                formattedLines.push(`<h3 class="font-semibold text-gray-900 mt-4 mb-2 text-base">${line.replace(/^###\s+/, '')}</h3>`);
            } else if (line.startsWith('##')) {
                formattedLines.push(`<h2 class="font-bold text-gray-900 mt-5 mb-3 text-lg">${line.replace(/^##\s+/, '')}</h2>`);
            } else if (line.startsWith('#')) {
                formattedLines.push(`<h1 class="font-bold text-gray-900 mt-6 mb-4 text-xl">${line.replace(/^#\s+/, '')}</h1>`);
            }
            continue;
        }
        
        // Check for bullet points
        if (line.match(/^[\-\*•]\s+/)) {
            if (!inList) {
                inList = true;
            }
            const itemText = line.replace(/^[\-\*•]\s+/, '').trim();
            listItems.push(`<li class="mb-1">${itemText}</li>`);
            continue;
        }
        
        // Check for numbered lists
        if (line.match(/^\d+\.\s+/)) {
            if (!inList) {
                inList = true;
            }
            const itemText = line.replace(/^\d+\.\s+/, '').trim();
            listItems.push(`<li class="mb-1 list-decimal">${itemText}</li>`);
            continue;
        }
        
        // Regular text line
        if (inList && listItems.length > 0) {
            formattedLines.push(`<ul class="list-disc ml-6 mb-3 space-y-1">${listItems.join('')}</ul>`);
            listItems = [];
            inList = false;
        }
        
        // Process inline formatting
        let processedLine = line
            .replace(/\*\*(.+?)\*\*/g, '<strong class="font-semibold">$1</strong>')
            .replace(/\*(.+?)\*/g, '<em class="italic">$1</em>');
        
        formattedLines.push(processedLine);
    }
    
    // Close any open list
    if (inList && listItems.length > 0) {
        formattedLines.push(`<ul class="list-disc ml-6 mb-3 space-y-1">${listItems.join('')}</ul>`);
    }
    
    // Join and wrap in paragraph
    let result = formattedLines.join('<br>');
    if (!result.startsWith('<')) {
        result = '<p class="mb-2">' + result;
    }
    if (!result.endsWith('>')) {
        result = result + '</p>';
    }
    
    return result;
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
            <div class="mx-auto max-w-[95%] sm:px-6 lg:px-8">
                <!-- Tab Navigation for Employees and Developers -->
                <div v-if="userRole !== 'admin'" class="border-b border-gray-200 mb-6">
                    <nav class="-mb-px flex space-x-8">
                        <button
                            @click="activeTab = 'dashboard'"
                            :class="[
                                'whitespace-nowrap border-b-2 py-4 px-1 text-sm font-medium',
                                activeTab === 'dashboard'
                                    ? 'border-indigo-500 text-indigo-600'
                                    : 'border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700'
                            ]"
                        >
                            Dashboard
                        </button>
                        <button
                            @click="activeTab = 'time-entries'; loadTimeEntries()"
                            :class="[
                                'whitespace-nowrap border-b-2 py-4 px-1 text-sm font-medium',
                                activeTab === 'time-entries'
                                    ? 'border-indigo-500 text-indigo-600'
                                    : 'border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700'
                            ]"
                        >
                            Time Entries
                        </button>
                    </nav>
                </div>

                <!-- Dashboard Tab Content -->
                <div v-if="activeTab === 'dashboard' || userRole === 'admin' || userRole === 'developer'">
                <!-- Header Cards: Work Day Timer, Running Task, Daily Logs (all roles) -->
                <div class="mb-6 grid gap-4 md:grid-cols-3">
                    <!-- Work Day Timer -->
                    <div class="overflow-hidden bg-white shadow sm:rounded-lg">
                        <div class="px-4 py-5 sm:p-6">
                            <h3 class="text-xs font-semibold uppercase tracking-wide text-gray-500">Work Day Timer</h3>
                            <p class="mt-2 text-3xl font-bold text-gray-900">{{ runningDisplay }}</p>
                            <p class="text-xs text-gray-500">{{ isClockedIn ? 'Working' : 'Idle' }}</p>
                            <div v-if="isOnBreak" class="mt-2">
                                <p class="text-sm text-gray-600">Break Timer: <span class="font-semibold">{{ breakDisplay }}</span></p>
                            </div>
                            <div class="mt-4 flex flex-wrap gap-2">
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
                                <p class="text-2xl font-bold text-gray-900 mt-1">
                                    {{ runningTaskDisplay }}<span v-if="tasks.find(t => t.id === runningTaskId)?.estimated_time" class="text-sm font-normal text-gray-500"> / {{ formatEstimatedTime(tasks.find(t => t.id === runningTaskId)?.estimated_time) }}</span>
                                </p>
                                <div class="mt-3">
                                    <button @click="pause()" :disabled="loading" class="rounded-md bg-yellow-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-yellow-700">Pause</button>
                                </div>
                            </div>
                            <div v-else class="mt-2">
                                <div class="flex items-center gap-2 w-full">
                                    <select v-model="selectedTaskId" class="w-full rounded-md border-gray-300 text-sm shadow-sm">
                                        <option :value="null">Select a task</option>
                                        <option v-for="t in tasks" :key="t.id" :value="t.id">{{ t.title }}</option>
                                    </select>
                                    <button @click="selectedTaskId ? play(selectedTaskId) : undefined" :disabled="loading || !selectedTaskId" class="rounded-md bg-green-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-green-700 disabled:opacity-50">Start</button>
                                </div>
                                <p v-if="!tasks.length" class="text-sm text-gray-500 mt-2">No tasks available.</p>
                            </div>
                        </div>
                    </div>

                    <!-- Daily Logs (summary with modal) -->
                    <div class="overflow-hidden bg-white shadow sm:rounded-lg">
                        <div class="px-4 py-5 sm:p-6">
                            <h3 class="text-xs font-semibold uppercase tracking-wide text-gray-500">Daily Logs</h3>
                            <p class="mt-2 text-3xl font-bold text-gray-900">{{ workingHoursToday }}<span class="text-sm font-normal text-gray-500"> / 8 Hours</span></p>
                            <div class="mt-4 flex space-x-2">
                                <button @click="openDailyLogs" class="rounded-md bg-indigo-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-indigo-700">Daily Logs</button>
                                <button @click="sendDailyReport" :disabled="sendingReport" class="rounded-md bg-green-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-green-700 disabled:opacity-50">
                                    {{ sendingReport ? 'Sending...' : 'Send Report' }}
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Task List with Play/Pause/Stop (all roles) -->
                <div class="mb-6 overflow-hidden bg-white shadow sm:rounded-lg">
                    <div class="px-4 py-5 sm:p-6">
                        <div class="flex items-center justify-between mb-3">
                            <h3 class="text-lg font-medium leading-6 text-gray-900">My Tasks</h3>
                            <div class="flex items-center gap-2">
                                <button
                                    @click="openSpaceSelectionModal"
                                    :disabled="taskSyncLoading"
                                    class="rounded-md bg-green-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-green-700 disabled:opacity-60 disabled:cursor-not-allowed flex items-center gap-2"
                                >
                                    <span
                                        v-if="taskSyncLoading"
                                        class="h-3 w-3 rounded-full border-2 border-white border-t-transparent animate-spin"
                                    ></span>
                                    <span>{{ taskSyncLoading ? 'Syncing…' : 'Refresh from ClickUp' }}</span>
                                </button>
                                <input v-model="taskSearch" @input="goTaskPage(1)" type="text" placeholder="Search tasks" class="rounded-md border-gray-300 text-sm shadow-sm" />
                                <select v-model="taskStatusFilter" @change="goTaskPage(1)" class="rounded-md border-gray-300 text-sm shadow-sm">
                                    <option value="all">All</option>
                                    <option v-for="s in availableStatuses" :key="s" :value="s">{{ s }}</option>
                                </select>
                                <select v-model="taskPriorityFilter" @change="goTaskPage(1)" class="rounded-md border-gray-300 text-sm shadow-sm">
                                    <option value="all">All priorities</option>
                                    <option value="urgent">Urgent</option>
                                    <option value="high">High</option>
                                    <option value="normal">Normal</option>
                                    <option value="low">Low</option>
                                    <option value="none">None</option>
                                </select>
                                <input v-model="dueStart" @change="goTaskPage(1)" type="date" class="rounded-md border-gray-300 text-sm shadow-sm" />
                                <input v-model="dueEnd" @change="goTaskPage(1)" type="date" class="rounded-md border-gray-300 text-sm shadow-sm" />
                                <select v-model.number="tasksPerPage" @change="goTaskPage(1)" class="rounded-md border-gray-300 text-sm shadow-sm">
                                    <option :value="5">5</option>
                                    <option :value="10">10</option>
                                    <option :value="20">20</option>
                                    <option :value="50">50</option>
                                </select>
                            </div>
                        </div>
                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Task</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Campaign</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Parent Task</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Status</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Due Date</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Estimated Time</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Priority</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Actions</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-200 bg-white">
                                    <tr v-for="t in paginatedTasks" :key="t.id">
                                        <td class="whitespace-nowrap px-6 py-4 text-sm text-indigo-600">
                                            <button @click="openTaskDetails(t.id)" class="hover:underline">{{ t.title }}</button>
                                        </td>
                                        <td class="whitespace-nowrap px-6 py-4 text-sm text-gray-900">{{ t.clickup_list_name || '--' }}</td>
                                        <td class="whitespace-nowrap px-6 py-4 text-sm text-gray-900">{{ t.parent_task_name || 'N/A' }}</td>
                                        <td class="whitespace-nowrap px-6 py-4 text-sm">
                                            <span 
                                                v-if="isStatusPill(t.status)"
                                                class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold"
                                                :class="getStatusClasses(t.status)"
                                            >
                                                {{ t.status || '—' }}
                                            </span>
                                            <span v-else class="text-sm text-gray-700">
                                                {{ t.status || '—' }}
                                            </span>
                                        </td>
                                        <td class="whitespace-nowrap px-6 py-4 text-sm text-gray-900">{{ t.due_date ? new Date(t.due_date).toLocaleDateString() : '--' }}</td>
                                        <td class="whitespace-nowrap px-6 py-4 text-sm text-gray-500">{{ formatEstimatedTime(t.estimated_time) }}</td>
                                        <td class="whitespace-nowrap px-6 py-4 text-sm">
                                            <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold" :class="getPriorityClasses(t.priority || null)">
                                                {{ t.priority || 'None' }}
                                            </span>
                                        </td>
                                        <td class="whitespace-nowrap px-6 py-4 text-sm text-gray-900">
                                            <div class="flex items-center space-x-2">
                                                <button @click="runningTaskId === t.id ? pause() : play(t.id)" :disabled="loading" class="rounded-full p-2 text-white" :class="runningTaskId === t.id ? 'bg-yellow-600 hover:bg-yellow-700' : 'bg-green-600 hover:bg-green-700'" :aria-label="runningTaskId === t.id ? 'Pause' : 'Play'">
                                                    <svg v-if="runningTaskId !== t.id" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="h-4 w-4"><path d="M8 5v14l11-7z"/></svg>
                                                    <svg v-else xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="h-4 w-4"><path d="M6 5h4v14H6zM14 5h4v14h-4z"/></svg>
                                                </button>
                                                <select :value="t.status || ''" @change="onChangeStatus(t.id, ($event.target as HTMLSelectElement).value)" class="rounded-md border-gray-300 text-xs shadow-sm">
                                                    <option disabled value="">Set status…</option>
                                                    <option v-for="s in availableStatuses" :key="s + t.id" :value="s">{{ s }}</option>
                                                    <option v-if="!availableStatuses.includes('complete')" value="complete">complete</option>
                                                    <option v-if="!availableStatuses.includes('done')" value="done">done</option>
                                                </select>
                                            </div>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                            <div class="flex items-center justify-between px-6 py-3 text-sm text-gray-600">
                                <div>
                                    Page {{ currentTaskPage }} of {{ totalTaskPages }} • {{ filteredTasks.length }} total
                                </div>
                                <div class="space-x-2">
                                    <button @click="goTaskPage(currentTaskPage - 1)" :disabled="currentTaskPage <= 1" class="rounded-md border px-3 py-1 disabled:opacity-50">Prev</button>
                                    <button @click="goTaskPage(currentTaskPage + 1)" :disabled="currentTaskPage >= totalTaskPages" class="rounded-md border px-3 py-1 disabled:opacity-50">Next</button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Time Events Table -->
                <div class="overflow-hidden bg-white shadow sm:rounded-lg">
                    <div class="px-4 py-5 sm:p-6">
                        <h3 class="text-lg font-medium leading-6 text-gray-900 mb-4">Today's Time Events</h3>
                        
                        <div v-if="todayTimeEntries.length === 0" class="text-center py-8 text-gray-500">
                            No time entries recorded today. Clock in to start tracking time.
                        </div>
                        
                        <div v-else class="overflow-x-auto">
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
                                    <tr v-for="(row, idx) in todayTimeEntries" :key="idx">
                                        <td class="px-4 py-4 text-sm text-gray-900">{{ row.name }}</td>
                                        <td class="px-4 py-4 text-sm text-gray-500">{{ formatTimeForEntries(row.start) }}</td>
                                        <td class="px-4 py-4 text-sm text-gray-500">{{ formatTimeForEntries(row.end) }}</td>
                                        <td class="px-4 py-4 text-sm text-gray-900 font-semibold">{{ formatSecondsToHHMMSS(row.durationSeconds) }}</td>
                                        <td class="px-4 py-4 text-sm text-gray-500">{{ formatSecondsToHHMMSS(row.breakDurationSeconds || 0) }}</td>
                                        <td class="px-4 py-4 text-sm text-gray-500">{{ row.notes || '-' }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                </div>
                <!-- End Dashboard Tab Content -->

                <!-- Time Entries Tab Content -->
                <div v-if="activeTab === 'time-entries' && userRole !== 'admin'" class="space-y-6">
                    <!-- Filters -->
                    <div class="bg-white shadow rounded-lg p-4">
                        <div class="grid gap-4 sm:grid-cols-2">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Date</label>
                                <input 
                                    v-model="timeEntriesDate" 
                                    @change="loadTimeEntries" 
                                    type="date" 
                                    class="w-full rounded-md border-gray-300 shadow-sm" 
                                />
                            </div>
                        </div>
                    </div>

                    <!-- Daily Summary -->
                    <div class="bg-white shadow rounded-lg p-5">
                        <h3 class="text-lg font-medium leading-6 text-gray-900 mb-4">Daily Summary</h3>
                        <div class="grid gap-4 sm:grid-cols-3 lg:grid-cols-5 text-sm">
                            <div>
                                <div class="text-gray-500">🕒 Time In Hours</div>
                                <div class="font-semibold">{{ formatSecondsToHHMMSS(timeEntriesSummary.workSeconds) }}</div>
                            </div>
                            <div>
                                <div class="text-gray-500">☕ Total Breaks</div>
                                <div class="font-semibold">{{ formatSecondsToHHMMSS(timeEntriesSummary.breakSeconds) }}</div>
                            </div>
                            <div>
                                <div class="text-gray-500">📋 Entries</div>
                                <div class="font-semibold">{{ timeEntriesSummary.tasksCount }}</div>
                            </div>
                            <div>
                                <div class="text-gray-500">⏰ Status</div>
                                <div 
                                    class="font-semibold"
                                    :class="{ 
                                        'text-green-700': timeEntriesSummary.status === 'Perfect', 
                                        'text-yellow-700': timeEntriesSummary.status === 'Late', 
                                        'text-gray-700': timeEntriesSummary.status === 'No Entry' 
                                    }"
                                >
                                    {{ timeEntriesSummary.status }}
                                </div>
                            </div>
                            <div>
                                <div class="text-gray-500">⚡ Overtime</div>
                                <div class="font-semibold">{{ formatSecondsToHHMMSS(timeEntriesSummary.overtimeSeconds) }}</div>
                            </div>
                        </div>
                    </div>

                    <!-- Sessions Table -->
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
                                    <tr v-for="(row, idx) in timeEntriesRows" :key="idx">
                                        <td class="px-4 py-4 text-sm text-gray-900">{{ row.name }}</td>
                                        <td class="px-4 py-4 text-sm text-gray-500">{{ formatTimeForEntries(row.start) }}</td>
                                        <td class="px-4 py-4 text-sm text-gray-500">{{ formatTimeForEntries(row.end) }}</td>
                                        <td class="px-4 py-4 text-sm text-gray-900 font-semibold">{{ formatSecondsToHHMMSS(row.durationSeconds) }}</td>
                                        <td class="px-4 py-4 text-sm text-gray-500">{{ formatSecondsToHHMMSS(row.breakDurationSeconds || 0) }}</td>
                                        <td class="px-4 py-4 text-sm text-gray-500">{{ row.notes || '-' }}</td>
                                    </tr>
                                    <tr v-if="timeEntriesRows.length === 0 && !timeEntriesLoading">
                                        <td colspan="6" class="px-4 py-4 text-center text-sm text-gray-500">No entries for selected day</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <div v-if="timeEntriesLoading" class="text-center py-8">
                            <div class="inline-block animate-spin rounded-full h-8 w-8 border-b-2 border-indigo-600"></div>
                            <p class="mt-2 text-sm text-gray-500">Loading...</p>
                        </div>
                    </div>
                </div>
                <!-- End Time Entries Tab Content -->

            </div>
            <!-- Daily Logs Modal -->
            <div v-if="showDailyLogs" class="fixed inset-0 z-50 flex items-center justify-center bg-black bg-opacity-50">
                <div class="w-full max-w-4xl rounded-lg bg-white shadow-lg flex flex-col max-h-[90vh]">
                    <div class="flex items-center justify-between border-b px-4 py-3 flex-shrink-0">
                        <h4 class="text-md font-semibold">Daily Logs</h4>
                        <button @click="showDailyLogs = false" class="rounded-md bg-gray-100 px-2 py-1 text-xs hover:bg-gray-200">Close</button>
                    </div>
                    <div class="p-4 flex flex-col flex-1 min-h-0">
                        <div v-if="todayTimeEntries.length === 0" class="text-gray-500 text-sm">No entry today.</div>
                        <div v-else class="flex flex-col flex-1 min-h-0">
                            <!-- Summary Section -->
                            <div class="mb-4 flex gap-6 flex-shrink-0">
                                <div class="flex-1 bg-gray-50 rounded-lg p-4">
                                    <div class="text-xs font-medium text-gray-500 uppercase tracking-wide">Total Work Hours</div>
                                    <div class="text-2xl font-bold text-gray-900 mt-1">{{ formatSecondsToHHMMSS(todayWorkSeconds) }}</div>
                                </div>
                                <div class="flex-1 bg-gray-50 rounded-lg p-4">
                                    <div class="text-xs font-medium text-gray-500 uppercase tracking-wide">Total Task Hours</div>
                                    <div class="text-2xl font-bold text-gray-900 mt-1">{{ formatSecondsToHHMMSS(todayTaskSeconds) }}</div>
                                </div>
                            </div>
                            <!-- Table Section with Scroll -->
                            <div class="flex-1 overflow-y-auto border border-gray-200 rounded-lg">
                                <table class="min-w-full divide-y divide-gray-200">
                                    <thead class="bg-gray-50 sticky top-0">
                                        <tr>
                                            <th class="px-4 py-2 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Task</th>
                                            <th class="px-4 py-2 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Start Time</th>
                                            <th class="px-4 py-2 text-left text-xs font-medium uppercase tracking-wider text-gray-500">End Time</th>
                                            <th class="px-4 py-2 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Duration</th>
                                            <th class="px-4 py-2 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Break Duration</th>
                                            <th class="px-4 py-2 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Notes</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-gray-200 bg-white">
                                        <tr v-for="(row, idx) in todayTimeEntries" :key="idx">
                                            <td class="px-4 py-2 text-sm text-gray-900">{{ row.name }}</td>
                                            <td class="px-4 py-2 text-sm text-gray-700">{{ formatTimeForEntries(row.start) }}</td>
                                            <td class="px-4 py-2 text-sm text-gray-700">{{ formatTimeForEntries(row.end) }}</td>
                                            <td class="px-4 py-2 text-sm text-gray-700 font-semibold">{{ formatSecondsToHHMMSS(row.durationSeconds) }}</td>
                                            <td class="px-4 py-2 text-sm text-gray-700">{{ formatSecondsToHHMMSS(row.breakDurationSeconds || 0) }}</td>
                                            <td class="px-4 py-2 text-sm text-gray-700">{{ row.notes || '-' }}</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Sequential ClickUp Space Sync Modal -->
            <div v-if="showSpaceSelectionModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black bg-opacity-50">
                <div class="w-full max-w-lg rounded-lg bg-white shadow-xl">
                    <div class="flex items-center justify-between border-b px-5 py-3">
                        <h4 class="text-md font-semibold text-gray-900">Sync ClickUp Spaces</h4>
                        <button @click="cancelSequentialSync" :disabled="sequentialSyncActive && spaceSyncProgress?.status === 'syncing'" class="rounded-md bg-gray-100 px-2 py-1 text-xs hover:bg-gray-200 disabled:opacity-50">Cancel</button>
                    </div>
                    <div class="p-5 space-y-4 text-sm text-gray-700">
                        <div v-if="spaceSyncProgress">
                            <!-- Progress Header -->
                            <div class="mb-4">
                                <div class="flex items-center justify-between mb-2">
                                    <span class="text-xs font-medium text-gray-600">
                                        Space {{ spaceSyncProgress.current }} of {{ spaceSyncProgress.total }}
                                    </span>
                                    <span class="text-xs text-gray-500">
                                        {{ Math.round((spaceSyncProgress.current / spaceSyncProgress.total) * 100) }}%
                                    </span>
                                </div>
                                <div class="w-full bg-gray-200 rounded-full h-2">
                                    <div
                                        class="bg-indigo-600 h-2 rounded-full transition-all duration-300"
                                        :style="{ width: `${(spaceSyncProgress.current / spaceSyncProgress.total) * 100}%` }"
                                    ></div>
                                </div>
                            </div>

                            <!-- Current Space Info -->
                            <div class="rounded-md border p-4 space-y-3" :class="{
                                'border-blue-200 bg-blue-50': spaceSyncProgress.status === 'checking',
                                'border-green-200 bg-green-50': spaceSyncProgress.status === 'completed',
                                'border-yellow-200 bg-yellow-50': spaceSyncProgress.status === 'skipped',
                                'border-red-200 bg-red-50': spaceSyncProgress.status === 'error',
                                'border-indigo-200 bg-indigo-50': spaceSyncProgress.status === 'syncing',
                            }">
                                <div class="flex items-center gap-2">
                                    <span
                                        v-if="spaceSyncProgress.status === 'checking' || spaceSyncProgress.status === 'syncing'"
                                        class="h-4 w-4 rounded-full border-2 border-current border-t-transparent animate-spin"
                                    ></span>
                                    <span
                                        v-else-if="spaceSyncProgress.status === 'completed'"
                                        class="text-green-600 text-lg"
                                    >✓</span>
                                    <span
                                        v-else-if="spaceSyncProgress.status === 'skipped'"
                                        class="text-yellow-600 text-lg"
                                    >⊘</span>
                                    <span
                                        v-else-if="spaceSyncProgress.status === 'error'"
                                        class="text-red-600 text-lg"
                                    >✗</span>
                                    <h5 class="font-semibold text-gray-900">{{ spaceSyncProgress.spaceName }}</h5>
                                </div>

                                <!-- Status Messages -->
                                <div v-if="spaceSyncProgress.status === 'checking' && currentSpaceInfo?.is_member === true" class="space-y-3">
                                    <p class="text-sm text-gray-700">You are a member of this space. Would you like to sync tasks from it?</p>
                                    <div class="flex gap-2">
                                        <button
                                            @click="approveSpaceSync"
                                            class="flex-1 rounded-md bg-indigo-600 px-3 py-2 text-xs font-semibold text-white hover:bg-indigo-700"
                                        >
                                            Yes, Sync This Space
                                        </button>
                                        <button
                                            @click="skipSpaceSync"
                                            class="flex-1 rounded-md bg-gray-200 px-3 py-2 text-xs font-medium text-gray-700 hover:bg-gray-300"
                                        >
                                            Skip
                                        </button>
                                    </div>
                                </div>

                                <div v-else-if="spaceSyncProgress.status === 'checking' && currentSpaceInfo?.is_member === false" class="text-sm text-gray-600">
                                    You are not a member of this space. Skipping...
                                </div>

                                <div v-else-if="spaceSyncProgress.status === 'syncing'" class="text-sm text-gray-600">
                                    Syncing tasks from this space...
                                </div>

                                <div v-else-if="spaceSyncProgress.status === 'completed' && spaceSyncProgress.result" class="text-sm space-y-1">
                                    <div class="font-medium text-green-700">Sync completed!</div>
                                    <div class="text-xs text-gray-600">
                                        Created: {{ spaceSyncProgress.result.summary?.created || 0 }},
                                        Updated: {{ spaceSyncProgress.result.summary?.updated || 0 }},
                                        Unchanged: {{ spaceSyncProgress.result.summary?.unchanged || 0 }}
                                    </div>
                                </div>

                                <div v-else-if="spaceSyncProgress.status === 'skipped'" class="text-sm text-yellow-700">
                                    This space was skipped.
                                </div>

                                <div v-else-if="spaceSyncProgress.status === 'error'" class="text-sm text-red-700">
                                    An error occurred while processing this space.
                                </div>
                            </div>
                        </div>

                        <!-- Completed Spaces Summary -->
                        <div v-if="spaceSyncResults.length > 0" class="mt-4">
                            <h6 class="text-xs font-medium text-gray-600 mb-2">Completed Spaces:</h6>
                            <div class="max-h-32 overflow-y-auto space-y-1 text-xs">
                                <div
                                    v-for="(result, idx) in spaceSyncResults"
                                    :key="idx"
                                    class="flex items-center justify-between p-2 rounded bg-gray-50"
                                >
                                    <span class="text-gray-700">{{ result.name }}</span>
                                    <span
                                        class="px-2 py-0.5 rounded text-xs font-medium"
                                        :class="{
                                            'bg-green-100 text-green-700': result.result?.ok,
                                            'bg-yellow-100 text-yellow-700': result.result?.skipped,
                                            'bg-red-100 text-red-700': !result.result?.ok && !result.result?.skipped,
                                        }"
                                    >
                                        {{ result.result?.ok ? 'Synced' : result.result?.skipped ? 'Skipped' : 'Error' }}
                                    </span>
                                </div>
                            </div>
                        </div>

                        <!-- Loading Initial State -->
                        <div v-if="!spaceSyncProgress && sequentialSyncActive" class="flex items-center gap-2 text-sm text-gray-500">
                            <span class="h-3 w-3 rounded-full border-2 border-gray-300 border-t-indigo-600 animate-spin"></span>
                            <span>Initializing space sync...</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Task Sync Summary Modal -->
            <div v-if="showTaskSyncModal && taskSyncResult" class="fixed inset-0 z-50 flex items-center justify-center bg-black bg-opacity-50">
                <div class="w-full max-w-3xl rounded-lg bg-white shadow-lg">
                    <div class="flex items-center justify-between border-b px-4 py-3">
                        <h4 class="text-md font-semibold">ClickUp Sync Summary</h4>
                        <button @click="resetTaskSyncModal" class="rounded-md bg-gray-100 px-2 py-1 text-xs hover:bg-gray-200">Close</button>
                    </div>
                    <div class="p-5 text-sm text-gray-700 space-y-5">
                        <div class="space-y-1">
                            <p>Latest sync completed successfully.</p>
                            <p v-if="taskSyncResult.sources.length" class="text-xs text-gray-500">
                                Sources:
                                <span class="font-medium text-gray-600">{{ taskSyncResult.sources.join(', ') }}</span>
                            </p>
                        </div>
                        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
                            <div class="rounded-md bg-indigo-50 px-4 py-3">
                                <div class="text-xs uppercase tracking-wide text-indigo-600">Total Processed</div>
                                <div class="text-xl font-semibold text-indigo-900">{{ taskSyncResult.summary.total }}</div>
                            </div>
                            <div class="rounded-md bg-green-50 px-4 py-3">
                                <div class="text-xs uppercase tracking-wide text-green-600">Created</div>
                                <div class="text-xl font-semibold text-green-900">{{ taskSyncResult.summary.created }}</div>
                            </div>
                            <div class="rounded-md bg-blue-50 px-4 py-3">
                                <div class="text-xs uppercase tracking-wide text-blue-600">Updated</div>
                                <div class="text-xl font-semibold text-blue-900">{{ taskSyncResult.summary.updated }}</div>
                            </div>
                            <div class="rounded-md bg-gray-100 px-4 py-3">
                                <div class="text-xs uppercase tracking-wide text-gray-600">No Changes</div>
                                <div class="text-xl font-semibold text-gray-900">{{ taskSyncResult.summary.unchanged }}</div>
                            </div>
                            <div class="rounded-md bg-amber-50 px-4 py-3">
                                <div class="text-xs uppercase tracking-wide text-amber-600">Skipped</div>
                                <div class="text-xl font-semibold text-amber-900">{{ taskSyncResult.summary.skipped }}</div>
                            </div>
                        </div>

                        <div v-if="taskSyncResult.created.length" class="space-y-2">
                            <h5 class="text-sm font-semibold text-gray-900">New tasks</h5>
                            <ul class="max-h-48 overflow-y-auto space-y-2 pr-1">
                                <li
                                    v-for="item in taskSyncResult.created"
                                    :key="'created-' + item.id"
                                    class="rounded-md border border-green-200 bg-green-50 px-3 py-2"
                                >
                                    <div class="flex items-start justify-between gap-3">
                                        <span class="font-medium text-green-900 leading-tight">{{ item.title }}</span>
                                        <span class="text-xs text-green-700 whitespace-nowrap">{{ formatDateLabel(item.due_date) }}</span>
                                    </div>
                                    <div class="mt-1 flex flex-wrap gap-3 text-xs text-green-800">
                                        <span v-if="item.status">Status: {{ item.status }}</span>
                                        <span v-if="item.priority">Priority: {{ item.priority }}</span>
                                    </div>
                                </li>
                            </ul>
                        </div>

                        <div v-if="taskSyncResult.updated.length" class="space-y-2">
                            <h5 class="text-sm font-semibold text-gray-900">Updated tasks</h5>
                            <ul class="max-h-48 overflow-y-auto space-y-2 pr-1">
                                <li
                                    v-for="item in taskSyncResult.updated"
                                    :key="'updated-' + item.id"
                                    class="rounded-md border border-blue-200 bg-blue-50 px-3 py-2"
                                >
                                    <div class="flex items-start justify-between gap-3">
                                        <span class="font-medium text-blue-900 leading-tight">{{ item.title }}</span>
                                        <span class="text-xs text-blue-700 whitespace-nowrap">{{ formatDateLabel(item.due_date) }}</span>
                                    </div>
                                    <div class="mt-1 flex flex-wrap gap-3 text-xs text-blue-800">
                                        <span v-if="item.status">Status: {{ item.status }}</span>
                                        <span v-if="item.priority">Priority: {{ item.priority }}</span>
                                    </div>
                                </li>
                            </ul>
                        </div>

                        <div v-if="taskSyncResult.skipped.length" class="space-y-2">
                            <h5 class="text-sm font-semibold text-gray-900">Skipped items</h5>
                            <ul class="max-h-40 overflow-y-auto space-y-1 pr-1">
                                <li
                                    v-for="item in taskSyncResult.skipped"
                                    :key="'skipped-' + item.id"
                                    class="rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-900"
                                >
                                    <div class="flex items-start justify-between gap-3">
                                        <span class="font-medium leading-tight">{{ item.id }}</span>
                                        <span class="text-amber-700">{{ item.reason }}</span>
                                    </div>
                                </li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Task Details Modal -->
            <div v-if="showTaskModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black bg-opacity-50">
                <div class="w-full max-w-3xl max-h-[90vh] rounded-lg bg-white shadow-lg flex flex-col">
                    <div class="flex items-center justify-between border-b px-4 py-3 flex-shrink-0">
                        <h4 class="text-md font-semibold">Task Details</h4>
                        <button @click="showTaskModal = false" class="rounded-md bg-gray-100 px-2 py-1 text-xs hover:bg-gray-200">Close</button>
                    </div>
                    <div class="p-6 text-sm text-gray-700 overflow-y-auto flex-1" v-if="taskDetails">
                        <div class="mb-4 pb-4 border-b">
                            <h2 class="font-semibold text-lg text-gray-900 mb-2">{{ taskDetails.task?.title }}</h2>
                            <div class="flex items-center gap-3 flex-wrap">
                                <span v-if="taskDetails.task?.status" class="text-sm">
                                    <span class="text-gray-600">Status:</span>
                                    <span v-if="isStatusPill(taskDetails.task.status)" 
                                          class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold ml-1"
                                          :class="getStatusClasses(taskDetails.task.status)">
                                        {{ taskDetails.task.status }}
                                    </span>
                                    <span v-else class="ml-1 text-gray-700">{{ taskDetails.task.status }}</span>
                                </span>
                                <span v-if="taskDetails.clickup?.url">
                                    <a :href="taskDetails.clickup.url" target="_blank" class="text-indigo-600 hover:underline text-sm font-medium">Open in ClickUp →</a>
                                </span>
                            </div>
                        </div>
                        <div v-if="taskDetails.clickup?.text_content" class="task-content">
                            <div v-html="formatTaskContent(taskDetails.clickup.text_content)" class="prose prose-sm max-w-none"></div>
                        </div>
                        <div v-else class="text-gray-500 italic">
                            No additional details available.
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
