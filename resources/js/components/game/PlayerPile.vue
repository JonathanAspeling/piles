<script setup lang="ts">
import { computed } from 'vue';
import type { PlayerPile } from '../../types/game';
import { CARD_COLOR_CLASSES, CLOTHING_TYPE_LABELS } from '../../types/game';
import CardArt from './CardArt.vue';

const props = defineProps<{
    pile: PlayerPile;
    isActive: boolean;
    disabled: boolean;
}>();

const emit = defineEmits<{
    open: [pileId: number];
}>();

const completedFan = computed(() => [...props.pile.cards].sort((a, b) => a.color - b.color));
const completedType = computed(() => props.pile.cards[0]?.clothing_type ?? null);

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
                ? 'cursor-default border-emerald-500 bg-emerald-50 dark:bg-emerald-950/20'
                : isActive
                  ? 'border-primary bg-primary/10 shadow-md'
                  : 'border-border bg-card hover:border-primary/50 hover:bg-muted/50',
            disabled && !pile.is_completed ? 'opacity-50' : '',
        ]"
        :title="pile.is_completed && completedType !== null ? `Completed: ${CLOTHING_TYPE_LABELS[completedType]}` : undefined"
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

        <!-- Completed: face-up fan of the four colours; desktop overlays the clothing silhouette -->
        <div v-else class="relative flex h-10 w-12 items-center justify-center sm:h-14 sm:w-16">
            <div
                v-for="(card, index) in completedFan"
                :key="card.id"
                class="absolute h-8 w-6 rounded-md border-2 sm:h-12 sm:w-8"
                :class="CARD_COLOR_CLASSES[card.color]"
                :style="{ transform: `translateX(${(index - 1.5) * 5}px)`, zIndex: index }"
            />
            <div
                v-if="completedType !== null"
                class="pointer-events-none absolute hidden h-10 w-10 text-white/95 drop-shadow-[0_1px_1px_rgba(0,0,0,0.5)] sm:flex sm:items-center sm:justify-center"
                :style="{ zIndex: 10 }"
            >
                <CardArt :type="completedType" />
            </div>
        </div>

        <span
            v-if="pile.is_completed"
            class="mt-0.5 rounded-full bg-emerald-500 px-1.5 py-0.5 text-[10px] font-bold text-white sm:mt-1 sm:px-2 sm:text-xs"
        >✓ Done</span>
        <span v-else class="mt-0.5 text-[9px] text-muted-foreground sm:mt-1 sm:text-xs">{{ pile.cards.length }} cards</span>
    </button>
</template>
