import { Controller } from '@hotwired/stimulus';

/**
 * Registers the service worker and handles PWA install prompt.
 * Activates as soon as the app shell loads.
 *
 * Usage: <body data-controller="pwa">
 */
export default class extends Controller {
    connect() {
        if (!('serviceWorker' in navigator)) {
            return;
        }

        // Register service worker
        navigator.serviceWorker
            .register('/sw.js', { scope: '/' })
            .then((registration) => {
                if (registration.waiting) {
                    registration.waiting.postMessage('SKIP_WAITING');
                }
                if (registration.active && !navigator.serviceWorker.controller) {
                    window.location.reload();
                }
            })
            .catch((err) => {
                console.warn('SW registration failed:', err);
            });

        // Listen for SW updates
        navigator.serviceWorker?.addEventListener('controllerchange', () => {
            window.location.reload();
        });

        // Capture install prompt for later use
        window.addEventListener('beforeinstallprompt', (e) => {
            e.preventDefault();
            this.installPrompt = e;
            this.showInstallButton();
        });
    }

    showInstallButton() {
        const btn = document.querySelector('[data-pwa-install]');
        if (btn) {
            btn.classList.remove('hidden');
        }
    }

    async install(event) {
        event.preventDefault();
        if (!this.installPrompt) {
            return;
        }
        this.installPrompt.prompt();
        const choice = await this.installPrompt.userChoice;
        this.installPrompt = null;
        if (choice.outcome === 'accepted') {
            const btn = document.querySelector('[data-pwa-install]');
            if (btn) btn.classList.add('hidden');
        }
    }
}