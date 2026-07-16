<script setup lang="ts">
import type { OpponentState } from '../../types/game';
import { CARD_COLOR_CLASSES, CLOTHING_TYPE_LABELS } from '../../types/game';

defineProps<{
    opponent: OpponentState;
}>();
</script>

<template>
    <div class="flex items-center gap-3 rounded-lg border border-border bg-card px-4 py-3">
        <span class="min-w-0 flex-1 truncate text-sm font-medium">{{ opponent.name }}</span>

        <!-- Held card slot -->
        <div
            v-if="opponent.picked_up_card"
            class="flex h-10 w-7 flex-col items-center justify-center rounded border-2 border-white/50 text-white shadow ring-2 ring-amber-400"
            :class="CARD_COLOR_CLASSES[opponent.picked_up_card.color]"
            :title="CLOTHING_TYPE_LABELS[opponent.picked_up_card.clothing_type]"
        >
            <span class="px-0.5 text-center text-[7px] font-bold leading-tight">{{ CLOTHING_TYPE_LABELS[opponent.picked_up_card.clothing_type] }}</span>
        </div>
        <div
            v-else
            class="flex h-10 w-7 items-center justify-center rounded border-2 border-dashed border-muted-foreground/30"
            title="No card held"
        />

        <div class="flex gap-1">
            <div
                v-for="pile in opponent.piles"
                :key="pile.id"
                class="h-7 w-5 rounded border-2 transition-colors"
                :class="pile.is_completed ? 'border-green-500 bg-green-400' : 'border-muted bg-muted/40'"
                :title="pile.is_completed ? 'Completed pile' : 'Pile in progress'"
            />
        </div>
        <span class="text-xs text-muted-foreground">{{ opponent.piles.filter((p) => p.is_completed).length }}/6</span>
    </div>
</template>