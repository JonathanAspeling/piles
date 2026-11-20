<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { useGameStore } from '../../stores/game';
import type { Card, CenterPile, GameSession } from '../../types/game';
import { CLOTHING_TYPE_LABELS, GameStatus } from '../../types/game';
import { cardStyleFor } from '../../utils/cardArt';
import type { GameCardState } from './GameCard.vue';
import GameCard from './GameCard.vue';
import HowToPlayModal from './HowToPlayModal.vue';
import OpponentCard from './OpponentCard.vue';
import PileTile from './PileTile.vue';
import type { Flight, SlotRect } from './SwapFlight.vue';
import SwapFlight from './SwapFlight.vue';

const props = defineProps<{
    game: GameSession;
}>();

const cardStyle = computed(() => cardStyleFor(props.game));

const gameStore = useGameStore();

const boardRoot = ref<HTMLElement | null>(null);
const activePileId = ref<number | null>(null);
const reorderMode = ref(false);
const reorderFirstPileId = ref<number | null>(null);
const showHowToPlay = ref(false);

const isDisabled = computed(() => gameStore.session?.status !== GameStatus.Playing);
const completedCount = computed(() => gameStore.myPiles.filter((p) => p.is_completed).length);
const activePile = computed(() => gameStore.myPiles.find((p) => p.id === activePileId.value) ?? null);
const readyToClaim = computed(() => completedCount.value === 6 && gameStore.session?.status === GameStatus.Playing);
const pickedCard = computed(() => gameStore.myPickedUpCard);
const pickedItemName = computed(() => (pickedCard.value ? CLOTHING_TYPE_LABELS[pickedCard.value.clothing_type].toLowerCase() : ''));

// Ranked by piles done, so whoever is closest to winning is always first.
const opponents = computed(() => gameStore.leaderboard.filter((row) => row.id !== gameStore.currentPlayer?.id));

/** The open pile's four cards, or four empty slots: the row is always there, so opening a pile moves nothing. */
const handSlots = computed<(Card | null)[]>(() => activePile.value?.cards ?? [null, null, null, null]);

/** The phone's spare space above the board holds one line of guidance, only while there's nothing else to read. */
const coachText = computed(() => {
    if (reorderMode.value) {
        return 'Tap two piles to swap their places.';
    }
    if (activePile.value || completedCount.value > 0) {
        return '';
    }

    return 'Make every pile 4 of the same item. Tap a pile to start.';
});

// --- Swap motion -----------------------------------------------------------

const flights = ref<Flight[] | null>(null);
const hiddenCardIds = ref<Set<number>>(new Set());
const flashCardIds = ref<Set<number>>(new Set());
const justSwapped = ref(false);
let flashTimer: ReturnType<typeof setTimeout> | null = null;

const prefersReducedMotion = typeof window !== 'undefined' && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

function cardState(card: Card | null, baseState: GameCardState): GameCardState {
    if (!card) {
        return 'idle';
    }
    if (hiddenCardIds.value.has(card.id)) {
        return 'hidden';
    }
    if (flashCardIds.value.has(card.id)) {
        return 'flash';
    }

    return baseState;
}

function handCardState(card: Card | null): GameCardState {
    return cardState(card, card !== null && card.id === pickedCard.value?.id ? 'lifted' : 'idle');
}

function centreCardState(card: Card | null): GameCardState {
    return cardState(card, pickedCard.value && !isDisabled.value ? 'target' : 'idle');
}

/** Where a slot's card sits at rest: a lifted card is drawn raised, but lands back level. */
function restingRect(element: Element): SlotRect {
    const rect = element.getBoundingClientRect();
    const lift = new DOMMatrixReadOnly(getComputedStyle(element).transform).m42;

    return { left: rect.left, top: rect.top - lift, width: rect.width, height: rect.height };
}

function slotElement(zone: 'hand' | 'centre', cardId: number): Element | null {
    return boardRoot.value?.querySelector(`[data-zone="${zone}"] [data-card-id="${cardId}"]`) ?? null;
}

function flash(cardIds: number[]) {
    if (flashTimer) {
        clearTimeout(flashTimer);
    }
    flashCardIds.value = new Set(cardIds);
    justSwapped.value = true;
    flashTimer = setTimeout(() => {
        flashCardIds.value = new Set();
        justSwapped.value = false;
    }, 700);
}

function onFlightLanded() {
    const landedIds = flights.value?.map((flight) => flight.card.id) ?? [];
    flights.value = null;
    hiddenCardIds.value = new Set();
    flash(landedIds);
}

onBeforeUnmount(() => {
    if (flashTimer) {
        clearTimeout(flashTimer);
    }
});

// --- Actions ---------------------------------------------------------------

