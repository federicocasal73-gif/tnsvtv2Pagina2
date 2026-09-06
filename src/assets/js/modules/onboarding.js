/**
 * TNSVT — Onboarding Wizard Module
 *
 * Lógica del modal first-login con 4 slides.
 * - Persistencia en localStorage (`tnsvt_onboarding_v1`)
 * - Trigger: post-login (cuando `/api/auth/check` devuelve user OK)
 * - Navegación: dots, prev/next, finish, skip
 * - Accesibilidad: focus trap, Escape, ARIA
 * - Reduced-motion: skip animations
 */

(function() {
    'use strict';

    const STORAGE_KEY = 'tnsvt_onboarding_v1';
    const TOTAL_SLIDES = 4;

    let overlay, slides, dots, prevBtn, nextBtn, counter;
    let currentSlide = 1;
    let isTransitioning = false;
    let reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    function init() {
        overlay = document.getElementById('tnsvt-onboarding');
        if (!overlay) return;

        slides = overlay.querySelectorAll('.onboarding-slide');
        dots = overlay.querySelectorAll('.onboarding-dot');
        prevBtn = overlay.querySelector('[data-onboarding-action="prev"]');
        nextBtn = overlay.querySelector('[data-onboarding-action="next"]');
        counter = overlay.querySelector('[data-onboarding-counter]');

        if (!slides.length) return;

        // Si ya completó, no mostrar
        if (isCompleted()) return;

        // Wire buttons
        overlay.querySelectorAll('[data-onboarding-action]').forEach(btn => {
            btn.addEventListener('click', handleAction);
        });

        // Wire dots
        dots.forEach(dot => {
            dot.addEventListener('click', () => {
                const target = parseInt(dot.dataset.dot, 10);
                if (target !== currentSlide) goTo(target);
            });
        });

        // Keyboard nav: Escape cierra, ArrowLeft/Right navega
        overlay.addEventListener('keydown', e => {
            if (e.key === 'Escape') {
                e.preventDefault();
                skip();
            } else if (e.key === 'ArrowLeft' && currentSlide > 1) {
                e.preventDefault();
                goTo(currentSlide - 1);
            } else if (e.key === 'ArrowRight' && currentSlide < TOTAL_SLIDES) {
                e.preventDefault();
                goTo(currentSlide + 1);
            }
        });

        // Reduced motion listener
        const mq = window.matchMedia('(prefers-reduced-motion: reduce)');
        mq.addEventListener('change', e => { reducedMotion = e.matches; });

        // Trigger: mostrar cuando el usuario esté logueado
        // Espera al evento `tnsvt:user-loaded` (emitido por shell.html.twig tras auth OK)
        window.addEventListener('tnsvt:user-loaded', () => {
            // Pequeño delay para que la transición de login sea suave
            setTimeout(show, 600);
        });

        // Fallback: si el evento ya se disparó antes de cargar este script
        if (window.TNSVT_USER && window.TNSVT_USER.code) {
            setTimeout(show, 600);
        }
    }

    function isCompleted() {
        try {
            return localStorage.getItem(STORAGE_KEY) === 'completed';
        } catch (e) {
            return false;
        }
    }

    function markCompleted() {
        try {
            localStorage.setItem(STORAGE_KEY, 'completed');
        } catch (e) {
            /* localStorage no disponible */
        }
    }

    function show() {
        if (!overlay) return;
        if (isCompleted()) return;
        overlay.hidden = false;
        document.body.style.overflow = 'hidden';
        // Focus en el primer botón accesible (Next)
        if (nextBtn) nextBtn.focus();
        // Trap focus
        trapFocus(true);
    }

    function hide() {
        if (!overlay) return;
        overlay.hidden = true;
        document.body.style.overflow = '';
        trapFocus(false);
    }

    function skip() {
        markCompleted();
        hide();
    }

    function finish() {
        markCompleted();
        hide();
    }

    function finishAndGo(url) {
        markCompleted();
        hide();
        if (url) window.location.href = url;
    }

    function handleAction(e) {
        const action = e.currentTarget.dataset.onboardingAction;
        if (isTransitioning) return;

        switch (action) {
            case 'skip':
                skip();
                break;
            case 'prev':
                if (currentSlide > 1) goTo(currentSlide - 1);
                break;
            case 'next':
                if (currentSlide < TOTAL_SLIDES) {
                    goTo(currentSlide + 1);
                } else {
                    finish();
                }
                break;
            case 'finish-and-go':
                const href = e.currentTarget.getAttribute('href');
                finishAndGo(href);
                break;
        }
    }

    function goTo(target) {
        if (target < 1 || target > TOTAL_SLIDES || target === currentSlide) return;
        isTransitioning = true;

        const currentEl = overlay.querySelector(`.onboarding-slide[data-slide="${currentSlide}"]`);
        const targetEl = overlay.querySelector(`.onboarding-slide[data-slide="${target}"]`);

        // Determinar dirección
        const goingForward = target > currentSlide;

        if (currentEl) {
            currentEl.classList.remove('active');
            if (goingForward) {
                currentEl.classList.add('exit-left');
            }
        }

        if (targetEl) {
            if (goingForward) {
                // Entra desde la derecha
                targetEl.style.transform = reducedMotion ? 'none' : 'translateX(40px)';
                // Forzar reflow antes de la transición
                targetEl.offsetHeight;
                requestAnimationFrame(() => {
                    targetEl.classList.add('active');
                    targetEl.style.transform = '';
                });
            } else {
                // Entra desde la izquierda
                targetEl.style.transform = reducedMotion ? 'none' : 'translateX(-40px)';
                targetEl.offsetHeight;
                requestAnimationFrame(() => {
                    targetEl.classList.add('active');
                    targetEl.style.transform = '';
                });
            }
        }

        // Actualizar dots
        dots.forEach(d => {
            const isActive = parseInt(d.dataset.dot, 10) === target;
            d.classList.toggle('active', isActive);
            d.setAttribute('aria-selected', isActive ? 'true' : 'false');
        });

        // Actualizar counter
        if (counter) counter.textContent = target;

        // Actualizar botones prev/next
        if (prevBtn) prevBtn.disabled = (target === 1);
        if (nextBtn) {
            nextBtn.textContent = (target === TOTAL_SLIDES) ? 'Finalizar ✓' : 'Siguiente →';
        }

        currentSlide = target;

        // Limpiar exit-left después de la transición
        setTimeout(() => {
            if (currentEl) currentEl.classList.remove('exit-left');
            isTransitioning = false;
        }, reducedMotion ? 0 : 400);
    }

    function trapFocus(enable) {
        if (!enable) {
            document.removeEventListener('keydown', focusTrapHandler);
            return;
        }

        document.addEventListener('keydown', focusTrapHandler);
    }

    function focusTrapHandler(e) {
        if (e.key !== 'Tab' || !overlay || overlay.hidden) return;

        const focusableEls = overlay.querySelectorAll(
            'button:not([disabled]), [href], input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'
        );
        const firstFocusable = focusableEls[0];
        const lastFocusable = focusableEls[focusableEls.length - 1];

        if (e.shiftKey) {
            if (document.activeElement === firstFocusable) {
                lastFocusable.focus();
                e.preventDefault();
            }
        } else {
            if (document.activeElement === lastFocusable) {
                firstFocusable.focus();
                e.preventDefault();
            }
        }
    }

    // Boot
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
