import axios from 'axios';

const api = axios.create({
    baseURL: '/api',
    headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
    },
    withCredentials: true,
});

// Add CSRF token to requests from cookies
api.defaults.xsrfCookieName = 'XSRF-TOKEN';
api.defaults.xsrfHeaderName = 'X-XSRF-TOKEN';

// Handle unauthorized responses - don't redirect automatically
api.interceptors.response.use(
    (response) => response,
    async (error) => {
        // Only redirect if it's not already on the login page
        if (error.response?.status === 401 && !window.location.pathname.includes('/login')) {
            // Show error message instead of redirecting
            console.error('Authentication error');
        }
        
        return Promise.reject(error);
    }
);

export default api;