// Close the pile as soon as it becomes completed — the completing swap
// arrives asynchronously via broadcast.
watch(
    () => activePile.value?.is_completed,
    (completed) => {
        if (completed) {
            closePile();
        }
    },
);

// Reorder mode must never outlive the game it belongs to.
watch(isDisabled, (disabled) => {
    if (disabled) {
        reorderMode.value = false;
        reorderFirstPileId.value = null;
    }
});

// Arming closes the pile and drops any held card, so the two tap gestures —
// open-a-pile and pick-two-piles — never overlap.
async function toggleReorderMode() {
    reorderMode.value = !reorderMode.value;
    reorderFirstPileId.value = null;

    if (reorderMode.value) {
        activePileId.value = null;
        await gameStore.releaseCard();
    }
}

function onReorderTap(pileId: number) {
    if (reorderFirstPileId.value === null) {
        reorderFirstPileId.value = pileId;
        return;
    }

    if (reorderFirstPileId.value === pileId) {
        reorderFirstPileId.value = null;
        return;
    }

    const ids = gameStore.myPiles.map((p) => p.id);
    const from = ids.indexOf(reorderFirstPileId.value);
    const to = ids.indexOf(pileId);
    [ids[from], ids[to]] = [ids[to], ids[from]];

    // Mode stays armed — rearranging is usually more than one swap.
    reorderFirstPileId.value = null;
    gameStore.reorderPiles(ids);
}

// A selection never survives leaving the pile it belongs to — every pile opens
// with all four cards unselected.
async function onOpenPile(pileId: number) {
    if (reorderMode.value) {
        onReorderTap(pileId);
        return;
    }

    activePileId.value = activePileId.value === pileId ? null : pileId;
    await gameStore.releaseCard();
}

async function closePile() {
    activePileId.value = null;
    await gameStore.releaseCard();
}

async function onSelectHandCard(card: Card) {
    if (!activePileId.value || isDisabled.value || activePile.value?.is_completed) {
        return;
    }
    if (pickedCard.value?.id === card.id) {
        await gameStore.releaseCard();
        return;
    }
    // Picking up a different card overwrites the selection server-side, so
    // there's no release round-trip when switching.
    await gameStore.pickUpCard(card.id, activePileId.value);
}

async function onSelectCentre(centerPile: CenterPile) {
    const heldCard = pickedCard.value;
    const incomingCard = centerPile.top_card;
    if (isDisabled.value || !heldCard || !incomingCard) {
        return;
    }

    // Measure both slots before the store swaps them (it does so synchronously).
    const heldElement = slotElement('hand', heldCard.id);
    const centreElement = slotElement('centre', incomingCard.id);
    const handRect = heldElement ? restingRect(heldElement) : null;
    const centreRect = centreElement ? restingRect(centreElement) : null;

    const swap = gameStore.swapCard(centerPile.id, incomingCard.id, centerPile.version);

    if (handRect && centreRect && !prefersReducedMotion) {
        hiddenCardIds.value = new Set([heldCard.id, incomingCard.id]);
        flights.value = [
            // The lifted card leaves from where it's drawn, raised by -translate-y-3.
            { card: heldCard, from: { ...handRect, top: handRect.top - 12 }, to: centreRect, bow: 1 },
            { card: incomingCard, from: centreRect, to: handRect, bow: 1 },
        ];
    } else {
        flash([heldCard.id, incomingCard.id]);
    }

    await swap;
}

async function claimPiles() {
    await gameStore.claimPiles();
}
</script>

