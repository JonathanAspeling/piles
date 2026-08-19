<script setup lang="ts">
import { computed } from 'vue';
import { useGameStore } from '../../stores/game';
import type { OpponentState } from '../../types/game';
import { CLOTHING_TYPE_LABELS } from '../../types/game';
import CardArt from './CardArt.vue';

const props = defineProps<{
    opponent: OpponentState;
}>();

const gameStore = useGameStore();

const completedCount = computed(() => props.opponent.piles.filter((p) => p.is_completed).length);
const isOnline = computed(() => gameStore.connectedUserIds.includes(props.opponent.user_id));
</script>

<template>
    <!-- Mobile: compact snapping chip; desktop: full row -->
    <div
        class="flex shrink-0 snap-start items-center gap-2 rounded-lg border border-border bg-card px-2.5 py-2 sm:w-auto sm:shrink sm:gap-3 sm:px-4 sm:py-3"
    >
        <span
            class="h-1.5 w-1.5 shrink-0 rounded-full transition-colors sm:h-2 sm:w-2"
            :class="isOnline ? 'bg-emerald-500' : 'bg-muted-foreground/40'"
            :title="isOnline ? 'Online' : 'Disconnected'"
        />
        <span
            class="max-w-[6rem] truncate text-xs font-medium sm:min-w-0 sm:max-w-none sm:flex-1 sm:text-sm"
            :class="{ 'text-muted-foreground/70': !isOnline }"
        >
            {{ opponent.name }}
        </span>

        <!-- Desktop: individual pile indicators. Mobile: just the count. -->
        <div class="hidden gap-1 sm:flex">
            <div
                v-for="pile in opponent.piles"
                :key="pile.id"
                class="group relative h-7 w-5 rounded border-2 transition-colors"
                :class="pile.is_completed ? 'border-green-500 bg-green-400' : 'border-muted bg-muted/40'"
                :tabindex="pile.is_completed ? 0 : undefined"
                :aria-label="
                    pile.is_completed && pile.completed_type !== null
                        ? `Completed: ${CLOTHING_TYPE_LABELS[pile.completed_type]}`
                        : pile.is_completed
                          ? 'Completed pile'
                          : 'Pile in progress'
                "
                :title="pile.is_completed ? undefined : 'Pile in progress'"
            >
                <!-- Thumbnail: readable at a glance if your eyes are good. -->
                <CardArt
                    v-if="pile.is_completed && pile.completed_type !== null"
                    :type="pile.completed_type"
                    class="p-0.5 text-white drop-shadow-[0_1px_1px_rgba(0,0,0,0.45)]"
                />

                <!-- Zoom: a full-size card on hover or keyboard focus, for when it isn't.
                     Opens downward — opponent rows sit at the top of the board, so
                     upward would clip off-screen. -->
                <div
                    v-if="pile.is_completed && pile.completed_type !== null"
                    class="pointer-events-none absolute left-1/2 top-full z-50 mt-2 hidden -translate-x-1/2 flex-col items-center gap-1 group-hover:flex group-focus:flex"
                >
                    <div
                        class="flex h-28 w-20 items-center justify-center rounded-xl border-2 border-green-500 bg-green-400 p-2 text-white shadow-xl"
                    >
                        <CardArt :type="pile.completed_type" />
                    </div>
                    <span class="whitespace-nowrap rounded bg-foreground px-1.5 py-0.5 text-[10px] font-medium text-background shadow">
                        {{ CLOTHING_TYPE_LABELS[pile.completed_type] }}
                    </span>
                </div>
            </div>
        </div>

        <span
            class="rounded-full px-1.5 py-0.5 text-[10px] font-bold sm:ml-0 sm:bg-transparent sm:px-0 sm:text-xs sm:font-normal sm:text-muted-foreground"
            :class="completedCount === 6 ? 'bg-emerald-500 text-white' : 'bg-muted text-muted-foreground'"
        >
            {{ completedCount }}/6
        </span>
    </div>
</template>
