<script setup lang="ts">
import { computed, onBeforeUnmount, ref } from 'vue';
import { useGameStore } from '../../stores/game';
import { GameStatus } from '../../types/game';

/**
 * Forfeit lives in the app menu rather than beside the piles, so a fast tap
 * during play can't hit it by accident. The first press arms it; a second
 * press within three seconds confirms.
 */
defineProps<{ variant: 'header' | 'menu' }>();

const gameStore = useGameStore();

const isForfeitable = computed(() => gameStore.session?.status === GameStatus.Playing || gameStore.session?.status === GameStatus.Verifying);

const confirmingForfeit = ref(false);
let forfeitTimer: ReturnType<typeof setTimeout> | null = null;

function clearForfeitTimer() {
    if (forfeitTimer) {
        clearTimeout(forfeitTimer);
        forfeitTimer = null;
    }
}

function handleForfeit() {
    if (!confirmingForfeit.value) {
        confirmingForfeit.value = true;
        forfeitTimer = setTimeout(() => {
            confirmingForfeit.value = false;
        }, 3000);

        return;
    }

    clearForfeitTimer();
    confirmingForfeit.value = false;
    gameStore.forfeit();
}

onBeforeUnmount(clearForfeitTimer);
</script>

<template>
    <button
        v-if="isForfeitable"
        @click="handleForfeit"
        class="transition-colors active:scale-95"
        :class="[
            variant === 'header' ? 'rounded px-2 py-1 text-xs' : 'rounded-md border px-3 py-2 text-sm',
            confirmingForfeit
                ? 'border-red-500 bg-red-500 text-white'
                : variant === 'header'
                  ? 'text-muted-foreground hover:bg-muted hover:text-red-500'
                  : 'border-border text-red-600 hover:bg-muted dark:text-red-400',
        ]"
    >
        {{ confirmingForfeit ? 'Tap again to forfeit' : 'Forfeit game' }}
    </button>
</template>
