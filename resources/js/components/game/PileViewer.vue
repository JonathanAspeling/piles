<script setup lang="ts">
import type { Card, PlayerPile } from '../../types/game';
import { CARD_COLOR_CLASSES, CLOTHING_TYPE_LABELS } from '../../types/game';
import CardArt from './CardArt.vue';

const props = withDefaults(
    defineProps<{
        pile: PlayerPile;
        disabled: boolean;
        selectedCardId: number | null;
        bare?: boolean;
    }>(),
    { bare: false },
);

const emit = defineEmits<{
    select: [card: Card];
}>();

function onCardClick(card: Card) {
    if (props.disabled || props.pile.is_completed) {
        return;
    }
    emit('select', card);
}
</script>

<template>
    <div :class="bare ? '' : 'rounded-xl border border-primary/30 bg-card p-4 shadow-sm'">
        <p v-if="!bare" class="mb-3 text-xs font-semibold uppercase tracking-wider text-muted-foreground">
            Pile {{ pile.pile_index + 1 }} —
            <template v-if="selectedCardId !== null">tap a center pile to swap</template>
            <template v-else>tap a card to pick it up</template>
        </p>
        <p v-else class="mb-2 text-[10px] text-muted-foreground">
            <template v-if="selectedCardId !== null">Tap a center pile to swap</template>
            <template v-else>Tap a card to pick it up</template>
        </p>
        <div class="flex flex-wrap items-end justify-center gap-2 pt-5 sm:gap-3">
            <button
                v-for="card in pile.cards"
                :key="card.id"
                @click="onCardClick(card)"
                :disabled="disabled || pile.is_completed"
                class="group relative flex h-24 w-16 items-center justify-center overflow-hidden rounded-xl border-2 p-1.5 text-white shadow transition-all active:scale-95 sm:h-20 sm:w-14"
                :class="[
                    CARD_COLOR_CLASSES[card.color],
                    disabled ? 'cursor-default opacity-60' : 'cursor-pointer hover:scale-105',
                    card.id === selectedCardId ? 'z-10 -translate-y-3 shadow-lg ring-2 ring-amber-400 sm:-translate-y-4' : '',
                ]"
                :title="CLOTHING_TYPE_LABELS[card.clothing_type]"
            >
                <CardArt :type="card.clothing_type" />

                <!-- Marked for discard; the label names the way out, since tapping
                     the card again clears the pick. Mobile has no hover, so the
                     idle label has to stand alone there. -->
                <span
                    v-if="card.id === selectedCardId"
                    class="pointer-events-none absolute inset-x-0 top-1/2 -translate-y-1/2 bg-black/55 py-0.5 text-[9px] font-bold uppercase tracking-wide text-white"
                >
                    <span class="sm:group-hover:hidden">Cancel?</span>
                    <span class="hidden sm:group-hover:inline">Yes</span>
                </span>
            </button>
        </div>
    </div>
</template>