<template>
    <div ref="boardRoot" class="flex min-h-full flex-1 flex-col md:flex-row">
        <!-- Desktop: opponents get a column of their own. -->
        <aside class="hidden w-[300px] shrink-0 flex-col gap-[22px] border-r border-border bg-card px-6 py-7 md:flex">
            <div class="flex items-baseline justify-between gap-3">
                <span class="text-[11px] font-bold uppercase tracking-[0.1em] text-muted-foreground">Opponents</span>
                <span class="text-xs text-muted-foreground">
                    Room <span class="font-mono tracking-[0.06em] text-foreground">{{ game.code }}</span>
                </span>
            </div>
            <TransitionGroup name="rank" tag="div" class="-m-1.5 flex min-h-0 flex-col gap-2.5 overflow-y-auto p-1.5">
                <OpponentCard v-for="opponent in opponents" :key="opponent.id" :player="opponent" :theme="game.theme" layout="sidebar" />
            </TransitionGroup>
            <div class="flex-1" />
            <div class="flex gap-[18px] border-t border-border pt-4 text-sm text-muted-foreground">
                <button type="button" class="hover:text-foreground" @click="showHowToPlay = true">How to play</button>
            </div>
        </aside>

        <!-- Phone: opponents across the top, clear of the floating menu button. -->
        <TransitionGroup name="rank" tag="div" class="flex shrink-0 snap-x gap-2.5 overflow-x-auto px-4 pb-1 pr-14 pt-2 md:hidden">
            <OpponentCard
                v-for="opponent in opponents"
                :key="opponent.id"
                :player="opponent"
                :theme="game.theme"
                layout="strip"
                class="min-w-[15rem] flex-1 snap-start"
            />
        </TransitionGroup>
        <p class="flex flex-1 items-end justify-center px-7 pb-3.5 text-center text-base leading-snug text-muted-foreground md:hidden">
            {{ coachText }}
        </p>

        <main class="flex shrink-0 flex-col items-center px-4 pb-3 md:flex-1 md:justify-center md:px-10 md:py-8">
            <div class="flex w-full flex-col gap-3.5 md:w-[660px] md:max-w-full md:gap-7">
                <div class="flex flex-col gap-2.5 md:gap-4">
                    <!-- Centre -->
                    <section
                        data-zone="centre"
                        class="rounded-[14px] border bg-muted p-3 transition-colors md:rounded-[18px] md:p-[18px]"
                        :class="pickedCard && !isDisabled ? 'border-[#F2B42B]' : 'border-border'"
                    >
                        <div class="flex h-[18px] items-center justify-between gap-3 md:h-6">
                            <h3 class="text-[11px] font-bold uppercase tracking-[0.1em] text-muted-foreground md:text-xs">Centre</h3>
                            <span
                                v-if="pickedCard && !isDisabled"
                                class="truncate text-[13px] font-semibold text-[#A86E00] dark:text-[#F2B42B] md:text-[15px]"
                            >
                                <span class="md:hidden">Tap one to swap</span>
                                <span class="hidden md:inline">Tap a card to swap it with your {{ pickedItemName }}</span>
                            </span>
                        </div>
                        <div class="mx-auto mt-2.5 grid max-w-[576px] grid-cols-4 gap-2 md:mt-4 md:gap-4">
                            <GameCard
                                v-for="pile in gameStore.centerPiles"
                                :key="pile.id"
                                :data-centre-index="pile.pile_index + 1"
                                :card="pile.top_card"
                                :card-style="cardStyle"
                                :state="centreCardState(pile.top_card)"
                                :interactive="!!pickedCard && !isDisabled && !!pile.top_card"
                                @select="onSelectCentre(pile)"
                            />
                        </div>
                    </section>

                    <!-- Your cards: always reserved, filled by whichever pile is open -->
                    <section
                        data-zone="hand"
                        class="rounded-[14px] border border-border p-3 transition-colors md:rounded-[18px] md:p-[18px]"
                        :class="activePile ? 'bg-muted' : 'bg-transparent'"
                    >
                        <div class="flex h-[18px] items-center justify-between gap-3 md:h-6">
                            <h3 class="text-[11px] font-bold uppercase tracking-[0.1em] text-muted-foreground md:text-xs">
                                {{ activePile ? `Pile ${activePile.pile_index + 1}` : 'Your cards' }}
                            </h3>
                            <div class="flex min-w-0 items-center gap-2">
                                <span v-if="justSwapped" class="text-[13px] font-semibold text-[#0E9C6B] md:text-[15px]">Swapped</span>
                                <span v-else-if="pickedCard" class="truncate text-[13px] font-semibold text-muted-foreground md:text-[15px]">
                                    <span class="md:hidden">Tap {{ pickedItemName }} to cancel</span>
                                    <span class="hidden md:inline"
                                        >{{ CLOTHING_TYPE_LABELS[pickedCard.clothing_type] }} lifted · tap it again to put it back</span
                                    >
                                </span>
                                <span
                                    v-else-if="activePile && !isDisabled"
                                    class="truncate text-[13px] font-semibold text-muted-foreground md:text-[15px]"
                                >
                                    Tap a card to pick it up
                                </span>
                                <button
                                    v-if="activePile"
                                    type="button"
                                    aria-label="Close pile"
                                    class="-mr-1 shrink-0 rounded-full p-1 text-muted-foreground hover:bg-background hover:text-foreground active:scale-95"
                                    @click="closePile"
                                >
                                    <svg
                                        xmlns="http://www.w3.org/2000/svg"
                                        class="h-4 w-4"
                                        viewBox="0 0 24 24"
                                        fill="none"
                                        stroke="currentColor"
                                        stroke-width="2.5"
                                        stroke-linecap="round"
                                        stroke-linejoin="round"
                                    >
                                        <line x1="6" y1="6" x2="18" y2="18" />
                                        <line x1="6" y1="18" x2="18" y2="6" />
                                    </svg>
                                </button>
                            </div>
                        </div>
                        <div class="relative mx-auto mt-2.5 grid max-w-[576px] grid-cols-4 gap-2 md:mt-4 md:gap-4">
                            <GameCard
                                v-for="(card, index) in handSlots"
                                :key="card?.id ?? `empty-${index}`"
                                :card="card"
                                :card-style="cardStyle"
                                :state="handCardState(card)"
                                :interactive="!!activePile && !isDisabled && !activePile.is_completed"
                                @select="card && onSelectHandCard(card)"
                            />
                            <p
                                v-if="!activePile"
                                class="absolute inset-0 flex items-center justify-center px-3 text-center text-[13px] font-semibold text-muted-foreground md:text-[15px]"
                            >
                                {{ reorderMode ? 'Tap two piles to swap their places' : 'Tap a pile below to look inside' }}
                            </p>
                        </div>
                    </section>
                </div>

                <!-- Your piles -->
                <section class="flex flex-col gap-2.5">
                    <div class="flex items-center justify-between gap-3">
                        <h3
                            class="text-[11px] font-bold uppercase tracking-[0.1em] md:text-xs"
                            :class="readyToClaim ? 'text-[#0E9C6B]' : 'text-muted-foreground'"
                        >
                            <template v-if="readyToClaim">You're ready — call PILES! to win</template>
                            <template v-else>
                                Your piles · <span class="text-foreground">{{ completedCount }}/6</span>
                            </template>
                        </h3>
                        <button
                            v-if="!isDisabled"
                            type="button"
                            :aria-pressed="reorderMode"
                            class="inline-flex items-center gap-1.5 rounded-full border px-3 py-1 text-[13px] transition-colors active:scale-95 md:text-[15px]"
                            :class="
                                reorderMode
                                    ? 'border-[#F2B42B] bg-[#F2B42B] font-semibold text-white'
                                    : 'border-border text-muted-foreground hover:text-foreground'
                            "
                            @click="toggleReorderMode"
                        >
                            <svg
                                v-if="!reorderMode"
                                xmlns="http://www.w3.org/2000/svg"
                                class="h-3.5 w-3.5"
                                viewBox="0 0 24 24"
                                fill="none"
                                stroke="currentColor"
                                stroke-width="2.5"
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                aria-hidden="true"
                            >
                                <polyline points="17 2 21 6 17 10" />
                                <line x1="21" y1="6" x2="3" y2="6" />
                                <polyline points="7 22 3 18 7 14" />
                                <line x1="3" y1="18" x2="21" y2="18" />
                            </svg>
                            {{ reorderMode ? 'Done rearranging' : 'Rearrange piles' }}
                        </button>
                    </div>

                    <button
                        v-if="readyToClaim"
                        type="button"
                        class="claim-cta w-full rounded-[14px] bg-[#0E9C6B] px-4 py-3 text-lg font-black tracking-wider text-white shadow-lg transition-transform hover:bg-[#0B8A5E] active:scale-[0.98] md:py-4 md:text-xl"
                        @click="claimPiles"
                    >
                        PILES!
                    </button>

                    <TransitionGroup name="pile" tag="div" class="grid grid-cols-6 gap-1.5 md:gap-3">
                        <PileTile
                            v-for="pile in gameStore.myPiles"
                            :key="pile.id"
                            :pile="pile"
                            :card-style="cardStyle"
                            :is-open="activePileId === pile.id"
                            :disabled="isDisabled"
                            :reorder-mode="reorderMode"
                            :is-reorder-selected="reorderFirstPileId === pile.id"
                            @open="onOpenPile"
                        />
                    </TransitionGroup>
                </section>
            </div>
        </main>

        <SwapFlight v-if="flights" :flights="flights" :card-style="cardStyle" @landed="onFlightLanded" />
        <HowToPlayModal v-if="showHowToPlay" :card-style="cardStyle" @close="showHowToPlay = false" />
    </div>
</template>

<style scoped>
/* FLIP-based: nothing in plain CSS can animate a DOM reorder, and seeing the
   row travel is the point of ranking them. */
.rank-move {
    transition: transform 320ms cubic-bezier(0.22, 1, 0.36, 1);
}

.pile-move {
    transition: transform 250ms ease;
}

@media (prefers-reduced-motion: reduce) {
    .rank-move,
    .pile-move {
        transition: none;
    }
}

.claim-cta {
    animation: claim-pulse 1.4s ease-in-out infinite;
}

@keyframes claim-pulse {
    0%,
    100% {
        box-shadow: 0 0 0 0 rgba(14, 156, 107, 0.55);
    }
    50% {
        box-shadow: 0 0 0 12px rgba(14, 156, 107, 0);
    }
}
</style>
