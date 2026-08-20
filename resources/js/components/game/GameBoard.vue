<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import type { Card, GameSession } from '../../types/game';
import { GameStatus } from '../../types/game';
import { useGameStore } from '../../stores/game';
import CenterPileComponent from './CenterPile.vue';
import PileViewer from './PileViewer.vue';
import PlayerScoreRow from './PlayerScoreRow.vue';
import PlayerPileComponent from './PlayerPile.vue';

defineProps<{
    game: GameSession;
}>();

const gameStore = useGameStore();

const activePileId = ref<number | null>(null);
const confirmingForfeit = ref(false);
let forfeitTimer: ReturnType<typeof setTimeout> | null = null;

const isDisabled = computed(() => gameStore.session?.status !== GameStatus.Playing);
const completedCount = computed(() => gameStore.myPiles.filter((p) => p.is_completed).length);
const activePile = computed(() => gameStore.myPiles.find((p) => p.id === activePileId.value) ?? null);
const readyToClaim = computed(() => completedCount.value === 6 && gameStore.session?.status === GameStatus.Playing);

// Close the pile viewer as soon as the pile it's showing becomes completed —
// the completing swap arrives asynchronously via broadcast.
watch(
    () => activePile.value?.is_completed,
    (completed) => {
        if (completed) {
            closePileViewer();
        }
    },
);

// A selection never survives leaving the pile it belongs to — every pile opens
// with all four cards unselected.
async function onOpenPile(pileId: number) {
    activePileId.value = activePileId.value === pileId ? null : pileId;
    await gameStore.releaseCard();
}

async function closePileViewer() {
    activePileId.value = null;
    await gameStore.releaseCard();
}

async function onSelectCard(card: Card) {
    if (!activePileId.value) {
        return;
    }
    if (gameStore.myPickedUpCard?.id === card.id) {
        await gameStore.releaseCard();
        return;
    }
    // Picking up a different card overwrites the selection server-side, so
    // there's no release round-trip when switching.
    await gameStore.pickUpCard(card.id, activePileId.value);
    // pile stays open — activePileId intentionally not cleared
}

async function onSwap(centerPileId: number, centerCardId: number, expectedVersion: number) {
    await gameStore.swapCard(centerPileId, centerCardId, expectedVersion);
}

async function claimPiles() {
    await gameStore.claimPiles();
}

function handleForfeit() {
    if (!confirmingForfeit.value) {
        confirmingForfeit.value = true;
        forfeitTimer = setTimeout(() => {
            confirmingForfeit.value = false;
        }, 3000);
    } else {
        if (forfeitTimer) {
            clearTimeout(forfeitTimer);
        }
        confirmingForfeit.value = false;
        gameStore.forfeit();
    }
}
</script>

