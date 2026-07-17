<script setup lang="ts">
import { computed } from 'vue';
import type { OpponentState } from '../../types/game';
import { CARD_COLOR_CLASSES, CLOTHING_TYPE_LABELS } from '../../types/game';

const props = defineProps<{
    opponent: OpponentState;
}>();

const completedCount = computed(() => props.opponent.piles.filter((p) => p.is_completed).length);
</script>

<template>
    <!-- Mobile: compact snapping chip; desktop: full row -->
    <div
        class="flex shrink-0 snap-start items-center gap-2 rounded-lg border border-border bg-card px-2.5 py-2 sm:w-auto sm:shrink sm:gap-3 sm:px-4 sm:py-3"
    >
        <span class="max-w-[6rem] truncate text-xs font-medium sm:min-w-0 sm:max-w-none sm:flex-1 sm:text-sm">
            {{ opponent.name }}
        </span>

        <!-- Held card slot -->
        <div
            v-if="opponent.picked_up_card"
            class="flex h-8 w-6 flex-col items-center justify-center rounded border-2 border-white/50 text-white shadow ring-2 ring-amber-400 sm:h-10 sm:w-7"
            :class="CARD_COLOR_CLASSES[opponent.picked_up_card.color]"
            :title="CLOTHING_TYPE_LABELS[opponent.picked_up_card.clothing_type]"
        >
            <span class="px-0.5 text-center text-[7px] font-bold leading-tight">{{ CLOTHING_TYPE_LABELS[opponent.picked_up_card.clothing_type] }}</span>
        </div>
        <div
            v-else
            class="flex h-8 w-6 items-center justify-center rounded border-2 border-dashed border-muted-foreground/30 sm:h-10 sm:w-7"
            title="No card held"
        />

        <!-- Desktop: individual pile indicators. Mobile: just the count. -->
        <div class="hidden gap-1 sm:flex">
            <div
                v-for="pile in opponent.piles"
                :key="pile.id"
                class="h-7 w-5 rounded border-2 transition-colors"
                :class="pile.is_completed ? 'border-green-500 bg-green-400' : 'border-muted bg-muted/40'"
                :title="pile.is_completed ? 'Completed pile' : 'Pile in progress'"
            />
        </div>

        <span
            class="rounded-full px-1.5 py-0.5 text-[10px] font-bold sm:ml-0 sm:bg-transparent sm:px-0 sm:text-xs sm:font-normal sm:text-muted-foreground"
            :class="completedCount === 6 ? 'bg-emerald-500 text-white' : 'bg-muted text-muted-foreground'"
        >
            {{ completedCount }}/6
        </span>
    </div>
</template>
