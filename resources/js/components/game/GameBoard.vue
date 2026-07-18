<script setup lang="ts">
import { computed, ref } from 'vue';
import type { Card, GameSession } from '../../types/game';
import { CARD_COLOR_CLASSES, CLOTHING_TYPE_LABELS, GameStatus } from '../../types/game';
import { useGameStore } from '../../stores/game';
import CardArt from './CardArt.vue';
import CenterPileComponent from './CenterPile.vue';
import OpponentRow from './OpponentRow.vue';
import PileViewer from './PileViewer.vue';
import PlayerPileComponent from './PlayerPile.vue';

defineProps<{
    game: GameSession;
}>();

const gameStore = useGameStore();

const activePileId = ref<number | null>(null);
const pendingCard = ref<Card | null>(null);
const confirmingForfeit = ref(false);
let forfeitTimer: ReturnType<typeof setTimeout> | null = null;

const isDisabled = computed(() => gameStore.session?.status !== GameStatus.Playing || gameStore.isSwapping);
const completedCount = computed(() => gameStore.myPiles.filter((p) => p.is_completed).length);
const activePile = computed(() => gameStore.myPiles.find((p) => p.id === activePileId.value) ?? null);

function onOpenPile(pileId: number) {
    if (activePileId.value === pileId) {
        activePileId.value = null;
        pendingCard.value = null;
    } else {
        activePileId.value = pileId;
        pendingCard.value = null;
    }
}

function closePileViewer() {
    activePileId.value = null;
    pendingCard.value = null;
}

function onPendingCard(card: Card) {
    pendingCard.value = card;
}

async function onPickupCard(card: Card) {
    if (!activePileId.value) {
        return;
    }
    await gameStore.pickUpCard(card.id, activePileId.value);
    pendingCard.value = null;
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
        <!-- Opponents: horizontal strip on mobile, grid on larger screens -->
        <div v-if="gameStore.opponents.length > 0" class="space-y-2">
            <h3 class="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground sm:text-xs">Opponents</h3>
            <div class="-mx-2 flex snap-x gap-2 overflow-x-auto px-2 pb-1 sm:mx-0 sm:grid sm:grid-cols-2 sm:overflow-visible sm:px-0 sm:pb-0 lg:grid-cols-3">
                <OpponentRow v-for="opponent in gameStore.opponents" :key="opponent.id" :opponent="opponent" />
            </div>
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

        <!-- My held card slot -->
        <div class="flex items-center gap-3 rounded-xl border border-border bg-card px-3 py-2 sm:gap-4 sm:px-4 sm:py-3">
            <h3 class="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground sm:text-xs">Your card</h3>
            <div
                v-if="gameStore.myPickedUpCard"
                class="flex h-14 w-11 items-center justify-center overflow-hidden rounded-lg border-2 border-white/50 p-1 text-white shadow ring-2 ring-amber-400 sm:w-10"
                :class="CARD_COLOR_CLASSES[gameStore.myPickedUpCard.color]"
                :title="CLOTHING_TYPE_LABELS[gameStore.myPickedUpCard.clothing_type]"
            >
                <CardArt :type="gameStore.myPickedUpCard.clothing_type" />
            </div>
            <div
                v-else
                class="flex h-14 w-11 items-center justify-center rounded-lg border-2 border-dashed border-muted-foreground/30 text-[10px] text-muted-foreground sm:w-10"
            >
                Empty
            </div>
            <p class="flex-1 text-[11px] leading-tight text-muted-foreground sm:text-xs">
                <template v-if="gameStore.myPickedUpCard">Tap a center pile to swap</template>
                <template v-else-if="activePile">Tap a card once to select, again to pick up</template>
                <template v-else>Tap a pile to open it</template>
            </p>
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
                    :pending-card="pendingCard"
                    :disabled="isDisabled || !!gameStore.myPickedUpCard"
                    :bare="true"
                    @pending="onPendingCard"
                    @pickup="onPickupCard"
                />
            </div>
            <!-- Desktop: inline card -->
            <div class="hidden sm:block">
                <PileViewer
                    :pile="activePile"
                    :pending-card="pendingCard"
                    :disabled="isDisabled || !!gameStore.myPickedUpCard"
                    @pending="onPendingCard"
                    @pickup="onPickupCard"
                />
            </div>
        </template>

        <!-- Player hand: sticky bottom on mobile, flow on desktop -->
        <div class="fixed inset-x-0 bottom-0 z-10 border-t border-border bg-background/95 p-2 shadow-[0_-2px_8px_rgba(0,0,0,0.08)] backdrop-blur-sm sm:static sm:flex-1 sm:border-t-0 sm:bg-transparent sm:p-0 sm:shadow-none sm:backdrop-blur-0">
            <div class="mb-2 flex items-center justify-between sm:mb-3">
                <h3 class="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground sm:text-xs">
                    Your Piles — {{ completedCount }}/6 done
                </h3>
                <div class="flex items-center gap-2">
                    <button
                        v-if="completedCount === 6 && gameStore.session?.status === GameStatus.Playing"
                        @click="claimPiles"
                        class="rounded-lg bg-emerald-600 px-3 py-1.5 text-sm font-bold text-white shadow transition-transform hover:bg-emerald-700 active:scale-95 sm:px-4"
                    >
                        PILES!
                    </button>
                    <button
                        v-if="gameStore.session?.status === GameStatus.Playing || gameStore.session?.status === GameStatus.Verifying"
                        @click="handleForfeit"
                        class="rounded-lg border px-2.5 py-1.5 text-[11px] font-medium transition-colors active:scale-95 sm:px-3 sm:text-xs"
                        :class="confirmingForfeit ? 'border-red-500 bg-red-500 text-white' : 'border-border text-muted-foreground hover:border-red-400 hover:text-red-500'"
                    >
                        {{ confirmingForfeit ? 'Confirm forfeit?' : 'Forfeit' }}
                    </button>
                </div>
            </div>
            <div class="grid grid-cols-6 gap-1.5 sm:gap-3">
                <PlayerPileComponent
                    v-for="pile in gameStore.myPiles"
                    :key="pile.id"
                    :pile="pile"
                    :is-active="activePileId === pile.id"
                    :disabled="isDisabled || !!gameStore.myPickedUpCard"
                    @open="onOpenPile"
                />
            </div>
        </div>
    </div>
</template>