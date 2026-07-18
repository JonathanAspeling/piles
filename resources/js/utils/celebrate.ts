import confetti from 'canvas-confetti';

const base = {
    particleCount: 90,
    spread: 70,
    startVelocity: 45,
    ticks: 220,
    zIndex: 100,
};

let intervalHandle: ReturnType<typeof setInterval> | null = null;

function fireBurst(): void {
    confetti({ ...base, origin: { x: 0.15, y: 0.7 }, angle: 60 });
    confetti({ ...base, origin: { x: 0.85, y: 0.7 }, angle: 120 });
    setTimeout(() => {
        confetti({ ...base, particleCount: 60, origin: { x: 0.5, y: 0.6 }, spread: 100 });
    }, 250);
}

export function celebrate(): void {
    stopCelebrating();
    fireBurst();
    intervalHandle = setInterval(fireBurst, 2000);
}

export function stopCelebrating(): void {
    if (intervalHandle) {
        clearInterval(intervalHandle);
        intervalHandle = null;
    }
    confetti.reset();
}
