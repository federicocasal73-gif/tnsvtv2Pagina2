/**
 * T.N.S.V.T Gateway — Sacred Geometry 3D Hero
 * Three.js icosahedron (wireframe) + octahedron (solid core).
 * Counter-rotation + breathing scale.
 *
 * Uses the `three` importmap entry (resolved to src/assets/third_party/three.module.js)
 * so the bundle is shipped locally and the gateway works offline (PWA).
 */
import * as THREE from 'three';

const container = document.getElementById('gw-emblem-3d');
if (container) {
    const width = container.clientWidth || 224;
    const height = container.clientHeight || 224;

    /* ── Scene ── */
    const scene = new THREE.Scene();

    const camera = new THREE.PerspectiveCamera(75, width / height, 0.1, 1000);
    camera.position.z = 5;

    const renderer = new THREE.WebGLRenderer({ alpha: true, antialias: true });
    renderer.setSize(width, height);
    renderer.setPixelRatio(Math.min(window.devicePixelRatio, 2));
    renderer.setClearColor(0x000000, 0);
    container.appendChild(renderer.domElement);

    /* ── Outer wireframe icosahedron (sacred geometry) ── */
    const icoGeo = new THREE.IcosahedronGeometry(1.8, 1);
    const icoMat = new THREE.MeshPhongMaterial({
        color: 0xd4af37,
        wireframe: true,
        emissive: 0xd4af37,
        emissiveIntensity: 0.4,
        transparent: true,
        opacity: 0.85,
    });
    const ico = new THREE.Mesh(icoGeo, icoMat);
    scene.add(ico);

    /* ── Inner solid core (octahedron) ── */
    const coreGeo = new THREE.OctahedronGeometry(0.55, 0);
    const coreMat = new THREE.MeshBasicMaterial({
        color: 0xffe7a0,
        transparent: true,
        opacity: 0.95,
    });
    const core = new THREE.Mesh(coreGeo, coreMat);
    scene.add(core);

    /* ── Lighting ── */
    const light = new THREE.PointLight(0xd4af37, 2.4, 12);
    light.position.set(2.5, 2.5, 3);
    scene.add(light);
    scene.add(new THREE.AmbientLight(0x404040));

    /* ── Animation loop ── */
    let t0 = performance.now();
    function frame(now) {
        const t = (now - t0) / 1000;
        ico.rotation.x = t * 0.18;
        ico.rotation.y = -t * 0.24;
        core.rotation.x = -t * 0.6;
        core.rotation.z = t * 0.5;
        const breathe = 1 + Math.sin(t * 1.4) * 0.06;
        core.scale.setScalar(breathe);
        renderer.render(scene, camera);
        requestAnimationFrame(frame);
    }
    requestAnimationFrame(frame);

    /* ── Resize ── */
    const ro = new ResizeObserver(() => {
        const w = container.clientWidth || 224;
        const h = container.clientHeight || 224;
        camera.aspect = w / h;
        camera.updateProjectionMatrix();
        renderer.setSize(w, h);
    });
    ro.observe(container);
}
