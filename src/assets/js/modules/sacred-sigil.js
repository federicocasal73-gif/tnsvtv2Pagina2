/**
 * T.N.S.V.T Sacred Glass - Sacred Sigil 3D Visualizer
 * Three.js Icosahedron wireframe with golden glow
 */
(function() {
    'use strict';

    function initSigil() {
        const container = document.getElementById('sacred-sigil-container');
        if (!container || typeof THREE === 'undefined') return;

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
            opacity: 0.8
        });
        const sigil = new THREE.Mesh(geometry, material);
        scene.add(sigil);

        // Inner core
        const coreGeo = new THREE.IcosahedronGeometry(0.4, 0);
        const coreMat = new THREE.MeshBasicMaterial({
            color: 0xd4af37,
            transparent: true,
            opacity: 0.9
        });
        const core = new THREE.Mesh(coreGeo, coreMat);
        scene.add(core);

        // Golden light
        const light = new THREE.PointLight(0xd4af37, 2, 10);
        light.position.set(2, 2, 2);
        scene.add(light);
        scene.add(new THREE.AmbientLight(0x404040));

        camera.position.z = 4;

        function animate() {
            requestAnimationFrame(animate);
            sigil.rotation.y += 0.005;
            sigil.rotation.z += 0.003;
            const scale = 1 + Math.sin(Date.now() * 0.002) * 0.1;
            core.scale.setScalar(scale);
            renderer.render(scene, camera);
        }
        animate();

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
})();
