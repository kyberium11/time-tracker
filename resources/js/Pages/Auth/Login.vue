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

        <div class="min-h-screen flex items-center justify-center bg-white py-12 px-4 sm:px-6 lg:px-8">
            <div class="w-full max-w-md space-y-8">
                <!-- Title -->
                <div class="text-center">
                    <h2 class="mt-2 text-3xl font-bold text-gray-900 tracking-tight">
                        Time Tracker
                    </h2>
                </div>

                <!-- Login Form Card -->
                <div class="bg-white rounded-xl shadow-md p-8 border border-gray-200">
                    <div v-if="status" class="mb-4 p-3 rounded-lg bg-green-50 border border-green-200">
                        <p class="text-sm font-medium text-green-700">{{ status }}</p>
                    </div>

                    <form @submit.prevent="submit" class="space-y-6">
                        <div>
                            <InputLabel for="email" value="Email Address" class="text-gray-900" />
                            <TextInput
                                id="email"
                                type="email"
                                class="mt-2 block w-full bg-white border-gray-300 text-gray-900 placeholder-gray-500 focus:border-gray-900 focus:ring-gray-900"
                                placeholder="Enter your email"
                                v-model="form.email"
                                required
                                autofocus
                                autocomplete="username"
                            />
                            <InputError class="mt-2" :message="form.errors.email" />
                        </div>

                        <div>
                            <InputLabel for="password" value="Password" class="text-gray-900" />
                            <TextInput
                                id="password"
                                type="password"
                                class="mt-2 block w-full bg-white border-gray-300 text-gray-900 placeholder-gray-500 focus:border-gray-900 focus:ring-gray-900"
                                placeholder="Enter your password"
                                v-model="form.password"
                                required
                                autocomplete="current-password"
                            />
                            <InputError class="mt-2" :message="form.errors.password" />
                        </div>

                        <div class="flex items-center justify-between">
                            <label class="flex items-center">
                                <Checkbox name="remember" v-model:checked="form.remember" class="border-gray-300" />
                                <span class="ml-2 text-sm text-gray-700">Remember me</span>
                            </label>

                            <Link
                                v-if="canResetPassword"
                                :href="route('password.request')"
                                class="text-sm text-gray-700 hover:text-gray-900 transition-colors"
                            >
                                Forgot password?
                            </Link>
                        </div>

                        <PrimaryButton
                            class="w-full bg-gray-900 hover:bg-black text-white font-semibold py-3 rounded-lg shadow-sm transition-colors"
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
                    <p class="text-xs text-gray-500">
                        © 2024 Time Tracker. All rights reserved.
                    </p>
                </div>
            </div>
        </div>
    </GuestLayout>
</template>
