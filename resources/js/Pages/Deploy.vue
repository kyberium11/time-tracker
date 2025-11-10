<script setup lang="ts">
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head } from '@inertiajs/vue3';
import { computed } from 'vue';

// Developer token for deploy.php access
// This matches the token in deploy.php
const developerToken = 'dev_deploy_access_2024';

const deployUrl = computed(() => {
    return `/deploy.php?from_app=1&token=${developerToken}`;
});
</script>

<template>
    <Head title="Deploy" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">
                Deployment Tools
            </h2>
        </template>

        <div class="py-12">
            <div class="mx-auto max-w-7xl sm:px-6 lg:px-8">
                <div class="overflow-hidden bg-white shadow sm:rounded-lg">
                    <div class="p-6">
                        <div class="mb-4">
                            <p class="text-sm text-gray-600">
                                Access the deployment tools below. This interface allows you to manage deployments, run migrations, and perform system maintenance tasks.
                            </p>
                        </div>
                        <div class="border border-gray-200 rounded-lg overflow-hidden">
                            <iframe
                                :src="deployUrl"
                                class="w-full"
                                style="height: 800px; border: none;"
                                title="Deployment Tools"
                            ></iframe>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>

