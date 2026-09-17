/**
 * T.N.S.V.T Sacred Glass - Sacred Sigil 3D Visualizer
 * Three.js Icosahedron wireframe with golden glow.
 *
 * Uses the `three` importmap entry (resolved to src/assets/third_party/three.module.js)
 * so the bundle is shipped locally and the page works offline (PWA).
 */
import * as THREE from 'three';

function initSigil() {
    const container = document.getElementById('sacred-sigil-container');
    if (!container) return;

    const width = container.clientWidth || 200;
    const height = container.clientHeight || 200;

    const scene = new THREE.Scene();
    const camera = new THREE.PerspectiveCamera(75, width / height, 0.1, 1000);
    const renderer = new THREE.WebGLRenderer({ alpha: true, antialias: true });
    renderer.setSize(width, height);
    renderer.setPixelRatio(window.devicePixelRatio);
    container.appendChild(renderer.domElement);

    // Outer wireframe icosahedron
    const geometry = new THREE.IcosahedronGeometry(1.5, 1);
    const material = new THREE.MeshPhongMaterial({
        color: 0xd4af37,
        wireframe: true,
        emissive: 0xd4af37,
        emissiveIntensity: 0.5,
        transparent: true,
        opacity: 0.8,
    });
    const sigil = new THREE.Mesh(geometry, material);
    scene.add(sigil);

    // Inner core
    const coreGeo = new THREE.IcosahedronGeometry(0.4, 0);
    const coreMat = new THREE.MeshBasicMaterial({
        color: 0xd4af37,
        transparent: true,
        opacity: 0.9,
    });
    const core = new THREE.Mesh(coreGeo, coreMat);
    scene.add(core);

    // Golden light
    const light = new THREE.PointLight(0xd4af37, 2, 10);
    light.position.set(2, 2, 2);
    scene.add(light);
    scene.add(new THREE.AmbientLight(0x404040));

    camera.position.z = 4;

    // P17: tap-to-pause (mobile). The loop also skips rendering
    // while the tab is hidden (battery) and resumes on return.
    let paused = false;
    function frame() {
        if (!paused && !document.hidden) {
            sigil.rotation.y += 0.005;
            sigil.rotation.z += 0.003;
            const scale = 1 + Math.sin(Date.now() * 0.002) * 0.1;
            core.scale.setScalar(scale);
            renderer.render(scene, camera);
        }
        requestAnimationFrame(frame);
    }
    container.style.cursor = 'pointer';
    container.setAttribute('role', 'button');
    container.setAttribute('tabindex', '0');
    container.setAttribute('aria-label', 'Pausar animación del sigilo');
    container.setAttribute('aria-pressed', 'false');
    function togglePause() {
        paused = !paused;
        container.setAttribute('aria-pressed', paused ? 'true' : 'false');
        container.setAttribute('aria-label', paused ? 'Reanudar animación del sigilo' : 'Pausar animación del sigilo');
        if (window.apiToast) {
            window.apiToast(paused ? 'Sigilo en pausa' : 'Sigilo en movimiento', 'info');
        }
    }
    container.addEventListener('click', togglePause);
    container.addEventListener('keydown', (e) => {
        if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); togglePause(); }
    });
    frame();

    window.addEventListener('resize', () => {
        const w = container.clientWidth || 200;
        const h = container.clientHeight || 200;
        camera.aspect = w / h;
        camera.updateProjectionMatrix();
        renderer.setSize(w, h);
    });
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initSigil);
} else {
    initSigil();
}
