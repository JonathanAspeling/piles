<script setup lang="ts">
import type { Card, CenterPile } from '../../types/game';
import { CARD_COLOR_CLASSES, CLOTHING_TYPE_LABELS } from '../../types/game';

const props = defineProps<{
    pile: CenterPile;
    hasCardInHand: boolean;
    disabled: boolean;
}>();

const emit = defineEmits<{
    swap: [centerPileId: number, centerCardId: number, expectedVersion: number];
}>();

function onPileClick() {
    if (!props.disabled && props.hasCardInHand && props.pile.top_card) {
        emit('swap', props.pile.id, props.pile.top_card.id, props.pile.version);
    }
}
</script>

<template>
    <button
        @click="onPileClick"
        :disabled="disabled || !hasCardInHand || !pile.top_card"
        class="flex flex-col items-center gap-2"
    >
        <!-- Card face -->
        <div
            v-if="pile.top_card"
            class="relative flex h-28 w-20 flex-col items-center justify-center rounded-xl border-2 p-2 text-center text-white shadow-md transition-all"
            :class="[
                CARD_COLOR_CLASSES[pile.top_card.color],
                hasCardInHand && !disabled ? 'cursor-pointer ring-2 ring-white ring-offset-2 hover:scale-105' : 'cursor-default',
            ]"
        >
            <span class="text-xs font-bold leading-tight">{{ CLOTHING_TYPE_LABELS[pile.top_card.clothing_type] }}</span>
        </div>

        <!-- Empty placeholder -->
        <div
            v-else
            class="flex h-28 w-20 items-center justify-center rounded-xl border-2 border-dashed border-muted bg-muted/20 text-xs text-muted-foreground"
        >
            Empty
        </div>

        <span class="text-xs text-muted-foreground">Center {{ pile.pile_index + 1 }}</span>
    </button>
</template>
