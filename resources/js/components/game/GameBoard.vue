<script setup lang="ts">
import { computed, ref } from 'vue';
import type { Card, GameSession } from '../../types/game';
import { CARD_COLOR_CLASSES, CLOTHING_TYPE_LABELS, GameStatus } from '../../types/game';
import { useGameStore } from '../../stores/game';
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
    <div class="flex h-full flex-col gap-4 p-4">
        <!-- Opponents -->
        <div v-if="gameStore.opponents.length > 0" class="space-y-2">
            <h3 class="text-xs font-semibold uppercase tracking-wider text-muted-foreground">Opponents</h3>
            <div class="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                <OpponentRow v-for="opponent in gameStore.opponents" :key="opponent.id" :opponent="opponent" />
            </div>
        </div>

        <!-- Center piles -->
        <div class="rounded-xl border border-border bg-muted/20 p-4">
            <h3 class="mb-3 text-xs font-semibold uppercase tracking-wider text-muted-foreground">Center</h3>
            <div class="flex flex-wrap justify-center gap-4">
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
        <div class="flex items-center gap-4 rounded-xl border border-border bg-card px-4 py-3">
            <h3 class="text-xs font-semibold uppercase tracking-wider text-muted-foreground">Your card</h3>
            <div
                v-if="gameStore.myPickedUpCard"
                class="flex h-14 w-10 flex-col items-center justify-center rounded-lg border-2 border-white/50 text-white shadow ring-2 ring-amber-400"
                :class="CARD_COLOR_CLASSES[gameStore.myPickedUpCard.color]"
                :title="CLOTHING_TYPE_LABELS[gameStore.myPickedUpCard.clothing_type]"
            >
                <span class="px-1 text-center text-[9px] font-bold leading-tight">{{ CLOTHING_TYPE_LABELS[gameStore.myPickedUpCard.clothing_type] }}</span>
            </div>
            <div
                v-else
                class="flex h-14 w-10 items-center justify-center rounded-lg border-2 border-dashed border-muted-foreground/30 text-[10px] text-muted-foreground"
            >
                Empty
            </div>
            <p class="text-xs text-muted-foreground">
                <template v-if="gameStore.myPickedUpCard">Tap a center pile to swap</template>
                <template v-else-if="activePile">Tap a card once to select, again to pick it up</template>
                <template v-else>Tap a pile to open it</template>
            </p>
        </div>

        <!-- Pile viewer (open pile) -->
        <PileViewer
            v-if="activePile"
            :pile="activePile"
            :pending-card="pendingCard"
            :disabled="isDisabled || !!gameStore.myPickedUpCard"
            @pending="onPendingCard"
            @pickup="onPickupCard"
        />

        <!-- Player hand -->
        <div class="flex-1">
            <div class="mb-3 flex items-center justify-between">
                <h3 class="text-xs font-semibold uppercase tracking-wider text-muted-foreground">
                    Your Piles — {{ completedCount }}/6 done
                </h3>
                <div class="flex items-center gap-2">
                    <button
                        v-if="completedCount === 6 && gameStore.session?.status === GameStatus.Playing"
                        @click="claimPiles"
                        class="rounded-lg bg-emerald-600 px-4 py-1.5 text-sm font-bold text-white shadow hover:bg-emerald-700 active:scale-95"
                    >
                        PILES!
                    </button>
                    <button
                        v-if="gameStore.session?.status === GameStatus.Playing || gameStore.session?.status === GameStatus.Verifying"
                        @click="handleForfeit"
                        class="rounded-lg border px-3 py-1.5 text-xs font-medium transition-colors"
                        :class="confirmingForfeit ? 'border-red-500 bg-red-500 text-white' : 'border-border text-muted-foreground hover:border-red-400 hover:text-red-500'"
                    >
                        {{ confirmingForfeit ? 'Confirm forfeit?' : 'Forfeit' }}
                    </button>
                </div>
            </div>
            <div class="grid grid-cols-3 gap-3 sm:grid-cols-6">
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
