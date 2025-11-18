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

interface Team {
    id: number;
    name: string;
    description: string | null;
    manager_id: number | null;
    manager?: {
        id: number;
        name: string;
        email: string;
    };
    members?: Array<{
        id: number;
        name: string;
        email: string;
        shift_start: string | null;
        shift_end: string | null;
        shift_schedule?: ShiftScheduleEntry[];
    }>;
}

interface Manager {
    id: number;
    name: string;
    email: string;
}

interface Member {
    id: number;
    name: string;
    email: string;
    team?: Team | null;
}

const teams = ref<Team[]>([]);
const managers = ref<Manager[]>([]);
const members = ref<Member[]>([]);
const loading = ref(false);
const showModal = ref(false);
const showMemberModal = ref(false);
const editingTeam = ref<Team | null>(null);
const selectedTeam = ref<Team | null>(null);
const formData = ref({
    name: '',
    description: '',
    manager_id: null as number | null,
});

const memberFormData = ref({
    selectedMembers: [] as number[],
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

const dayLabel = (value: number) => dayOptions.find((d) => d.value === value)?.label ?? 'Unknown';

onMounted(() => {
    fetchTeams();
    fetchManagers();
});

const fetchTeams = async () => {
    loading.value = true;
    try {
        const response = await api.get('/admin/teams');
        teams.value = response.data;
    } catch (error) {
        console.error('Error fetching teams:', error);
        alert('Failed to fetch teams');
    } finally {
        loading.value = false;
    }
};

const fetchManagers = async () => {
    try {
        const response = await api.get('/admin/teams/managers/list');
        managers.value = response.data;
    } catch (error) {
        console.error('Error fetching managers:', error);
    }
};

const fetchAllUsers = async () => {
    try {
        const response = await api.get('/admin/users');
        members.value = response.data.data || response.data;
    } catch (error) {
        console.error('Error fetching users:', error);
    }
};

const openEditModal = (team?: Team) => {
    if (team) {
        editingTeam.value = team;
        formData.value = {
            name: team.name,
            description: team.description || '',
            manager_id: team.manager_id,
        };
    } else {
        editingTeam.value = null;
        formData.value = {
            name: '',
            description: '',
            manager_id: null,
        };
    }
    showModal.value = true;
};

const closeModal = () => {
    showModal.value = false;
    editingTeam.value = null;
};

const saveTeam = async () => {
    loading.value = true;
    try {
        if (editingTeam.value) {
            await api.put(`/admin/teams/${editingTeam.value.id}`, formData.value);
        } else {
            await api.post('/admin/teams', formData.value);
        }
        await fetchTeams();
        closeModal();
        formData.value = { name: '', description: '', manager_id: null };
    } catch (error: any) {
        alert(error.response?.data?.message || 'Error saving team');
    } finally {
        loading.value = false;
    }
};

const deleteTeam = async (teamId: number) => {
    if (!confirm('Are you sure you want to delete this team?')) return;
    
    try {
        await api.delete(`/admin/teams/${teamId}`);
        await fetchTeams();
    } catch (error: any) {
        alert(error.response?.data?.message || 'Error deleting team');
    }
};

const openMemberModal = async (team: Team) => {
    selectedTeam.value = team;
    await fetchAllUsers();
    memberFormData.value.selectedMembers = team.members?.map(m => m.id) || [];
    showMemberModal.value = true;
};

const closeMemberModal = () => {
    showMemberModal.value = false;
    selectedTeam.value = null;
    memberFormData.value.selectedMembers = [];
};

const saveMembers = async () => {
    if (!selectedTeam.value) return;
    
    loading.value = true;
    try {
        // Update each user's team
        for (const userId of memberFormData.value.selectedMembers) {
            await api.put(`/admin/users/${userId}`, { team_id: selectedTeam.value.id });
        }
        
        // Remove users from team if they were removed
        const previousMemberIds = selectedTeam.value.members?.map(m => m.id) || [];
        const removedMembers = previousMemberIds.filter(id => !memberFormData.value.selectedMembers.includes(id));
        
        for (const userId of removedMembers) {
            await api.put(`/admin/users/${userId}`, { team_id: null });
        }
        
        await fetchTeams();
        closeMemberModal();
    } catch (error: any) {
        alert(error.response?.data?.message || 'Error updating team members');
    } finally {
        loading.value = false;
    }
};

const toggleMember = (userId: number) => {
    const index = memberFormData.value.selectedMembers.indexOf(userId);
    if (index > -1) {
        memberFormData.value.selectedMembers.splice(index, 1);
    } else {
        memberFormData.value.selectedMembers.push(userId);
    }
};
</script>

<template>
    <Head title="Team Management" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <h2 class="text-xl font-semibold leading-tight text-gray-800">
                    Team Management
                </h2>
                <button
                    @click="openEditModal()"
                    class="rounded-md bg-indigo-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-500"
                >
                    Add Team
                </button>
            </div>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-7xl sm:px-6 lg:px-8 space-y-6">
                <div v-if="loading && teams.length === 0" class="flex justify-center py-12">
                    <div class="inline-block animate-spin rounded-full h-8 w-8 border-b-2 border-indigo-600"></div>
                </div>

                <div v-else class="grid gap-6">
                    <div v-for="team in teams" :key="team.id" class="bg-white shadow rounded-lg overflow-hidden">
                        <div class="px-6 py-4 border-b border-gray-200">
                            <div class="flex items-center justify-between">
                                <div>
                                    <h3 class="text-lg font-medium text-gray-900">{{ team.name }}</h3>
                                    <p v-if="team.description" class="text-sm text-gray-500 mt-1">{{ team.description }}</p>
                                </div>
                                <div class="flex gap-2">
                                    <button
                                        @click="openMemberModal(team)"
                                        class="rounded-md bg-green-600 px-3 py-1.5 text-xs font-semibold text-white shadow-sm hover:bg-green-500"
                                    >
                                        Manage Members
                                    </button>
                                    <button
                                        @click="openEditModal(team)"
                                        class="rounded-md bg-indigo-600 px-3 py-1.5 text-xs font-semibold text-white shadow-sm hover:bg-indigo-500"
                                    >
                                        Edit
                                    </button>
                                    <button
                                        @click="deleteTeam(team.id)"
                                        class="rounded-md bg-red-600 px-3 py-1.5 text-xs font-semibold text-white shadow-sm hover:bg-red-500"
                                    >
                                        Delete
                                    </button>
                                </div>
                            </div>
                        </div>
                        
                        <div class="px-6 py-4 space-y-3">
                            <div v-if="team.manager" class="flex items-center text-sm">
                                <span class="font-medium text-gray-700">Manager:</span>
                                <span class="ml-2 text-gray-900">{{ team.manager.name }} ({{ team.manager.email }})</span>
                            </div>
                            <div v-else class="text-sm text-gray-500 italic">No manager assigned</div>
                            
                            <div class="mt-4">
                                <h4 class="text-sm font-medium text-gray-700 mb-2">Team Members ({{ team.members?.length || 0 }})</h4>
                                <div v-if="team.members && team.members.length > 0" class="space-y-2">
                                    <div v-for="member in team.members" :key="member.id" class="flex items-center justify-between text-sm p-2 bg-gray-50 rounded">
                                        <div>
                                            <span class="font-medium text-gray-900">{{ member.name }}</span>
                                            <span class="text-gray-500 ml-2">({{ member.email }})</span>
                                        </div>
                                        <div class="text-right text-xs text-gray-600">
                                            <template v-if="member.shift_schedule?.length">
                                                <div v-for="entry in member.shift_schedule" :key="`${member.id}-${entry.day_of_week}`">
                                                    {{ dayLabel(entry.day_of_week) }}: {{ entry.start_time }} - {{ entry.end_time }}
                                                </div>
                                            </template>
                                            <template v-else-if="member.shift_start && member.shift_end">
                                                Default: {{ member.shift_start }} - {{ member.shift_end }}
                                            </template>
                                            <template v-else>
                                                Shift not set
                                            </template>
                                        </div>
                                    </div>
                                </div>
                                <p v-else class="text-sm text-gray-500 italic">No members in this team</p>
                            </div>
                        </div>
                    </div>
                    
                    <div v-if="teams.length === 0" class="text-center py-12 text-gray-500">
                        <p>No teams created yet. Click "Add Team" to get started.</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Team Modal -->
        <div v-if="showModal" class="fixed inset-0 z-50 overflow-y-auto" aria-labelledby="modal-title" role="dialog" aria-modal="true">
            <div class="flex min-h-screen items-end justify-center px-4 pt-4 pb-20 text-center sm:block sm:p-0">
                <div class="fixed inset-0 transition-opacity bg-gray-500 bg-opacity-75" aria-hidden="true" @click="closeModal"></div>
                
                <div class="relative inline-block transform overflow-hidden rounded-lg bg-white text-left align-bottom shadow-xl transition-all sm:my-8 sm:w-full sm:max-w-lg">
                    <div class="bg-white px-4 pt-5 pb-4 sm:p-6 sm:pb-4">
                        <h3 class="text-lg font-medium leading-6 text-gray-900 mb-4">
                            {{ editingTeam ? 'Edit Team' : 'Add Team' }}
                        </h3>
                        
                        <form @submit.prevent="saveTeam">
                            <div class="mb-4">
                                <label class="block text-sm font-medium text-gray-700">Team Name</label>
                                <input
                                    v-model="formData.name"
                                    type="text"
                                    required
                                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm"
                                />
                            </div>
                            
                            <div class="mb-4">
                                <label class="block text-sm font-medium text-gray-700">Description</label>
                                <textarea
                                    v-model="formData.description"
                                    rows="3"
                                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm"
                                ></textarea>
                            </div>
                            
                            <div class="mb-4">
                                <label class="block text-sm font-medium text-gray-700">Manager</label>
                                <select
                                    v-model="formData.manager_id"
                                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm"
                                >
                                    <option :value="null">No Manager</option>
                                    <option v-for="manager in managers" :key="manager.id" :value="manager.id">
                                        {{ manager.name }} ({{ manager.email }})
                                    </option>
                                </select>
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

        <!-- Member Modal -->
        <div v-if="showMemberModal" class="fixed inset-0 z-50 overflow-y-auto" aria-labelledby="modal-title" role="dialog" aria-modal="true">
            <div class="flex min-h-screen items-end justify-center px-4 pt-4 pb-20 text-center sm:block sm:p-0">
                <div class="fixed inset-0 transition-opacity bg-gray-500 bg-opacity-75" aria-hidden="true" @click="closeMemberModal"></div>
                
                <div class="relative inline-block transform overflow-hidden rounded-lg bg-white text-left align-bottom shadow-xl transition-all sm:my-8 sm:w-full sm:max-w-2xl">
                    <div class="bg-white px-4 pt-5 pb-4 sm:p-6 sm:pb-4">
                        <h3 class="text-lg font-medium leading-6 text-gray-900 mb-4">
                            Manage Team Members: {{ selectedTeam?.name }}
                        </h3>
                        
                        <div class="max-h-96 overflow-y-auto">
                            <div class="space-y-2">
                                <div
                                    v-for="member in members"
                                    :key="member.id"
                                    @click="toggleMember(member.id)"
                                    :class="[
                                        'flex items-center justify-between p-3 rounded cursor-pointer transition',
                                        memberFormData.selectedMembers.includes(member.id)
                                            ? 'bg-indigo-50 border-2 border-indigo-500'
                                            : 'bg-gray-50 border-2 border-transparent hover:bg-gray-100'
                                    ]"
                                >
                                    <div>
                                        <div class="font-medium text-gray-900">{{ member.name }}</div>
                                        <div class="text-sm text-gray-500">{{ member.email }}</div>
                                        <div v-if="member.team && member.team.id !== selectedTeam?.id" class="text-xs text-orange-600 mt-1">
                                            Currently in: {{ member.team.name }}
                                        </div>
                                    </div>
                                    <div class="flex items-center">
                                        <div v-if="memberFormData.selectedMembers.includes(member.id)" class="w-5 h-5 bg-indigo-600 rounded-full flex items-center justify-center">
                                            <svg class="w-3 h-3 text-white" fill="currentColor" viewBox="0 0 20 20">
                                                <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/>
                                            </svg>
                                        </div>
                                        <div v-else class="w-5 h-5 border-2 border-gray-300 rounded-full"></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="mt-6 flex justify-end space-x-3">
                            <button
                                type="button"
                                @click="closeMemberModal"
                                class="rounded-md border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50"
                            >
                                Cancel
                            </button>
                            <button
                                @click="saveMembers"
                                class="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700"
                            >
                                Save
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>

