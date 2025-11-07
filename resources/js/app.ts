import '../css/app.css';
import './bootstrap';

import { createInertiaApp } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createApp, DefineComponent, h } from 'vue';
import { ZiggyVue } from '../../vendor/tightenco/ziggy';
import { router } from '@inertiajs/vue3';

const appName = import.meta.env.VITE_APP_NAME || 'Time Tracker';

// Get CSRF token from meta tag or cookie
const getCsrfToken = () => {
    const metaTag = document.querySelector('meta[name="csrf-token"]');
    if (metaTag) {
        return metaTag.getAttribute('content');
    }
    
    // Try to get from cookie
    const cookies = document.cookie.split(';');
    for (let cookie of cookies) {
        const [name, value] = cookie.trim().split('=');
        if (name === 'XSRF-TOKEN') {
            return decodeURIComponent(value);
        }
    }
    
    return null;
};

// Configure Inertia router to include CSRF token in all POST/PUT/PATCH/DELETE requests
const csrfToken = getCsrfToken();
if (csrfToken) {
    router.on('start', (event) => {
        const method = event.detail.visit.method?.toUpperCase();
        if (method && method !== 'GET') {
            // Add CSRF token to headers
            event.detail.visit.headers = {
                ...event.detail.visit.headers,
                'X-CSRF-TOKEN': csrfToken,
                'X-XSRF-TOKEN': csrfToken,
            };
        }
    });
}

createInertiaApp({
    title: (title) => `${title} - ${appName}`,
    resolve: (name) =>
        resolvePageComponent(
            `./Pages/${name}.vue`,
            import.meta.glob<DefineComponent>('./Pages/**/*.vue'),
        ),
    setup({ el, App, props, plugin }) {
        createApp({ render: () => h(App, props) })
            .use(plugin)
            .use(ZiggyVue)
            .mount(el);
    },
    progress: {
        color: '#4B5563',
    },
});
