<script setup lang="ts">
import type { Card, PlayerPile } from '../../types/game';
import { CARD_COLOR_CLASSES, CLOTHING_TYPE_LABELS } from '../../types/game';

const props = defineProps<{
    pile: PlayerPile;
    selectedCard: Card | null;
    disabled: boolean;
}>();

const emit = defineEmits<{
    selectCard: [pileId: number, card: Card];
    deselectCard: [];
}>();

function onCardClick(card: Card) {
    if (props.disabled || props.pile.is_completed) {
        return;
    }
    if (props.selectedCard?.id === card.id) {
        emit('deselectCard');
    } else {
        emit('selectCard', props.pile.id, card);
    }
}
</script>

<template>
    <div
        class="flex flex-col gap-2 rounded-xl border-2 p-3 transition-colors"
        :class="pile.is_completed ? 'border-green-500 bg-green-50 dark:bg-green-950/20' : 'border-border bg-card'"
    >
        <div class="flex items-center justify-between">
            <span class="text-xs font-medium text-muted-foreground">Pile {{ pile.pile_index + 1 }}</span>
            <span v-if="pile.is_completed" class="rounded-full bg-green-500 px-2 py-0.5 text-xs font-bold text-white">✓ Done</span>
        </div>

        <div class="flex flex-wrap gap-1.5">
            <button
                v-for="card in pile.cards"
                :key="card.id"
                @click="onCardClick(card)"
                :disabled="disabled || pile.is_completed"
                class="flex h-16 w-12 flex-col items-center justify-center rounded-lg border-2 text-white shadow-sm transition-all"
                :class="[
                    CARD_COLOR_CLASSES[card.color],
                    pile.is_completed || disabled ? 'cursor-default opacity-70' : 'cursor-pointer hover:scale-105',
                    selectedCard?.id === card.id ? 'scale-110 ring-2 ring-white ring-offset-1' : '',
                ]"
                :title="CLOTHING_TYPE_LABELS[card.clothing_type]"
            >
                <span class="px-1 text-center text-[9px] font-bold leading-tight">{{ CLOTHING_TYPE_LABELS[card.clothing_type] }}</span>
            </button>
        </div>
    </div>
</template>