/**
 * TNSVT — Divine Overlay Module
 *
 * Puerto del "cielo estrellado" de V1 (assets/app.js:339-364).
 * Se monta encima del WebGL shader existente (webgl-background.js).
 *
 * Capas:
 *  - 300 stars con twinkle sinusoidal (alpha modulada por Math.sin)
 *  - 80 gold particles drifting vertical con wrap horizontal
 *  - Shooting meteors random con trail de 15 frames
 *  - Radial gradient de fondo dark-deep
 *
 * Optimizaciones:
 *  - Pausa en tab oculto (visibilitychange)
 *  - prefers-reduced-motion: solo dibuja 1 frame estático
 *  - Mobile (≤768px): solo stars (battery saver)
 *  - Resize handler regenera stars/particles
 */

(function() {
    'use strict';

    let canvas, ctx;
    let stars = [], particles = [], meteors = [];
    let rafId = null;
    let isVisible = !document.hidden;
    let reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    let isMobile = window.matchMedia('(max-width: 768px)').matches;

    function init() {
        if (canvas) return;

        // Crear canvas overlay (encima del WebGL shader)
        canvas = document.createElement('canvas');
        canvas.id = 'divine-canvas-overlay';
        canvas.setAttribute('aria-hidden', 'true');
        canvas.style.cssText = 'position:fixed;top:0;left:0;width:100%;height:100%;z-index:-1;pointer-events:none;opacity:0;transition:opacity 600ms ease;';
        document.body.appendChild(canvas);

        ctx = canvas.getContext('2d');
        if (!ctx) return;

        resize();

        // Visibility API — pausar cuando el tab está oculto
        document.addEventListener('visibilitychange', () => {
            isVisible = !document.hidden;
            if (isVisible && !rafId) loop();
            else if (!isVisible && rafId) cancelAnimationFrame(rafId), rafId = null;
        });

        // Reduced-motion — pausa después de 1 frame
        const mq = window.matchMedia('(prefers-reduced-motion: reduce)');
        mq.addEventListener('change', e => {
            reducedMotion = e.matches;
            if (!rafId) loop();
        });

        // Resize handler con debounce
        let resizeTimer;
        window.addEventListener('resize', () => {
            clearTimeout(resizeTimer);
            resizeTimer = setTimeout(resize, 150);
        });

        // Mobile detection
        window.matchMedia('(max-width: 768px)').addEventListener('change', e => {
            isMobile = e.matches;
            stars = []; particles = []; meteors = [];
            resize();
        });

        loop();

        // Fade-in
        requestAnimationFrame(() => {
            canvas.style.opacity = '1';
        });
    }

    function resize() {
        canvas.width = window.innerWidth;
        canvas.height = window.innerHeight;

        stars = [];
        particles = [];
        meteors = [];

        // 300 stars con twinkle
        const starCount = isMobile ? 150 : 300;
        for (let i = 0; i < starCount; i++) {
            stars.push({
                x: Math.random() * canvas.width,
                y: Math.random() * canvas.height,
                radius: Math.random() * 1.5 + 0.4,
                alpha: Math.random() * 0.5 + 0.3,
                twinkle: Math.random() * 0.02 + 0.01,
                phase: Math.random() * Math.PI * 2
            });
        }

        // 80 gold particles (skipped en mobile)
        if (!isMobile) {
            for (let i = 0; i < 80; i++) {
                particles.push({
                    x: Math.random() * canvas.width,
                    y: Math.random() * canvas.height,
                    radius: Math.random() * 3 + 1,
                    alpha: Math.random() * 0.6 + 0.2,
                    vy: Math.random() * 0.5 + 0.2,
                    vx: (Math.random() - 0.5) * 0.3
                });
            }
        }
    }

    function createMeteor() {
        if (isMobile || Math.random() > 0.02) return;
        meteors.push({
            x: Math.random() * canvas.width,
            y: 0,
            radius: Math.random() * 3 + 2,
            vx: (Math.random() - 0.5) * 3,
            vy: Math.random() * 6 + 4,
            trail: [],
            life: 100
        });
    }

    function draw() {
        if (!isVisible || reducedMotion) {
            // Reduced motion: solo 1 frame estático
            if (reducedMotion) drawFrame();
            return;
        }

        drawFrame();
        rafId = requestAnimationFrame(draw);
    }

    function drawFrame() {
        // Clear con radial gradient dark-deep
        ctx.clearRect(0, 0, canvas.width, canvas.height);

        const grad = ctx.createRadialGradient(
            canvas.width / 2, canvas.height / 2, 50,
            canvas.width / 2, canvas.height / 2, canvas.width / 2
        );
        grad.addColorStop(0, '#0a0618');
        grad.addColorStop(0.5, '#05030c');
        grad.addColorStop(1, '#010003');
        ctx.fillStyle = grad;
        ctx.fillRect(0, 0, canvas.width, canvas.height);

        const now = Date.now();

        // Stars con twinkle
        for (let i = 0; i < stars.length; i++) {
            const s = stars[i];
            const twinkle = Math.sin(now * s.twinkle + s.phase) * 0.15;
            ctx.beginPath();
            ctx.arc(s.x, s.y, s.radius, 0, Math.PI * 2);
            ctx.fillStyle = `rgba(255, 215, 150, ${s.alpha + twinkle})`;
            ctx.fill();
        }

        // Gold particles drifting
        if (!isMobile) {
            for (let i = 0; i < particles.length; i++) {
                const p = particles[i];
                ctx.beginPath();
                ctx.arc(p.x, p.y, p.radius, 0, Math.PI * 2);
                ctx.fillStyle = `rgba(255, 215, 100, ${p.alpha})`;
                ctx.fill();

                p.x += p.vx;
                p.y += p.vy;
                if (p.y > canvas.height) { p.y = 0; p.x = Math.random() * canvas.width; }
                if (p.x < 0) p.x = canvas.width;
                if (p.x > canvas.width) p.x = 0;
            }
        }

        // Shooting meteors
        createMeteor();
        for (let i = meteors.length - 1; i >= 0; i--) {
            const m = meteors[i];
            m.trail.push({ x: m.x, y: m.y });
            if (m.trail.length > 15) m.trail.shift();

            for (let j = 0; j < m.trail.length; j++) {
                const t = m.trail[j];
                const alpha = (j / m.trail.length) * 0.5;
                ctx.beginPath();
                ctx.arc(t.x, t.y, m.radius * (j / m.trail.length + 0.5), 0, Math.PI * 2);
                ctx.fillStyle = `rgba(255, 200, 80, ${alpha})`;
                ctx.fill();
            }

            ctx.beginPath();
            ctx.arc(m.x, m.y, m.radius, 0, Math.PI * 2);
            ctx.fillStyle = 'rgba(255, 220, 100, 0.9)';
            ctx.fill();

            m.x += m.vx;
            m.y += m.vy;
            m.life--;

            if (m.y > canvas.height || m.x < 0 || m.x > canvas.width || m.life <= 0) {
                meteors.splice(i, 1);
            }
        }
    }

    function loop() {
        if (reducedMotion) {
            drawFrame();
            return;
        }
        draw();
    }

    // Boot
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
