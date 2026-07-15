<script setup lang="ts">
import type { OpponentState } from '../../types/game';

defineProps<{
    opponent: OpponentState;
}>();
</script>

<template>
    <div class="flex items-center gap-3 rounded-lg border border-border bg-card px-4 py-3">
        <span class="min-w-0 flex-1 truncate text-sm font-medium">{{ opponent.name }}</span>
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