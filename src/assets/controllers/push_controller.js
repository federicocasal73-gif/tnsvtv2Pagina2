import { Controller } from '@hotwired/stimulus';

// Web push registration (Firebase Cloud Messaging).
// - Fetches the PUBLIC web config from /api/firebase/config (keys are never
//   hardcoded; the endpoint 503s when unconfigured and we bail silently).
// - Dynamically imports the Firebase modular SDK from jsDelivr
//   (CSP-allowlisted in script-src). No bundler changes needed.
// - Asks Notification permission ONCE (localStorage flag), mints an FCM
//   token bound to our /sw.js registration + VAPID key, and POSTs it to
//   /api/devices/register. Re-registers only when the token changes.
// - Foreground messages surface via window.apiToast.
// Everything is best-effort: any failure leaves push off, chat/polling
// keep working exactly as before.
const FIREBASE_VERSION = '10.12.2';
const TOKEN_STORAGE_KEY = 'tnsvt-fcm-token';
const ASKED_STORAGE_KEY = 'tnsvt-push-asked';

export default class extends Controller {
    async connect() {
        if (!('Notification' in window) || !('serviceWorker' in navigator)) return;
        const code = window.TNSVT_USER?.code;
        if (code) {
            await this.setup(code);
        } else {
            window.addEventListener(
                'tnsvt:user-loaded',
                (e) => {
                    if (e.detail?.code) this.setup(e.detail.code);
                },
                { once: true }
            );
        }
    }

    async setup(code) {
        try {
            if (Notification.permission === 'denied') return;
            if (Notification.permission === 'default') {
                if (localStorage.getItem(ASKED_STORAGE_KEY)) return;
                localStorage.setItem(ASKED_STORAGE_KEY, '1');
                const perm = await Notification.requestPermission();
                if (perm !== 'granted') return;
            }
            const cfg = await this.fetchWebConfig();
            if (!cfg) return;
            const base = `https://cdn.jsdelivr.net/npm/firebase@${FIREBASE_VERSION}`;
            const { initializeApp } = await import(`${base}/firebase-app/+esm`);
            const { getMessaging, getToken, onMessage } = await import(
                `${base}/firebase-messaging/+esm`
            );
            const app = initializeApp({
                apiKey: cfg.apiKey,
                authDomain: cfg.authDomain,
                projectId: cfg.projectId,
                storageBucket: cfg.storageBucket,
                messagingSenderId: cfg.messagingSenderId,
                appId: cfg.appId,
            });
            const messaging = getMessaging(app);
            // Bind the push subscription to our own /sw.js registration
            // (scope '/') instead of the default firebase-messaging-sw.js.
            const registration = await navigator.serviceWorker.ready;
            const token = await getToken(messaging, {
                vapidKey: cfg.vapidKey,
                serviceWorkerRegistration: registration,
            });
            if (!token) return;
            onMessage(messaging, (payload) => this.showForeground(payload));
            await this.registerToken(code, token);
        } catch {
            // Push stays off; nothing else breaks.
        }
    }

    async fetchWebConfig() {
        if (typeof window.apiFetch !== 'function') return null;
        const r = await window.apiFetch('/api/firebase/config', { silent: true });
        if (!r.ok || !r.data?.configured) return null;
        const cfg = r.data;
        if (
            !cfg.apiKey ||
            !cfg.projectId ||
            !cfg.messagingSenderId ||
            !cfg.appId ||
            !cfg.vapidKey
        ) {
            return null;
        }
        return cfg;
    }

    async showForeground(payload) {
        const title = payload?.notification?.title || 'Sanctum';
        const body = payload?.notification?.body || '';
        if (typeof window.apiToast === 'function') {
            window.apiToast(body ? `${title}: ${body}` : title, 'info');
        }
    }

    async registerToken(code, token) {
        const stored = localStorage.getItem(TOKEN_STORAGE_KEY);
        if (stored === `${code}:${token}`) return;
        if (typeof window.apiFetch !== 'function') return;
        const r = await window.apiFetch('/api/devices/register', {
            method: 'POST',
            body: {
                user_code: code,
                fcm_token: token,
                platform: 'web',
                device_model: navigator.platform || 'web',
            },
            silent: true,
        });
        if (r.ok) localStorage.setItem(TOKEN_STORAGE_KEY, `${code}:${token}`);
    }
}
