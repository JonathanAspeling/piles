<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref } from 'vue';

/**
 * Shown the moment your sixth pile completes. Players kept finishing and then
 * forgetting to call it, so the prompt takes over the board: tap PILES! to win
 * at once, or it calls it for you when the ring runs out. The server has
 * already locked all six piles by now, so the claim can't be wrong; the delay
 * only exists to keep the moment of calling it.
 */
const props = withDefaults(defineProps<{ autoClaimMs?: number }>(), { autoClaimMs: 3000 });

const emit = defineEmits<{ claim: [] }>();

const claimed = ref(false);
const button = ref<HTMLButtonElement | null>(null);
let timer: ReturnType<typeof setTimeout> | null = null;

function claim() {
    if (claimed.value) {
        return;
    }
    claimed.value = true;
    if (timer) {
        clearTimeout(timer);
    }
    emit('claim');
}

onMounted(() => {
    button.value?.focus();
    timer = setTimeout(claim, props.autoClaimMs);
    try {
        navigator.vibrate?.([40, 60, 40]);
    } catch {
        // Not supported: fine.
    }
});

onBeforeUnmount(() => {
    if (timer) {
        clearTimeout(timer);
    }
});
</script>

<template>
    <div
        role="dialog"
        aria-modal="true"
        aria-labelledby="claim-prompt-title"
        class="fixed inset-0 z-40 flex items-center justify-center bg-black/55 px-4 backdrop-blur-sm"
    >
        <div class="claim-pop flex flex-col items-center gap-5 text-center text-white">
            <h2 id="claim-prompt-title" class="text-xl font-black tracking-wide md:text-2xl">All six piles done!</h2>

            <div class="relative h-52 w-52 md:h-60 md:w-60">
                <!-- The ring runs down to the automatic call. -->
                <svg class="absolute inset-0 -rotate-90" viewBox="0 0 100 100" aria-hidden="true">
                    <circle cx="50" cy="50" r="47" fill="none" stroke="rgba(255,255,255,0.18)" stroke-width="4" />
                    <circle
                        class="claim-ring"
                        cx="50"
                        cy="50"
                        r="47"
                        fill="none"
                        stroke="#F2B42B"
                        stroke-width="4"
                        stroke-linecap="round"
                        pathLength="100"
                        :style="{ animationDuration: `${autoClaimMs}ms` }"
                    />
                </svg>
                <button
                    ref="button"
                    type="button"
                    class="claim-button absolute inset-3 rounded-full bg-[#0E9C6B] text-3xl font-black tracking-wider text-white shadow-[0_12px_40px_rgba(14,156,107,0.6)] transition-transform hover:bg-[#0B8A5E] active:scale-95 md:text-4xl"
                    @click="claim"
                >
                    PILES!
                </button>
            </div>

            <p class="text-sm text-white/80 md:text-base" aria-live="polite">
                {{ claimed ? 'Calling it…' : 'Tap to win, or we’ll call it for you in a moment.' }}
            </p>
        </div>
    </div>
</template>

<style scoped>
.claim-pop {
    animation: claim-pop 380ms cubic-bezier(0.34, 1.56, 0.64, 1) both;
}

.claim-ring {
    stroke-dasharray: 100;
    animation-name: claim-ring;
    animation-timing-function: linear;
    animation-fill-mode: forwards;
}

.claim-button {
    animation: claim-pulse 1.2s ease-in-out infinite;
}

@keyframes claim-pop {
    from {
        opacity: 0;
        transform: scale(0.8);
    }
}

@keyframes claim-ring {
    from {
        stroke-dashoffset: 0;
    }
    to {
        stroke-dashoffset: 100;
    }
}

@keyframes claim-pulse {
    0%,
    100% {
        box-shadow:
            0 12px 40px rgba(14, 156, 107, 0.6),
            0 0 0 0 rgba(14, 156, 107, 0.55);
    }
    50% {
        box-shadow:
            0 12px 40px rgba(14, 156, 107, 0.6),
            0 0 0 18px rgba(14, 156, 107, 0);
    }
}

/* The ring is information (time left), so it still runs; only the flourishes stop. */
@media (prefers-reduced-motion: reduce) {
    .claim-pop,
    .claim-button {
        animation: none;
    }
}
</style>
