<script setup lang="ts">
import { computed } from 'vue';
import type { Card } from '../../types/game';
import { CLOTHING_TYPE_LABELS } from '../../types/game';
import type { CardStyle } from '../../utils/cardArt';
import { cardFaceClasses } from '../../utils/cardArt';
import CardArt from './CardArt.vue';

/**
 * One card slot on the board, the same size wherever it sits, so a pile's
 * cards line up column for column under the centre cards they're compared with.
 *
 * - idle: a plain face
 * - target: a centre card you can swap your lifted card for (amber glow)
 * - lifted: the card you've picked up (raised, amber ring)
 * - flash: a card that just arrived in a swap (green glow)
 * - hidden: keeps the slot while a flying copy of the card is mid-swap
 */
export type GameCardState = 'idle' | 'target' | 'lifted' | 'flash' | 'hidden';

const props = withDefaults(
    defineProps<{
        card: Card | null;
        cardStyle: CardStyle;
        state?: GameCardState;
        interactive?: boolean;
    }>(),
    { state: 'idle', interactive: false },
);

defineEmits<{
    select: [];
}>();

// Exactly one box-shadow per state: two competing shadow utilities would be
// settled by stylesheet order, not by intent.
const STATE_CLASSES: Record<GameCardState, string> = {
    idle: 'shadow-[0_1px_2px_rgba(20,18,14,0.08),0_6px_16px_rgba(20,18,14,0.10)] dark:shadow-[0_10px_22px_rgba(0,0,0,0.55)]',
    target: 'shadow-[0_0_0_3px_#F2B42B,0_0_26px_6px_rgba(242,180,43,0.55)] hover:-translate-y-1 dark:shadow-[0_0_0_3px_#F2B42B,0_0_26px_6px_rgba(242,180,43,0.45)]',
    lifted: 'z-10 -translate-y-3 shadow-[0_0_0_3px_#F2B42B,0_18px_30px_rgba(0,0,0,0.28)] dark:shadow-[0_0_0_3px_#F2B42B,0_18px_30px_rgba(0,0,0,0.5)]',
    flash: 'shadow-[0_0_0_3px_#0E9C6B,0_0_24px_6px_rgba(14,156,107,0.4)] dark:shadow-[0_0_0_3px_#0E9C6B,0_0_24px_6px_rgba(14,156,107,0.5)]',
    hidden: 'opacity-0',
};

const stateClasses = computed(() => STATE_CLASSES[props.state]);
</script>

<template>
    <button
        v-if="card"
        type="button"
        :disabled="!interactive"
        :data-card-id="card.id"
        :title="CLOTHING_TYPE_LABELS[card.clothing_type]"
        class="relative flex aspect-[33/43] w-full items-center justify-center rounded-[10px] border-[1.5px] px-[12%] py-[19%] transition-[transform,box-shadow,opacity] duration-150 md:rounded-[14px]"
        :class="[
            cardFaceClasses(card.clothing_type, card.color, cardStyle),
            interactive ? 'cursor-pointer active:scale-95' : 'cursor-default',
            stateClasses,
        ]"
        @click="$emit('select')"
    >
        <CardArt :type="card.clothing_type" :color="card.color" :theme="cardStyle.theme" />
    </button>

    <!-- Empty slot: keeps the row's shape whether or not a pile is open. -->
    <div
        v-else
        aria-hidden="true"
        class="aspect-[33/43] w-full rounded-[10px] border-[1.5px] border-dashed border-stone-300 dark:border-zinc-700 md:rounded-[14px]"
    />
</template>
