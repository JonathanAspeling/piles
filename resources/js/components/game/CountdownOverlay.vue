<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref } from 'vue';

const props = defineProps<{
    endsAt: string;
}>();

const secondsLeft = ref(0);
let timer: ReturnType<typeof setInterval> | null = null;

function tick() {
    const ms = new Date(props.endsAt).getTime() - Date.now();
    secondsLeft.value = Math.max(0, Math.ceil(ms / 1000));
}

onMounted(() => {
    tick();
    timer = setInterval(tick, 250);
});

onUnmounted(() => {
    if (timer) {
        clearInterval(timer);
    }
});

const display = computed(() => (secondsLeft.value > 0 ? String(secondsLeft.value) : 'GO!'));
</script>

<template>
    <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/70 backdrop-blur-sm">
        <div class="flex flex-col items-center gap-4 text-white">
            <p class="text-xl font-semibold tracking-wide">Game starting in…</p>
            <span class="text-9xl font-black tabular-nums">{{ display }}</span>
        </div>
    </div>
</template>