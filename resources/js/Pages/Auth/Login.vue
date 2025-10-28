<script setup lang="ts">
import Checkbox from '@/Components/Checkbox.vue';
import GuestLayout from '@/Layouts/GuestLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

defineProps<{
    canResetPassword?: boolean;
    status?: string;
}>();

const form = useForm({
    email: '',
    password: '',
    remember: false,
});

const submit = () => {
    form.post(route('login'), {
        onFinish: () => {
            form.reset('password');
        },
    });
};
</script>

<template>
    <GuestLayout>
        <Head title="Log in" />

        <div class="min-h-screen flex items-center justify-center bg-gradient-to-br from-indigo-900 via-purple-900 to-pink-900 py-12 px-4 sm:px-6 lg:px-8">
            <div class="w-full max-w-2xl space-y-8">
                <!-- Logo and Title -->
                <div class="text-center">
                    <div class="flex justify-center">
                        <div class="relative">
                            <div class="absolute inset-0 bg-white blur-3xl opacity-20"></div>
                            <div class="relative bg-gradient-to-r from-indigo-400 to-purple-500 p-4 rounded-2xl shadow-2xl">
                                <svg class="w-16 h-16 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                                </svg>
                            </div>
                        </div>
                    </div>
                    <h2 class="mt-8 text-4xl font-extrabold text-white tracking-tight">
                        Time Tracker Elite
                    </h2>
                    <p class="mt-2 text-sm text-indigo-200">
                        Professional Time Management System
                    </p>
                </div>

                <!-- Login Form Card -->
                <div class="bg-white/10 backdrop-blur-lg rounded-2xl shadow-2xl p-8 border border-white/20">
                    <div v-if="status" class="mb-4 p-3 rounded-lg bg-green-500/20 border border-green-400/30">
                        <p class="text-sm font-medium text-green-200">{{ status }}</p>
                    </div>

                    <form @submit.prevent="submit" class="space-y-6">
                        <div>
                            <InputLabel for="email" value="Email Address" class="text-gray-200" />
                            <TextInput
                                id="email"
                                type="email"
                                class="mt-2 block w-full bg-white/10 border-white/20 text-white placeholder-gray-400 focus:border-indigo-400 focus:ring-indigo-400"
                                placeholder="Enter your email"
                                v-model="form.email"
                                required
                                autofocus
                                autocomplete="username"
                            />
                            <InputError class="mt-2" :message="form.errors.email" />
                        </div>

                        <div>
                            <InputLabel for="password" value="Password" class="text-gray-200" />
                            <TextInput
                                id="password"
                                type="password"
                                class="mt-2 block w-full bg-white/10 border-white/20 text-white placeholder-gray-400 focus:border-indigo-400 focus:ring-indigo-400"
                                placeholder="Enter your password"
                                v-model="form.password"
                                required
                                autocomplete="current-password"
                            />
                            <InputError class="mt-2" :message="form.errors.password" />
                        </div>

                        <div class="flex items-center justify-between">
                            <label class="flex items-center">
                                <Checkbox name="remember" v-model:checked="form.remember" class="bg-white/10 border-white/20" />
                                <span class="ml-2 text-sm text-gray-300">Remember me</span>
                            </label>

                            <Link
                                v-if="canResetPassword"
                                :href="route('password.request')"
                                class="text-sm text-indigo-300 hover:text-indigo-200 transition-colors"
                            >
                                Forgot password?
                            </Link>
                        </div>

                        <PrimaryButton
                            class="w-full bg-gradient-to-r from-indigo-500 to-purple-600 hover:from-indigo-600 hover:to-purple-700 text-white font-semibold py-3 rounded-lg shadow-lg transition-all transform hover:scale-105"
                            :class="{ 'opacity-50': form.processing, 'cursor-not-allowed': form.processing }"
                            :disabled="form.processing"
                        >
                            <span v-if="form.processing">Signing in...</span>
                            <span v-else>Sign In</span>
                        </PrimaryButton>
                    </form>
                </div>

                <!-- Footer -->
                <div class="text-center">
                    <p class="text-xs text-gray-400">
                        © 2024 Time Tracker Elite. All rights reserved.
                    </p>
                </div>
            </div>
        </div>
    </GuestLayout>
</template>
