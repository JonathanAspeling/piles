<script setup lang="ts">
import { computed } from 'vue';
import { useGameStore } from '../../stores/game';
import type { OpponentState } from '../../types/game';
import { CardColor, CardTheme, CLOTHING_TYPE_LABELS } from '../../types/game';
import { completedSetFaceClasses } from '../../utils/cardArt';
import CardArt from './CardArt.vue';

const props = defineProps<{
    player: OpponentState;
    theme: CardTheme;
    isSelf?: boolean;
}>();

const gameStore = useGameStore();

const completedCount = computed(() => props.player.piles.filter((p) => p.is_completed).length);
const isOnline = computed(() => gameStore.connectedUserIds.includes(props.player.user_id));

// A completed set stands for all four colours; the illustrated themes show it
// in its first colourway (classic's silhouette is colourless anyway).
const COMPLETED_SET_COLOR = CardColor.Red;
</script>

<template>
    <!-- Mobile: compact snapping chip; desktop: full row -->
    <div
        class="flex shrink-0 snap-start items-center gap-2 rounded-lg border bg-card px-2.5 py-2 sm:w-auto sm:shrink sm:gap-3 sm:px-4 sm:py-3"
        :class="isSelf ? 'border-primary/60 bg-primary/5' : 'border-border'"
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
            {{ player.name }}
            <span v-if="isSelf" class="ml-1 rounded bg-primary/15 px-1 py-0.5 text-[9px] font-bold uppercase tracking-wide text-primary sm:text-[10px]">
                You
            </span>
        </span>

        <!-- Desktop: individual pile indicators. Mobile: just the count. -->
        <div class="hidden gap-1 sm:flex">
            <div
                v-for="pile in player.piles"
                :key="pile.id"
                class="group relative h-7 w-5 rounded border-2 transition-colors"
                :class="pile.is_completed ? completedSetFaceClasses(theme) : 'border-muted bg-muted/40'"
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
                    :color="COMPLETED_SET_COLOR"
                    :theme="theme"
                    class="p-0.5"
                    :class="theme === CardTheme.Classic ? 'drop-shadow-[0_1px_1px_rgba(0,0,0,0.45)]' : ''"
                />

                <!-- Zoom: a full-size card on hover or keyboard focus, for when it isn't.
                     Opens downward — opponent rows sit at the top of the board, so
                     upward would clip off-screen. -->
                <div
                    v-if="pile.is_completed && pile.completed_type !== null"
                    class="pointer-events-none absolute left-1/2 top-full z-50 mt-2 hidden -translate-x-1/2 flex-col items-center gap-1 group-hover:flex group-focus:flex"
                >
                    <div
                        class="flex h-28 w-20 items-center justify-center rounded-xl border-2 p-2 shadow-xl"
                        :class="completedSetFaceClasses(theme)"
                    >
                        <CardArt :type="pile.completed_type" :color="COMPLETED_SET_COLOR" :theme="theme" />
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
