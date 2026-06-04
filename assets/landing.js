// Three.js Background
let scene, camera, renderer, particles;

function initThree() {
    try {
        if (typeof THREE === 'undefined') {
            console.log('Three.js not loaded, skipping 3D background');
            return;
        }
        const canvas = document.querySelector('#canvas-3d');
        if (!canvas) return;

        scene = new THREE.Scene();
        camera = new THREE.PerspectiveCamera(75, window.innerWidth / window.innerHeight, 0.1, 1000);
        renderer = new THREE.WebGLRenderer({ canvas: canvas, antialias: true, alpha: true });
        renderer.setSize(window.innerWidth, window.innerHeight);
        renderer.setPixelRatio(window.devicePixelRatio);

        // Particles
        const particlesGeometry = new THREE.BufferGeometry();
        const particlesCount = 2000;
        const posArray = new Float32Array(particlesCount * 3);

        for (let i = 0; i < particlesCount * 3; i++) {
            posArray[i] = (Math.random() - 0.5) * 10;
        }

        particlesGeometry.setAttribute('position', new THREE.BufferAttribute(posArray, 3));

        const material = new THREE.PointsMaterial({
            size: 0.008,
            color: 0x6366f1, // Light mode primary (indigo)
            transparent: true,
            opacity: 0.4,
            blending: THREE.NormalBlending // Changed for light mode
        });

        particles = new THREE.Points(particlesGeometry, material);
        scene.add(particles);

        camera.position.z = 3;

        // Mouse Tracking
        document.addEventListener('mousemove', (event) => {
            const mouseX = (event.clientX / window.innerWidth) - 0.5;
            const mouseY = (event.clientY / window.innerHeight) - 0.5;
            
            if (typeof gsap !== 'undefined') {
                gsap.to(particles.rotation, {
                    y: mouseX * 0.4,
                    x: -mouseY * 0.4,
                    duration: 2
                });
            }
        });

        animate();
    } catch (e) {
        console.error('Three.js Init Error:', e);
    }
}

function animate() {
    requestAnimationFrame(animate);
    if (particles) {
        particles.rotation.y += 0.0005;
    }
    if (renderer && scene && camera) {
        renderer.render(scene, camera);
    }
}

// Window Resize
window.addEventListener('resize', () => {
    if (camera) {
        camera.aspect = window.innerWidth / window.innerHeight;
        camera.updateProjectionMatrix();
    }
    if (renderer) {
        renderer.setSize(window.innerWidth, window.innerHeight);
    }
});

// Scroll Reveal
function initReveal() {
    const reveals = document.querySelectorAll('.reveal');
    
    // Add the init class immediately to prepare for reveal
    reveals.forEach(el => el.classList.add('reveal-init'));

    const observerOptions = {
        threshold: 0.1,
        rootMargin: '0px 0px -50px 0px'
    };

    const observer = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                entry.target.classList.add('active');
            }
        });
    }, observerOptions);

    reveals.forEach(el => observer.observe(el));
}

// Initialize
document.addEventListener('DOMContentLoaded', () => {
    initThree();
    initReveal();
});
