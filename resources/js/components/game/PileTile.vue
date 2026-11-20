<script setup lang="ts">
import { computed } from 'vue';
import type { PlayerPile } from '../../types/game';
import { CardTheme, CLOTHING_TYPE_LABELS } from '../../types/game';
import type { CardStyle } from '../../utils/cardArt';
import { completedSetFaceClasses } from '../../utils/cardArt';
import CardArt from './CardArt.vue';

/**
 * A slim tab for one of your piles: its number, plus a face-down back or the
 * finished set's art. Its cards are shown in the "Your cards" row above, not
 * here, so the tile only has to say which pile is which and which are done.
 */
const props = defineProps<{
    pile: PlayerPile;
    cardStyle: CardStyle;
    isOpen: boolean;
    disabled: boolean;
    reorderMode: boolean;
    isReorderSelected: boolean;
}>();

const emit = defineEmits<{
    open: [pileId: number];
}>();

const completedCard = computed(() => (props.pile.is_completed ? (props.pile.cards[0] ?? null) : null));
// A completed pile is normally inert, but rearranging moves the slot rather
// than its cards, so it has to be selectable while reorder mode is armed.
const isInert = computed(() => props.disabled || (props.pile.is_completed && !props.reorderMode));
const isRaised = computed(() => (props.isOpen && !props.reorderMode) || props.isReorderSelected);

const label = computed(() => {
    const name = `Pile ${props.pile.pile_index + 1}`;

    return completedCard.value ? `${name}, complete: ${CLOTHING_TYPE_LABELS[completedCard.value.clothing_type]}` : name;
});

function onClick() {
    if (!isInert.value) {
        emit('open', props.pile.id);
    }
}
</script>

<template>
    <button
        type="button"
        :disabled="isInert"
        :aria-label="label"
        :aria-pressed="isOpen"
        class="flex h-[60px] flex-col items-center justify-center gap-1 rounded-[10px] border-2 transition-[transform,box-shadow,border-color,background-color] duration-150 active:scale-95 md:h-[72px] md:flex-row md:gap-3"
        :class="[
            pile.is_completed
                ? 'border-[#0E9C6B] bg-[#E5F4ED] dark:bg-[rgba(14,156,107,0.16)]'
                : isRaised
                  ? 'border-[#F2B42B] bg-[#FDF4DC] dark:bg-[rgba(242,180,43,0.14)]'
                  : 'border-border bg-card hover:border-[#F2B42B]/60',
            isRaised ? '-translate-y-1 shadow-[0_8px_18px_rgba(0,0,0,0.12)]' : '',
            reorderMode ? 'border-dashed' : '',
            pile.is_completed && !reorderMode ? 'cursor-default' : '',
            disabled && !pile.is_completed ? 'opacity-50' : '',
        ]"
        @click="onClick"
    >
        <span
            class="text-[13px] font-bold md:text-base"
            :class="pile.is_completed ? 'text-[#0E9C6B]' : isRaised ? 'text-foreground' : 'text-muted-foreground'"
        >
            {{ pile.pile_index + 1 }}
        </span>

        <span
            v-if="completedCard"
            class="flex h-7 w-7 items-center justify-center rounded md:h-10 md:w-10"
            :class="cardStyle.theme === CardTheme.Classic ? completedSetFaceClasses(cardStyle.theme) : ''"
        >
            <CardArt :type="completedCard.clothing_type" :color="completedCard.color" :theme="cardStyle.theme" />
        </span>
        <span v-else class="pile-back h-7 w-[22px] rounded md:h-10 md:w-[30px]" :class="isRaised ? 'shadow-[0_0_0_2px_#F2B42B]' : ''" />
    </button>
</template>

<style scoped>
.pile-back {
    background: repeating-linear-gradient(45deg, #2a2926 0 4px, #3a3935 4px 8px);
}

:global(.dark) .pile-back {
    background: repeating-linear-gradient(45deg, #2c2c31 0 4px, #38383e 4px 8px);
}
</style>
