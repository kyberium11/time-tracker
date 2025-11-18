<script setup lang="ts">
import { ref, onMounted } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head } from '@inertiajs/vue3';
import api from '@/api';

interface ShiftScheduleEntry {
    day_of_week: number;
    start_time: string;
    end_time: string;
}

interface ShiftScheduleFormEntry extends ShiftScheduleEntry {
    enabled: boolean;
}

interface User {
    id: number;
    name: string;
    email: string;
    role: string;
    clickup_user_id?: string | null;
    team?: {
        id: number;
        name: string;
    } | null;
    shift_start: string | null;
    shift_end: string | null;
    shift_schedule?: ShiftScheduleEntry[];
}

const users = ref<User[]>([]);
const loading = ref(false);
const showModal = ref(false);
const editingUser = ref<User | null>(null);
const formData = ref({
    name: '',
    email: '',
    password: '',
    password_confirmation: '',
    role: 'employee',
    team_id: null as number | null,
    shift_start: '',
    shift_end: '',
    clickup_user_id: '' as string | null | '',
});

const dayOptions = [
    { label: 'Sunday', value: 0 },
    { label: 'Monday', value: 1 },
    { label: 'Tuesday', value: 2 },
    { label: 'Wednesday', value: 3 },
    { label: 'Thursday', value: 4 },
    { label: 'Friday', value: 5 },
    { label: 'Saturday', value: 6 },
];

const createDefaultShiftSchedule = (): ShiftScheduleFormEntry[] =>
    dayOptions.map((day) => ({
        day_of_week: day.value,
        start_time: '',
        end_time: '',
        enabled: false,
    }));

const shiftScheduleForm = ref<ShiftScheduleFormEntry[]>(createDefaultShiftSchedule());

const teams = ref<Array<{ id: number; name: string }>>([]);

const fetchTeams = async () => {
    try {
        const response = await api.get('/admin/teams');
        teams.value = response.data;
    } catch (error) {
        console.error('Error fetching teams:', error);
    }
};

const fetchUsers = async () => {
    loading.value = true;
    try {
        const response = await api.get('/admin/users');
        users.value = response.data.data;
    } catch (error) {
        console.error('Error fetching users:', error);
        alert('Failed to fetch users');
    } finally {
        loading.value = false;
    }
};

const openEditModal = (user?: User) => {
    shiftScheduleForm.value = createDefaultShiftSchedule();

    if (user) {
        editingUser.value = user;
        formData.value = {
            name: user.name,
            email: user.email,
            password: '',
            password_confirmation: '',
            role: user.role,
            team_id: user.team?.id || null,
            shift_start: user.shift_start || '',
            shift_end: user.shift_end || '',
            clickup_user_id: user.clickup_user_id || '',
        };

        if (user.shift_schedule?.length) {
            user.shift_schedule.forEach((entry) => {
                const target = shiftScheduleForm.value.find((d) => d.day_of_week === entry.day_of_week);
                if (target) {
                    target.enabled = true;
                    target.start_time = entry.start_time;
                    target.end_time = entry.end_time;
                }
            });
        }
    } else {
        editingUser.value = null;
        formData.value = {
            name: '',
            email: '',
            password: '',
            password_confirmation: '',
            role: 'employee',
            team_id: null,
            shift_start: '',
            shift_end: '',
            clickup_user_id: '',
        };
    }
    showModal.value = true;
};

const closeModal = () => {
    showModal.value = false;
    editingUser.value = null;
    shiftScheduleForm.value = createDefaultShiftSchedule();
};

const buildShiftSchedulePayload = () =>
    shiftScheduleForm.value
        .filter((entry) => entry.enabled && entry.start_time && entry.end_time)
        .map((entry) => ({
            day_of_week: entry.day_of_week,
            start_time: entry.start_time,
            end_time: entry.end_time,
        }));

const saveUser = async () => {
    loading.value = true;
    try {
        const payload = {
            ...formData.value,
            shift_schedule: buildShiftSchedulePayload(),
        };

        if (editingUser.value) {
            await api.put(`/admin/users/${editingUser.value.id}`, payload);
        } else {
            await api.post('/admin/users', payload);
        }
        await fetchUsers();
        closeModal();
        formData.value = { name: '', email: '', password: '', password_confirmation: '', role: 'employee', team_id: null, shift_start: '', shift_end: '', clickup_user_id: '' };
    } catch (error: any) {
        alert(error.response?.data?.message || 'Error saving user');
    } finally {
        loading.value = false;
    }
};

const deleteUser = async (userId: number) => {
    if (!confirm('Are you sure you want to delete this user?')) return;
    
    try {
        await api.delete(`/admin/users/${userId}`);
        await fetchUsers();
    } catch (error: any) {
        alert(error.response?.data?.message || 'Error deleting user');
    }
};

const changeRole = async (userId: number, newRole: string) => {
    if (!confirm(`Are you sure you want to change this user's role to ${newRole}?`)) return;
    
    loading.value = true;
    try {
        await api.put(`/admin/users/${userId}`, { role: newRole });
        await fetchUsers();
    } catch (error: any) {
        alert(error.response?.data?.message || 'Error updating role');
    } finally {
        loading.value = false;
    }
};

