<script setup lang="ts">
import { computed, ref } from 'vue';
import type { Card, GameSession } from '../../types/game';
import { GameStatus } from '../../types/game';
import { useGameStore } from '../../stores/game';
import CenterPileComponent from './CenterPile.vue';
import OpponentRow from './OpponentRow.vue';
import PlayerPileComponent from './PlayerPile.vue';

defineProps<{
    game: GameSession;
}>();

const gameStore = useGameStore();

const selectedPileId = ref<number | null>(null);
const selectedCard = ref<Card | null>(null);

const isDisabled = computed(() => gameStore.session?.status !== GameStatus.Playing || gameStore.isSwapping);

const completedCount = computed(() => gameStore.myPiles.filter((p) => p.is_completed).length);

function onSelectCard(pileId: number, card: Card) {
    selectedPileId.value = pileId;
    selectedCard.value = card;
}

function onDeselectCard() {
    selectedPileId.value = null;
    selectedCard.value = null;
}

async function onSwap(centerPileId: number) {
    if (!selectedPileId.value || !selectedCard.value) {
        return;
    }
    await gameStore.swapCard(selectedPileId.value, selectedCard.value.id, centerPileId);
    selectedPileId.value = null;
    selectedCard.value = null;
}

async function claimPiles() {
    await gameStore.claimPiles();
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
                    :selected-card="selectedCard"
                    :disabled="isDisabled"
                    @swap="onSwap"
                />
            </div>
        </div>

        <!-- Instruction hint -->
        <div v-if="!isDisabled" class="text-center text-sm text-muted-foreground">
            <span v-if="!selectedCard">Tap a card from your hand to select it</span>
            <span v-else>Tap a center pile to swap — or tap your card again to deselect</span>
        </div>

        <!-- Player hand -->
        <div class="flex-1">
            <div class="mb-3 flex items-center justify-between">
                <h3 class="text-xs font-semibold uppercase tracking-wider text-muted-foreground">
                    Your Hand — {{ completedCount }}/6 piles done
                </h3>
                <button
                    v-if="completedCount === 6 && gameStore.session?.status === GameStatus.Playing"
                    @click="claimPiles"
                    class="rounded-lg bg-emerald-600 px-4 py-1.5 text-sm font-bold text-white shadow hover:bg-emerald-700 active:scale-95"
                >
                    PILES!
                </button>
            </div>
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
                <PlayerPileComponent
                    v-for="pile in gameStore.myPiles"
                    :key="pile.id"
                    :pile="pile"
                    :selected-card="selectedCard"
                    :disabled="isDisabled"
                    @select-card="onSelectCard"
                    @deselect-card="onDeselectCard"
                />
            </div>
        </div>
    </div>
</template>