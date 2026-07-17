<script setup lang="ts">
import type { Card, PlayerPile } from '../../types/game';
import { CARD_COLOR_CLASSES, CLOTHING_TYPE_LABELS } from '../../types/game';

const props = withDefaults(
    defineProps<{
        pile: PlayerPile;
        pendingCard: Card | null;
        disabled: boolean;
        bare?: boolean;
    }>(),
    { bare: false },
);

const emit = defineEmits<{
    pending: [card: Card];
    pickup: [card: Card];
}>();

function onCardClick(card: Card) {
    if (props.disabled) {
        return;
    }
    if (props.pendingCard?.id === card.id) {
        emit('pickup', card);
    } else {
        emit('pending', card);
    }
}
</script>

<template>
    <div :class="bare ? '' : 'rounded-xl border border-primary/30 bg-card p-4 shadow-sm'">
        <p v-if="!bare" class="mb-3 text-xs font-semibold uppercase tracking-wider text-muted-foreground">
            Pile {{ pile.pile_index + 1 }} — click a card once to select, again to pick it up
        </p>
        <p v-else class="mb-2 text-[10px] text-muted-foreground">Tap to select, tap again to pick up</p>
        <div class="flex flex-wrap justify-center gap-2 sm:gap-3">
            <button
                v-for="card in pile.cards"
                :key="card.id"
                @click="onCardClick(card)"
                :disabled="disabled"
                class="flex h-24 w-16 flex-col items-center justify-center rounded-xl border-2 text-white shadow transition-all active:scale-95 sm:h-20 sm:w-14"
                :class="[
                    CARD_COLOR_CLASSES[card.color],
                    disabled ? 'cursor-default opacity-60' : 'cursor-pointer hover:scale-105',
                    pendingCard?.id === card.id
                        ? 'scale-110 ring-4 ring-white ring-offset-2 ring-offset-card'
                        : '',
                ]"
                :title="CLOTHING_TYPE_LABELS[card.clothing_type]"
            >
                <span class="px-1 text-center text-[11px] font-bold leading-tight sm:text-[10px]">{{ CLOTHING_TYPE_LABELS[card.clothing_type] }}</span>
                <span v-if="pendingCard?.id === card.id" class="mt-1 text-[9px] font-semibold opacity-90">Pick up?</span>
            </button>
        </div>
    </div>
</template>
