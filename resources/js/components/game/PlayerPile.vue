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
        class="relative flex flex-col items-center justify-center rounded-lg border-2 p-1.5 transition-all active:scale-95 sm:rounded-xl sm:p-3"
        :class="[
            pile.is_completed
                ? 'border-green-500 bg-green-50 dark:bg-green-950/20'
                : isActive
                  ? 'border-primary bg-primary/10 shadow-md'
                  : 'border-border bg-card hover:border-primary/50 hover:bg-muted/50',
            disabled && !pile.is_completed ? 'opacity-50' : '',
        ]"
    >
        <span class="mb-0.5 text-[9px] font-medium text-muted-foreground sm:mb-1 sm:text-xs">Pile {{ pile.pile_index + 1 }}</span>

        <!-- Face-down card stack -->
        <div v-if="!pile.is_completed" class="relative flex h-10 w-8 items-center justify-center sm:h-12 sm:w-9">
            <div class="absolute left-1 top-1 h-8 w-6 rounded-md border-2 border-border bg-muted/60 sm:h-10 sm:w-7" />
            <div class="absolute left-0.5 top-0.5 h-8 w-6 rounded-md border-2 border-border bg-muted/40 sm:h-10 sm:w-7" />
            <div class="relative flex h-8 w-6 items-center justify-center rounded-md border-2 border-border bg-muted/80 sm:h-10 sm:w-7">
                <span class="select-none text-base font-black text-muted-foreground/40 sm:text-lg">?</span>
            </div>
        </div>

        <span v-if="pile.is_completed" class="rounded-full bg-green-500 px-1.5 py-0.5 text-[10px] font-bold text-white sm:px-2 sm:text-xs">✓ Done</span>
        <span v-else class="mt-0.5 text-[9px] text-muted-foreground sm:mt-1 sm:text-xs">{{ pile.cards.length }} cards</span>
    </button>
</template>