const refreshUserTasks = async (userId: number) => {
    try {
        const res = await api.post(`/admin/users/${userId}/clickup/sync-tasks`);
        const count = res.data?.count ?? 0;
        alert(`Synced ${count} task(s) from ClickUp.`);
    } catch (error: any) {
        alert(error?.response?.data?.error || 'Failed to sync tasks');
    }
};

onMounted(() => {
    fetchUsers();
    fetchTeams();
});

const dayLabel = (value: number) => dayOptions.find((d) => d.value === value)?.label ?? 'Unknown';
</script>

<template>
    <Head title="User Management" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <h2 class="text-xl font-semibold leading-tight text-gray-800">
                    User Management
                </h2>
                <button
                    @click="openEditModal()"
                    class="rounded-md bg-indigo-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500"
                >
                    Add User
                </button>
            </div>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-7xl sm:px-6 lg:px-8">
                <div class="overflow-hidden bg-white shadow sm:rounded-lg">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">
                                    Name
                                </th>
                                <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">
                                    Email
                                </th>
                                <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">
                                    Role
                                </th>
                                <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">
                                    ClickUp User ID
                                </th>
                                <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">
                                    Team
                                </th>
                                <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">
                                    Shift Time
                                </th>
                                <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">
                                    Actions
                                </th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 bg-white">
                            <tr v-for="user in users" :key="user.id">
                                <td class="whitespace-nowrap px-6 py-4 text-sm font-medium text-gray-900">
                                    {{ user.name }}
                                </td>
                                <td class="whitespace-nowrap px-6 py-4 text-sm text-gray-500">
                                    {{ user.email }}
                                </td>
                                <td class="whitespace-nowrap px-6 py-4 text-sm text-gray-500">
                                    <select
                                        :value="user.role"
                                        @change="changeRole(user.id, ($event.target as HTMLSelectElement).value)"
                                        :class="[
                                            'inline-flex rounded-full px-3 py-1 text-xs font-semibold leading-5 border-0 cursor-pointer',
                                            user.role === 'admin' ? 'bg-purple-600 text-white hover:bg-purple-700' :
                                            user.role === 'manager' ? 'bg-blue-600 text-white hover:bg-blue-700' :
                                            'bg-green-600 text-white hover:bg-green-700'
                                        ]"
                                    >
                                        <option value="admin" :selected="user.role === 'admin'">Admin</option>
                                        <option value="manager" :selected="user.role === 'manager'">Manager</option>
                                        <option value="employee" :selected="user.role === 'employee'">Employee</option>
                                    </select>
                                </td>
                                <td class="whitespace-nowrap px-6 py-4 text-sm text-gray-500">
                                    {{ user.clickup_user_id || '—' }}
                                </td>
                                <td class="whitespace-nowrap px-6 py-4 text-sm text-gray-500">
                                    {{ user.team?.name || 'No team' }}
                                </td>
                                <td class="whitespace-nowrap px-6 py-4 text-sm text-gray-500">
                                    <div v-if="user.shift_schedule?.length" class="space-y-1 text-xs text-gray-700">
                                        <div
                                            v-for="entry in user.shift_schedule"
                                            :key="`${user.id}-${entry.day_of_week}`"
                                        >
                                            {{ dayLabel(entry.day_of_week) }}:
                                            {{ entry.start_time }} - {{ entry.end_time }}
                                        </div>
                                    </div>
                                    <div v-else class="text-xs text-gray-400 italic">
                                        <span v-if="user.shift_start && user.shift_end">
                                            {{ user.shift_start }} - {{ user.shift_end }}
                                        </span>
                                        <span v-else>Not set</span>
                                    </div>
                                </td>
                                <td class="whitespace-nowrap px-6 py-4 text-sm font-medium">
                                    <button
                                        @click="openEditModal(user)"
                                        class="rounded-md bg-indigo-600 px-3 py-1.5 text-xs font-semibold text-white shadow-sm hover:bg-indigo-500"
                                    >
                                        Edit
                                    </button>
                                    <button
                                        @click="refreshUserTasks(user.id)"
                                        class="ml-2 rounded-md bg-green-600 px-3 py-1.5 text-xs font-semibold text-white shadow-sm hover:bg-green-500"
                                    >
                                        Refresh Tasks
                                    </button>
                                    <button
                                        @click="deleteUser(user.id)"
                                        class="ml-2 rounded-md bg-red-600 px-3 py-1.5 text-xs font-semibold text-white shadow-sm hover:bg-red-500"
                                    >
                                        Delete
                                    </button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Modal -->
        <div v-if="showModal" class="fixed inset-0 z-50 overflow-y-auto" aria-labelledby="modal-title" role="dialog" aria-modal="true">
            <div class="flex min-h-screen items-end justify-center px-4 pt-4 pb-20 text-center sm:block sm:p-0">
                <div class="fixed inset-0 transition-opacity bg-gray-500 bg-opacity-75" aria-hidden="true" @click="closeModal"></div>
                
                <div class="relative inline-block transform overflow-hidden rounded-lg bg-white text-left align-bottom shadow-xl transition-all sm:my-8 sm:w-full sm:max-w-lg">
                    <div class="bg-white px-4 pt-5 pb-4 sm:p-6 sm:pb-4">
                        <h3 class="text-lg font-medium leading-6 text-gray-900 mb-4">
                            {{ editingUser ? 'Edit User' : 'Add User' }}
                        </h3>
                        
                        <form @submit.prevent="saveUser">
                            <div class="mb-4">
                                <label class="block text-sm font-medium text-gray-700">Name</label>
                                <input
                                    v-model="formData.name"
                                    type="text"
                                    required
                                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm"
                                />
                            </div>
                            
                            <div class="mb-4">
                                <label class="block text-sm font-medium text-gray-700">Email</label>
                                <input
                                    v-model="formData.email"
                                    type="email"
                                    required
                                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm"
                                />
                            </div>
                            
                            <div class="mb-4">
                                <label class="block text-sm font-medium text-gray-700">
                                    {{ editingUser ? 'New Password (leave blank to keep current)' : 'Password' }}
                                </label>
                                <input
                                    v-model="formData.password"
                                    type="password"
                                    :required="!editingUser"
                                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm"
                                />
                            </div>
                            
                            <div class="mb-4">
                                <label class="block text-sm font-medium text-gray-700">Confirm Password</label>
                                <input
                                    v-model="formData.password_confirmation"
                                    type="password"
                                    :required="!editingUser"
                                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm"
                                />
                            </div>
                            
                            <div class="mb-4">
                                <label class="block text-sm font-medium text-gray-700">Role</label>
                                <select
                                    v-model="formData.role"
                                    required
                                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm"
                                >
                                    <option value="admin">Admin</option>
                                    <option value="manager">Manager</option>
                                    <option value="employee">Employee</option>
                                </select>
                            </div>
                            
                            <div class="mb-4">
                                <label class="block text-sm font-medium text-gray-700">Team</label>
                                <select
                                    v-model="formData.team_id"
                                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm"
                                >
                                    <option :value="null">No team</option>
                                    <option v-for="team in teams" :key="team.id" :value="team.id">
                                        {{ team.name }}
                                    </option>
                                </select>
                            </div>
                            
                            <div class="mb-4 grid grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700">Shift Start</label>
                                    <input
                                        v-model="formData.shift_start"
                                        type="time"
                                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm"
                                    />
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700">Shift End</label>
                                    <input
                                        v-model="formData.shift_end"
                                        type="time"
                                        class="mt-1 block w-full rounded-md border-gray-300 shadow-sm"
                                    />
                                </div>
                            </div>

                            <div class="mb-6">
                                <div class="flex items-center justify-between">
                                    <div>
                                        <p class="text-sm font-medium text-gray-700">Weekly Shift Schedule</p>
                                        <p class="text-xs text-gray-500">
                                            Configure day-specific hours. Unchecked days are treated as off.
                                        </p>
                                    </div>
                                    <button
                                        type="button"
                                        class="text-xs text-indigo-600 hover:text-indigo-800"
                                        @click="shiftScheduleForm = createDefaultShiftSchedule()"
                                    >
                                        Clear schedule
                                    </button>
                                </div>
                                <div class="mt-3 divide-y divide-gray-100 rounded-md border border-gray-200">
                                    <div
                                        v-for="day in shiftScheduleForm"
                                        :key="day.day_of_week"
                                        class="flex flex-wrap items-center gap-4 px-4 py-3"
                                    >
                                        <div class="w-28 text-sm font-medium text-gray-800">
                                            {{ dayLabel(day.day_of_week) }}
                                        </div>
                                        <label class="flex items-center gap-2 text-sm text-gray-700">
                                            <input
                                                type="checkbox"
                                                v-model="day.enabled"
                                                class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500"
                                            />
                                            Enable
                                        </label>
                                        <div class="flex items-center gap-2">
                                            <input
                                                type="time"
                                                v-model="day.start_time"
                                                :disabled="!day.enabled"
                                                class="block rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm disabled:bg-gray-100"
                                            />
                                            <span class="text-sm text-gray-500">to</span>
                                            <input
                                                type="time"
                                                v-model="day.end_time"
                                                :disabled="!day.enabled"
                                                class="block rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm disabled:bg-gray-100"
                                            />
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="mb-4">
                                <label class="block text-sm font-medium text-gray-700">ClickUp User ID</label>
                                <input
                                    v-model="formData.clickup_user_id"
                                    type="text"
                                    placeholder="e.g. 12345678"
                                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm"
                                />
                                <p class="mt-1 text-xs text-gray-500">Use the member's numeric id from ClickUp.</p>
                            </div>
                            
                            <div class="flex justify-end space-x-3">
                                <button
                                    type="button"
                                    @click="closeModal"
                                    class="rounded-md border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50"
                                >
                                    Cancel
                                </button>
                                <button
                                    type="submit"
                                    class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700"
                                >
                                    Save
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>

