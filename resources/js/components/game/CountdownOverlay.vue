<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';

const props = defineProps<{
    durationMs: number | null;
    startedAtLocalMs: number | null;
}>();

const emit = defineEmits<{ ended: [] }>();

const msLeft = ref(0);
let timer: ReturnType<typeof setInterval> | null = null;
let ended = false;

function tick() {
    if (props.durationMs === null || props.startedAtLocalMs === null) {
        return;
    }
    const elapsed = Date.now() - props.startedAtLocalMs;
    msLeft.value = Math.max(0, props.durationMs - elapsed);

    if (msLeft.value <= 0 && !ended) {
        ended = true;
        setTimeout(() => emit('ended'), 600);
    }
}

function startTimer() {
    ended = false;
    tick();
    timer = setInterval(tick, 100);
}

function stopTimer() {
    if (timer) {
        clearInterval(timer);
        timer = null;
    }
}

onMounted(() => {
    if (props.durationMs !== null && props.startedAtLocalMs !== null) {
        startTimer();
    }
});

onUnmounted(stopTimer);

watch(
    () => [props.durationMs, props.startedAtLocalMs],
    ([dur, start]) => {
        stopTimer();
        if (dur !== null && start !== null) {
            startTimer();
        }
    },
);

const display = computed(() => {
    if (msLeft.value <= 0) {
        return 'GO!';
    }
    return String(Math.ceil(msLeft.value / 1000));
});
</script>

<template>
    <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/70 backdrop-blur-sm">
        <div class="flex flex-col items-center gap-6 text-white">
            <template v-if="durationMs !== null && startedAtLocalMs !== null">
                <p class="text-xl font-semibold tracking-wide">Game starting in…</p>
                <span class="text-9xl font-black tabular-nums">{{ display }}</span>
            </template>
            <template v-else>
                <p class="text-xl font-semibold tracking-wide">Get ready…</p>
                <div class="flex gap-2">
                    <span class="h-3 w-3 animate-bounce rounded-full bg-white [animation-delay:-0.3s]"></span>
                    <span class="h-3 w-3 animate-bounce rounded-full bg-white [animation-delay:-0.15s]"></span>
                    <span class="h-3 w-3 animate-bounce rounded-full bg-white"></span>
                </div>
            </template>
        </div>
    </div>
</template>
