<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';

const props = defineProps<{
    endsAt: string | null;
}>();

const emit = defineEmits<{ ended: [] }>();

const secondsLeft = ref(0);
let timer: ReturnType<typeof setInterval> | null = null;
let ended = false;

function tick() {
    if (!props.endsAt) {
        return;
    }
    const ms = new Date(props.endsAt).getTime() - Date.now();
    secondsLeft.value = Math.max(0, Math.ceil(ms / 1000));

    if (ms <= 0 && !ended) {
        ended = true;
        setTimeout(() => emit('ended'), 600);
    }
}

function startTimer() {
    ended = false;
    tick();
    timer = setInterval(tick, 250);
}

function stopTimer() {
    if (timer) {
        clearInterval(timer);
        timer = null;
    }
}

onMounted(() => {
    if (props.endsAt) {
        startTimer();
    }
});

onUnmounted(stopTimer);

watch(
    () => props.endsAt,
    (val) => {
        stopTimer();
        if (val) {
            startTimer();
        }
    },
);

const display = computed(() => (secondsLeft.value > 0 ? String(secondsLeft.value) : 'GO!'));
</script>

<template>
    <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/70 backdrop-blur-sm">
        <div class="flex flex-col items-center gap-6 text-white">
            <template v-if="endsAt">
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
