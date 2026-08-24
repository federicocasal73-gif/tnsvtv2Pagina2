/**
 * T.N.S.V.T — Theme Manager
 *
 * Gestiona los temas dark / light / auto:
 * - Lee la preferencia guardada en localStorage.
 * - Si es "auto", sigue prefers-color-scheme del sistema.
 * - Actualiza <html data-theme="..."> para activar los tokens correctos.
 * - Emite un evento `theme:change` para que otros componentes reaccionen.
 *
 * API:
 *   window.tnsvtTheme.get()              -> 'dark' | 'light' | 'auto'
 *   window.tnsvtTheme.set(theme)         -> aplica y guarda el tema
 *   window.tnsvtTheme.applyTo(html)      -> aplica el tema actual a un elemento
 *   window.tnsvtTheme.isDark()           -> true si el tema efectivo es dark
 */
(function () {
    'use strict';

    const STORAGE_KEY = 'tnsvt_theme';
    const VALID = ['dark', 'light', 'auto'];
    const html = document.documentElement;

    function getStored() {
        try {
            const v = localStorage.getItem(STORAGE_KEY);
            return VALID.includes(v) ? v : 'auto';
        } catch (_) {
            return 'auto';
        }
    }

    function systemPrefersLight() {
        return window.matchMedia &&
               window.matchMedia('(prefers-color-scheme: light)').matches;
    }

    function effectiveIsDark(theme) {
        if (theme === 'dark') return true;
        if (theme === 'light') return false;
        return !systemPrefersLight(); // auto
    }

    function apply(theme) {
        html.setAttribute('data-theme', theme);
        // Mantener la clase .dark por compatibilidad con código legacy.
        if (effectiveIsDark(theme)) {
            html.classList.add('dark');
            html.classList.remove('light');
        } else {
            html.classList.add('light');
            html.classList.remove('dark');
        }
        // Notificar a Stimulus controllers y otros listeners.
        try {
            html.dispatchEvent(new CustomEvent('theme:change', {
                bubbles: false,
                detail: { theme: theme, effectiveDark: effectiveIsDark(theme) }
            }));
        } catch (_) {}
    }

    function set(theme) {
        if (!VALID.includes(theme)) theme = 'auto';
        try { localStorage.setItem(STORAGE_KEY, theme); } catch (_) {}
        apply(theme);
        // Sincronizar cualquier select con data-theme-target en la página.
        syncSelectors(theme);
    }

    function get() {
        return html.getAttribute('data-theme') || getStored();
    }

    function isDark() {
        return effectiveIsDark(get());
    }

    // Sincroniza todos los <select data-theme-target> al tema actual.
    function syncSelectors(theme) {
        document.querySelectorAll('[data-theme-target]').forEach(function (el) {
            if (el.value !== theme) el.value = theme;
        });
    }

    // Reaccionar a cambios del sistema cuando el tema es "auto".
    if (window.matchMedia) {
        const mq = window.matchMedia('(prefers-color-scheme: light)');
        const listener = function (e) {
            if (get() === 'auto') apply('auto');
        };
        if (mq.addEventListener) mq.addEventListener('change', listener);
        else if (mq.addListener) mq.addListener(listener); // Safari viejo
    }

    // Aplicar el tema guardado antes del primer paint (FOUC prevention).
    apply(getStored());

    // Wire de los selects de tema cuando el DOM esté listo.
    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('[data-theme-target]').forEach(function (el) {
            const current = get();
            if (el.value !== current) el.value = current;
            el.addEventListener('change', function () {
                set(el.value);
            });
        });
    });

    // Exponer API global.
    window.tnsvtTheme = {
        get: get,
        set: set,
        apply: apply,
        isDark: isDark,
        syncSelectors: syncSelectors,
    };
})();
