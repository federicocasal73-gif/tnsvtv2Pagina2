/**
 * T.N.S.V.T Gateway — Sacred Geometry 3D Hero
 * Three.js icosahedron (wireframe) + octahedron (solid core)
 * Counter-rotation + breathing scale. Requires Three.js loaded via CDN.
 */
(function () {
    'use strict';

    const container = document.getElementById('gw-emblem-3d');
    if (!container || typeof THREE === 'undefined') return;

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
        transparent: true,
        opacity: 0.3,
        emissive: 0xd4af37,
        emissiveIntensity: 0.2,
    });
    const icosahedron = new THREE.Mesh(icoGeo, icoMat);
    scene.add(icosahedron);

    /* ── Inner solid octahedron (core) ── */
    const octGeo = new THREE.OctahedronGeometry(0.8, 0);
    const octMat = new THREE.MeshPhongMaterial({
        color: 0xd4af37,
        emissive: 0xd4af37,
        emissiveIntensity: 1.0,
        shininess: 100,
    });
    const octahedron = new THREE.Mesh(octGeo, octMat);
    scene.add(octahedron);

    /* ── Lighting ── */
    const pointLight = new THREE.PointLight(0xf2ca50, 4, 20);
    pointLight.position.set(5, 5, 5);
    scene.add(pointLight);

    const ambientLight = new THREE.AmbientLight(0xffffff, 0.1);
    scene.add(ambientLight);

    /* ── Animation loop ── */
    let rafId = null;
    const clock = new THREE.Clock();

    function animate() {
        rafId = requestAnimationFrame(animate);
        const t = clock.getElapsedTime();

        icosahedron.rotation.y += 0.003;
        icosahedron.rotation.z += 0.001;

        octahedron.rotation.y -= 0.01;
        octahedron.rotation.x += 0.005;

        const breath = 1 + Math.sin(t) * 0.05;
        octahedron.scale.set(breath, breath, breath);

        renderer.render(scene, camera);
    }

    animate();

    /* ── Pause when hidden ── */
    document.addEventListener('visibilitychange', function () {
        if (document.hidden) {
            if (rafId !== null) { cancelAnimationFrame(rafId); rafId = null; }
        } else {
            if (rafId === null) animate();
        }
    });

    /* ── Resize ── */
    window.addEventListener('resize', function () {
        const w = container.clientWidth;
        const h = container.clientHeight;
        camera.aspect = w / h;
        camera.updateProjectionMatrix();
        renderer.setSize(w, h);
    });
})();