<template>
    <div class="flex h-full flex-col gap-3 p-2 pb-40 sm:gap-4 sm:p-4 sm:pb-4">
        <!-- Leaderboard: horizontal strip on mobile, grid on larger screens.
             Sorted by piles done, so the row that moves is the story. -->
        <div v-if="gameStore.leaderboard.length > 0" class="space-y-2">
            <h3 class="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground sm:text-xs">Leaderboard</h3>
            <TransitionGroup
                name="rank"
                tag="div"
                class="-mx-2 flex snap-x gap-2 overflow-x-auto px-2 pb-1 sm:mx-0 sm:grid sm:grid-cols-2 sm:overflow-visible sm:px-0 sm:pb-0 lg:grid-cols-3"
            >
                <PlayerScoreRow
                    v-for="row in gameStore.leaderboard"
                    :key="row.id"
                    :player="row"
                    :is-self="row.id === gameStore.currentPlayer?.id"
                />
            </TransitionGroup>
        </div>

        <!-- Center piles -->
        <div class="rounded-xl border border-border bg-muted/20 p-2 sm:p-4">
            <h3 class="mb-2 text-[10px] font-semibold uppercase tracking-wider text-muted-foreground sm:mb-3 sm:text-xs">Center</h3>
            <div class="flex flex-wrap justify-center gap-2 sm:gap-4">
                <CenterPileComponent
                    v-for="pile in gameStore.centerPiles"
                    :key="pile.id"
                    :pile="pile"
                    :has-card-in-hand="!!gameStore.myPickedUpCard"
                    :disabled="isDisabled"
                    @swap="onSwap"
                />
            </div>
        </div>

        <!-- Pile viewer: bottom sheet on mobile, inline card on desktop -->
        <template v-if="activePile">
            <!-- Mobile: fixed bottom sheet above sticky hand -->
            <div class="fixed inset-x-0 bottom-32 z-20 mx-2 rounded-xl border border-primary/40 bg-card p-3 shadow-lg sm:hidden">
                <div class="mb-2 flex items-center justify-between">
                    <p class="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">
                        Pile {{ activePile.pile_index + 1 }}
                    </p>
                    <button
                        @click="closePileViewer"
                        aria-label="Close pile"
                        class="rounded-full p-1 text-muted-foreground active:scale-95"
                    >
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                            <line x1="6" y1="6" x2="18" y2="18" />
                            <line x1="6" y1="18" x2="18" y2="6" />
                        </svg>
                    </button>
                </div>
                <PileViewer
                    :pile="activePile"
                    :disabled="isDisabled"
                    :selected-card-id="gameStore.myPickedUpCard?.id ?? null"
                    :bare="true"
                    @select="onSelectCard"
                />
            </div>
            <!-- Desktop: inline card -->
            <div class="hidden sm:block">
                <PileViewer
                    :pile="activePile"
                    :disabled="isDisabled"
                    :selected-card-id="gameStore.myPickedUpCard?.id ?? null"
                    @select="onSelectCard"
                />
            </div>
        </template>

        <!-- Player hand: sticky bottom on mobile, flow on desktop -->
        <div class="fixed inset-x-0 bottom-0 z-10 border-t border-border bg-background/95 p-2 shadow-[0_-2px_8px_rgba(0,0,0,0.08)] backdrop-blur-sm sm:static sm:flex-1 sm:border-t-0 sm:bg-transparent sm:p-0 sm:shadow-none sm:backdrop-blur-0">
            <div class="mb-2 flex items-center justify-between sm:mb-3">
                <h3
                    class="text-[10px] font-semibold uppercase tracking-wider sm:text-xs"
                    :class="readyToClaim ? 'text-emerald-600 dark:text-emerald-400' : 'text-muted-foreground'"
                >
                    <template v-if="readyToClaim">You're ready — call PILES! to win! ↓</template>
                    <template v-else>Your Piles — {{ completedCount }}/6 done</template>
                </h3>
                <button
                    v-if="gameStore.session?.status === GameStatus.Playing || gameStore.session?.status === GameStatus.Verifying"
                    @click="handleForfeit"
                    class="rounded-lg border px-2.5 py-1.5 text-[11px] font-medium transition-colors active:scale-95 sm:px-3 sm:text-xs"
                    :class="confirmingForfeit ? 'border-red-500 bg-red-500 text-white' : 'border-border text-muted-foreground hover:border-red-400 hover:text-red-500'"
                >
                    {{ confirmingForfeit ? 'Confirm forfeit?' : 'Forfeit' }}
                </button>
            </div>

            <button
                v-if="readyToClaim"
                @click="claimPiles"
                class="claim-cta mb-2 w-full rounded-xl bg-emerald-600 px-4 py-3 text-lg font-black tracking-wider text-white shadow-lg transition-transform hover:bg-emerald-700 active:scale-[0.98] sm:mb-3 sm:py-4 sm:text-xl"
            >
                PILES!
            </button>
            <div class="grid grid-cols-6 gap-1.5 sm:gap-3">
                <PlayerPileComponent
                    v-for="pile in gameStore.myPiles"
                    :key="pile.id"
                    :pile="pile"
                    :is-active="activePileId === pile.id"
                    :disabled="isDisabled"
                    @open="onOpenPile"
                />
            </div>
        </div>
    </div>
</template>

<style scoped>
/* FLIP-based: nothing in plain CSS can animate a DOM reorder, and seeing the
   row travel is the point of ranking them. */
.rank-move {
    transition: transform 320ms cubic-bezier(0.22, 1, 0.36, 1);
}

@media (prefers-reduced-motion: reduce) {
    .rank-move {
        transition: none;
    }
}

.claim-cta {
    animation: claim-pulse 1.4s ease-in-out infinite;
}

@keyframes claim-pulse {
    0%, 100% {
        box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.55);
    }
    50% {
        box-shadow: 0 0 0 12px rgba(16, 185, 129, 0);
    }
}
</style>