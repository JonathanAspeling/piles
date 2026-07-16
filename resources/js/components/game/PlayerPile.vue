<script setup lang="ts">
import type { PlayerPile } from '../../types/game';

const props = defineProps<{
    pile: PlayerPile;
    isActive: boolean;
    disabled: boolean;
}>();

const emit = defineEmits<{
    open: [pileId: number];
}>();

function onClick() {
    if (props.disabled || props.pile.is_completed) {
        return;
    }
    emit('open', props.pile.id);
}
</script>

<template>
    <button
        @click="onClick"
        :disabled="disabled || pile.is_completed"
        class="relative flex flex-col items-center justify-center rounded-xl border-2 p-3 transition-all"
        :class="[
            pile.is_completed
                ? 'border-green-500 bg-green-50 dark:bg-green-950/20'
                : isActive
                  ? 'border-primary bg-primary/10 shadow-md'
                  : 'border-border bg-card hover:border-primary/50 hover:bg-muted/50',
            disabled && !pile.is_completed ? 'opacity-50' : '',
        ]"
    >
        <span class="mb-1 text-xs font-medium text-muted-foreground">Pile {{ pile.pile_index + 1 }}</span>

        <!-- Face-down card stack -->
        <div v-if="!pile.is_completed" class="relative flex h-12 w-9 items-center justify-center">
            <div class="absolute left-1 top-1 h-10 w-7 rounded-md border-2 border-border bg-muted/60" />
            <div class="absolute left-0.5 top-0.5 h-10 w-7 rounded-md border-2 border-border bg-muted/40" />
            <div class="relative h-10 w-7 rounded-md border-2 border-border bg-muted/80 flex items-center justify-center">
                <span class="text-lg font-black text-muted-foreground/40 select-none">?</span>
            </div>
        </div>

        <span v-if="pile.is_completed" class="rounded-full bg-green-500 px-2 py-0.5 text-xs font-bold text-white">✓ Done</span>
        <span v-else class="mt-1 text-xs text-muted-foreground">{{ pile.cards.length }} cards</span>
    </button>
</template>
