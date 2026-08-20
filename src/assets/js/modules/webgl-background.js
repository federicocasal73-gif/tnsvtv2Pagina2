/**
 * T.N.S.V.T Sacred Glass - WebGL Background Shader
 * Perlin noise with golden glow and stars
 *
 * FASE 0: Reads --bg-stars-density and --bg-stars-opacity from CSS custom
 * properties so the same module can power both the gateway (full) and the
 * Sanctum shell (subtle).
 */
(function() {
    'use strict';

    const canvas = document.createElement('canvas');
    canvas.id = 'bg-shader-canvas';
    canvas.style.cssText = 'position:fixed;top:0;left:0;width:100%;height:100%;z-index:-1;pointer-events:none;';
    document.body.prepend(canvas);

    const gl = canvas.getContext('webgl') || canvas.getContext('experimental-webgl');
    if (!gl) return;

    // Read CSS custom properties so the same shader can be tuned per view.
    const rootStyles = getComputedStyle(document.documentElement);
    const cssDensity = parseFloat(rootStyles.getPropertyValue('--bg-stars-density')) || 30;
    const cssOpacity = parseFloat(rootStyles.getPropertyValue('--bg-stars-opacity')) || 0.6;
    const cssGoldRaw = rootStyles.getPropertyValue('--bg-stars-color').trim() || '#f2ca50';

    // Convert hex color to rgb vec3
    function hexToRgb(hex) {
        let h = hex.replace('#', '');
        if (h.length === 3) h = h.split('').map(c => c + c).join('');
        const num = parseInt(h, 16);
        return [
            ((num >> 16) & 255) / 255,
            ((num >> 8) & 255) / 255,
            (num & 255) / 255
        ];
    }
    const goldRgb = hexToRgb(cssGoldRaw);

    const vertexSrc = `
        attribute vec2 position;
        varying vec2 v_texCoord;
        void main() {
            v_texCoord = position * 0.5 + 0.5;
            gl_Position = vec4(position, 0.0, 1.0);
        }
    `;

    const fragmentSrc = `
        precision highp float;
        uniform float u_time;
        uniform vec2 u_resolution;
        uniform float u_density;
        uniform vec3 u_gold;
        varying vec2 v_texCoord;

        vec3 permute(vec3 x) { return mod(((x*34.0)+1.0)*x, 289.0); }
        float snoise(vec2 v){
            const vec4 C = vec4(0.211324865405187, 0.366025403784439, -0.577350269189626, 0.024390243902439);
            vec2 i  = floor(v + dot(v, C.yy));
            vec2 x0 = v - i + dot(i, C.xx);
            vec2 i1 = (x0.x > x0.y) ? vec2(1.0, 0.0) : vec2(0.0, 1.0);
            vec4 x12 = x0.xyxy + C.xxzz;
            x12.xy -= i1;
            i = mod(i, 289.0);
            vec3 p = permute(permute(i.y + vec3(0.0, i1.y, 1.0)) + i.x + vec3(0.0, i1.x, 1.0));
            vec3 m = max(0.5 - vec3(dot(x0,x0), dot(x12.xy,x12.xy), dot(x12.zw,x12.zw)), 0.0);
            m = m*m; m = m*m;
            vec3 x = 2.0 * fract(p * C.www) - 1.0;
            vec3 h = abs(x) - 0.5;
            vec3 a0 = x - floor(x + 0.5);
            vec3 g;
            g.x = a0.x * x0.x + h.x * x0.y;
            g.yz = a0.yz * x12.xz + h.yz * x12.yw;
            return 130.0 * dot(m, g);
        }

        void main() {
            vec2 uv = v_texCoord;
            vec2 p = uv * 2.0 - 1.0;
            p.x *= u_resolution.x / u_resolution.y;

            float noise = snoise(p * 1.2 + u_time * 0.04);
            float noise2 = snoise(p * 2.5 - u_time * 0.06);

            vec3 color1 = vec3(0.031, 0.023, 0.047);  // warm deep purple
            vec3 color2 = vec3(0.086, 0.067, 0.129);  // #161121
            vec3 gold = u_gold;

            vec3 base = mix(color1, color2, noise * 0.5 + 0.5);
            float goldGlow = smoothstep(0.4, 1.0, noise2 * noise);
            vec3 finalColor = mix(base, gold * 0.08, goldGlow);

            // Stars tuned per-page via --bg-stars-density
            float stars = pow(max(0.0, snoise(p * u_density)), 25.0);
            finalColor += stars * gold * 0.5;

            gl_FragColor = vec4(finalColor, 1.0);
        }
    `;

    function createShader(type, source) {
        const shader = gl.createShader(type);
        gl.shaderSource(shader, source);
        gl.compileShader(shader);
        if (!gl.getShaderParameter(shader, gl.COMPILE_STATUS)) {
            console.warn('Shader compile error:', gl.getShaderInfoLog(shader));
            return null;
        }
        return shader;
    }

    const vs = createShader(gl.VERTEX_SHADER, vertexSrc);
    const fs = createShader(gl.FRAGMENT_SHADER, fragmentSrc);
    if (!vs || !fs) return;

    const program = gl.createProgram();
    gl.attachShader(program, vs);
    gl.attachShader(program, fs);
    gl.linkProgram(program);
    gl.useProgram(program);

    const buffer = gl.createBuffer();
    gl.bindBuffer(gl.ARRAY_BUFFER, buffer);
    gl.bufferData(gl.ARRAY_BUFFER, new Float32Array([-1, -1, 1, -1, -1, 1, -1, 1, 1, -1, 1, 1]), gl.STATIC_DRAW);

    const posLoc = gl.getAttribLocation(program, 'position');
    gl.enableVertexAttribArray(posLoc);
    gl.vertexAttribPointer(posLoc, 2, gl.FLOAT, false, 0, 0);

    const timeLoc = gl.getUniformLocation(program, 'u_time');
    const resLoc = gl.getUniformLocation(program, 'u_resolution');
    const densityLoc = gl.getUniformLocation(program, 'u_density');
    const goldLoc = gl.getUniformLocation(program, 'u_gold');

    let rafId = null;
    let cachedW = 0;
    let cachedH = 0;
    let cachedDensity = 0;
    let cachedGold = [0, 0, 0];

    function resize() {
        const w = window.innerWidth;
        const h = window.innerHeight;
        if (w === cachedW && h === cachedH) return;
        cachedW = w;
        cachedH = h;
        canvas.width = w;
        canvas.height = h;
    }

    function render(time) {
        time *= 0.001;
        resize();
        gl.viewport(0, 0, canvas.width, canvas.height);
        gl.uniform1f(timeLoc, time);
        gl.uniform2f(resLoc, canvas.width, canvas.height);
        // Re-read CSS props each frame so live tuning works.
        const liveDensity = parseFloat(getComputedStyle(document.documentElement).getPropertyValue('--bg-stars-density')) || cssDensity;
        if (liveDensity !== cachedDensity) {
            cachedDensity = liveDensity;
            gl.uniform1f(densityLoc, liveDensity);
        }
        gl.uniform3f(goldLoc, goldRgb[0], goldRgb[1], goldRgb[2]);
        gl.drawArrays(gl.TRIANGLES, 0, 6);
        rafId = requestAnimationFrame(render);
    }

    // Push initial values
    gl.uniform1f(densityLoc, cssDensity);
    gl.uniform3f(goldLoc, goldRgb[0], goldRgb[1], goldRgb[2]);

    // Apply opacity from CSS
    canvas.style.opacity = String(cssOpacity);

    // Pause when tab is hidden to save GPU/battery
    document.addEventListener('visibilitychange', () => {
        if (document.hidden) {
            if (rafId !== null) {
                cancelAnimationFrame(rafId);
                rafId = null;
            }
        } else {
            if (rafId === null) {
                rafId = requestAnimationFrame(render);
            }
        }
    });

    window.addEventListener('resize', resize);

    requestAnimationFrame(render);
})();
