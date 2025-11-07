import '../css/app.css';
import './bootstrap';

import { createInertiaApp } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createApp, DefineComponent, h } from 'vue';
import { ZiggyVue } from '../../vendor/tightenco/ziggy';
import { router } from '@inertiajs/vue3';

const appName = import.meta.env.VITE_APP_NAME || 'Time Tracker';

// Get CSRF token from cookie or meta tag
const getCsrfToken = () => {
    // Try meta tag first
    const metaTag = document.querySelector('meta[name="csrf-token"]');
    if (metaTag) {
        return metaTag.getAttribute('content');
    }
    
    // Try cookie
    const cookies = document.cookie.split(';');
    for (let cookie of cookies) {
        const [name, value] = cookie.trim().split('=');
        if (name === 'XSRF-TOKEN') {
            return decodeURIComponent(value);
        }
    }
    
    return null;
};

// Configure Inertia to include CSRF token in all requests
router.on('start', (event) => {
    const csrfToken = getCsrfToken();
    const method = event.detail.visit.method?.toString().toUpperCase();
    if (csrfToken && method && method !== 'GET') {
        // Ensure headers object exists
        if (!event.detail.visit.headers) {
            event.detail.visit.headers = {};
        }
        
        // Add CSRF token headers
        event.detail.visit.headers['X-CSRF-TOKEN'] = csrfToken;
        event.detail.visit.headers['X-XSRF-TOKEN'] = csrfToken;
    }
});

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
