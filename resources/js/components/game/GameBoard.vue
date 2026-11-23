<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue';
import type { DragZone, DropContext } from '../../composables/useDragSort';
import { useDragSort } from '../../composables/useDragSort';
import { useGameStore } from '../../stores/game';
import { useNotificationStore } from '../../stores/notification';
import type { Card, CenterPile, GameSession } from '../../types/game';
import { CLOTHING_TYPE_LABELS, GameStatus } from '../../types/game';
import { cardStyleFor } from '../../utils/cardArt';
import ClaimPrompt from './ClaimPrompt.vue';
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
const notificationStore = useNotificationStore();

const boardRoot = ref<HTMLElement | null>(null);
const activePileId = ref<number | null>(null);
const showHowToPlay = ref(false);

const isDisabled = computed(() => gameStore.session?.status !== GameStatus.Playing);
const completedCount = computed(() => gameStore.myPiles.filter((p) => p.is_completed).length);
const activePile = computed(() => gameStore.myPiles.find((p) => p.id === activePileId.value) ?? null);
const readyToClaim = computed(() => completedCount.value === 6 && gameStore.session?.status === GameStatus.Playing);
const pickedCard = computed(() => gameStore.myPickedUpCard);
/**
 * Swaps work in either order: lift one of your cards then tap the centre, or
 * tap a centre card first and then the card of yours to trade it for. The
 * centre choice is local only — nothing reaches the server until the swap.
 */
const selectedCentrePileId = ref<number | null>(null);
const selectedCentrePile = computed(() => gameStore.centerPiles.find((p) => p.id === selectedCentrePileId.value) ?? null);
const canSwapFromHand = computed(() => !!activePile.value && !isDisabled.value && !activePile.value.is_completed);
const pickedItemName = computed(() => (pickedCard.value ? CLOTHING_TYPE_LABELS[pickedCard.value.clothing_type].toLowerCase() : ''));

// Ranked by piles done, so whoever is closest to winning is always first.
const opponents = computed(() => gameStore.leaderboard.filter((row) => row.id !== gameStore.currentPlayer?.id));

/** The open pile's four cards, or four empty slots: the row is always there, so opening a pile moves nothing. */
const handSlots = computed<(Card | null)[]>(() => activePile.value?.cards ?? [null, null, null, null]);

/** The phone's spare space above the board holds one line of guidance, only while there's nothing else to read. */
const coachText = computed(() => {
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
    if (card !== null && card.id === pickedCard.value?.id) {
        return cardState(card, 'lifted');
    }

    return cardState(card, selectedCentrePile.value && canSwapFromHand.value ? 'target' : 'idle');
}

function centreCardState(pile: CenterPile): GameCardState {
    if (pile.id === selectedCentrePileId.value) {
        return cardState(pile.top_card, 'lifted');
    }

    return cardState(pile.top_card, pickedCard.value && !isDisabled.value ? 'target' : 'idle');
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

/**
 * Hands over from the flying copies to the real cards without a blink: the
 * real cards are shown (art decoded) underneath first, then the copies go.
 */
async function onFlightLanded() {
    const landedFlights = flights.value;
    const landedIds = landedFlights?.map((flight) => flight.card.id) ?? [];

    hiddenCardIds.value = new Set();
    await nextTick();
    await Promise.all(
        landedIds.map((cardId) =>
            boardRoot.value
                ?.querySelector<HTMLImageElement>(`[data-zone] [data-card-id="${cardId}"] img`)
                ?.decode()
                .catch(() => undefined),
        ),
    );

    // A newer swap may have taken off meanwhile; its flight isn't ours to clear.
    if (flights.value === landedFlights) {
        flights.value = null;
    }
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

// A selection never survives leaving the pile it belongs to — every pile opens
// with all four cards unselected.
async function onOpenPile(pileId: number) {
    activePileId.value = activePileId.value === pileId ? null : pileId;
    await gameStore.releaseCard();
}

async function closePile() {
    activePileId.value = null;
    await gameStore.releaseCard();
}

async function onSelectHandCard(card: Card) {
    if (!activePileId.value || !canSwapFromHand.value) {
        return;
    }
    const centrePile = selectedCentrePile.value;
    if (centrePile?.top_card) {
        // Centre first: pick this card up and swap straight away. The pickup is
        // queued ahead of the swap (the store serialises gameplay requests), so
        // the swap can start moving without waiting for it.
        const pickup = gameStore.pickUpCard(card.id, activePileId.value);
        await Promise.all([pickup, swapWithCentre(centrePile)]);
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
    if (isDisabled.value || !centerPile.top_card) {
        return;
    }
    if (pickedCard.value) {
        await swapWithCentre(centerPile);
        return;
    }
    // Nothing lifted yet: choose this centre card, or put it back if it's already chosen.
    selectedCentrePileId.value = selectedCentrePileId.value === centerPile.id ? null : centerPile.id;
}

/**
 * Swap the lifted card with a centre pile's top card, flying both between their
 * slots. A drag-and-drop passes where it left the cards; a tap measures them.
 */
async function swapWithCentre(centerPile: CenterPile, dropped?: { hand: SlotPair; centre: SlotPair }) {
    const heldCard = pickedCard.value;
    const incomingCard = centerPile.top_card;
    selectedCentrePileId.value = null;
    if (isDisabled.value || !heldCard || !incomingCard) {
        return;
    }

    // Measure both slots before the store swaps them (it does so synchronously).
    // Each card flies from where it's drawn now (a chosen card sits raised) to
    // where the other one rests.
    const heldElement = slotElement('hand', heldCard.id);
    const centreElement = slotElement('centre', incomingCard.id);
    const handFrom = dropped?.hand.from ?? (heldElement ? slotRect(heldElement.getBoundingClientRect()) : null);
    const centreFrom = dropped?.centre.from ?? (centreElement ? slotRect(centreElement.getBoundingClientRect()) : null);
    const handRect = dropped?.hand.rest ?? (heldElement ? restingRect(heldElement) : null);
    const centreRect = dropped?.centre.rest ?? (centreElement ? restingRect(centreElement) : null);

    const swap = gameStore.swapCard(centerPile.id, incomingCard.id, centerPile.version);

    if (handFrom && centreFrom && handRect && centreRect && !prefersReducedMotion) {
        hiddenCardIds.value = new Set([heldCard.id, incomingCard.id]);
        flights.value = [
            { card: heldCard, from: handFrom, to: centreRect, bow: 1 },
            { card: incomingCard, from: centreFrom, to: handRect, bow: 1 },
        ];
    } else {
        flash([heldCard.id, incomingCard.id]);
    }

    await swap;
}

function slotRect(rect: DOMRect): SlotRect {
    return { left: rect.left, top: rect.top, width: rect.width, height: rect.height };
}

interface SlotPair {
    from: SlotRect;
    rest: SlotRect;
}

// --- Drag and drop ---------------------------------------------------------
// Dragging is the main way to move things; every drag has a tap equivalent.
//  - a card in your open pile: along the row to rearrange the pile, or onto a centre card to swap;
//  - a centre card: onto a card in your open pile to swap (the centre's order is shared, so it stays put);
//  - a pile tile: along the row to rearrange your piles.

const drag = useDragSort();
const centreRow = ref<HTMLElement | null>(null);
const handRow = ref<HTMLElement | null>(null);
const pilesRow = ref<HTMLElement | null>(null);

function rowItems(row: HTMLElement | null, selector: string): HTMLElement[] {
    return row ? [...row.querySelectorAll<HTMLElement>(selector)] : [];
}

function centrePileFor(element: HTMLElement): CenterPile | null {
    const index = Number(element.dataset.centreIndex) - 1;

    return gameStore.centerPiles.find((pile) => pile.pile_index === index) ?? null;
}

const handZone: DragZone = {
    items: () => rowItems(handRow.value, 'button[data-card-id]'),
    idOf: (element) => Number(element.dataset.cardId) || null,
    canDrag: () => canSwapFromHand.value && !flights.value,
    onDragStart: () => (selectedCentrePileId.value = null),
    onReorder: (orderedIds) => {
        if (activePileId.value) {
            gameStore.reorderPileCards(activePileId.value, orderedIds);
        }
    },
    targets: () => rowItems(centreRow.value, 'button[data-card-id]'),
    onDropOnTarget: (cardId, target, context) => {
        const centerPile = centrePileFor(target);
        if (!centerPile?.top_card || !activePileId.value || !canSwapFromHand.value) {
            return;
        }
        if (pickedCard.value?.id !== cardId) {
            gameStore.pickUpCard(cardId, activePileId.value);
        }
        swapWithCentre(centerPile, {
            hand: { from: context.draggedRect, rest: context.draggedRest },
            centre: { from: context.targetRect, rest: context.targetRest },
        });
    },
};

const centreZone: DragZone = {
    items: () => rowItems(centreRow.value, 'button[data-card-id]'),
    idOf: (element) => centrePileFor(element)?.id ?? null,
    canDrag: () => !isDisabled.value && !flights.value,
    onDragStart: () => (selectedCentrePileId.value = null),
    targets: () => (canSwapFromHand.value ? rowItems(handRow.value, 'button[data-card-id]') : []),
    onDropOnTarget: (centerPileId, target, context: DropContext) => {
        const centerPile = gameStore.centerPiles.find((pile) => pile.id === centerPileId);
        const cardId = Number(target.dataset.cardId);
        if (!centerPile?.top_card || !activePileId.value || !canSwapFromHand.value) {
            return;
        }
        if (pickedCard.value?.id !== cardId) {
            gameStore.pickUpCard(cardId, activePileId.value);
        }
        swapWithCentre(centerPile, {
            hand: { from: context.targetRect, rest: context.targetRest },
            centre: { from: context.draggedRect, rest: context.draggedRest },
        });
    },
};

const pilesZone: DragZone = {
    items: () => rowItems(pilesRow.value, '[data-pile-id]'),
    idOf: (element) => Number(element.dataset.pileId) || null,
    canDrag: () => !isDisabled.value,
    onReorder: (orderedIds) => gameStore.reorderPiles(orderedIds),
};

// A drag never outlives the game it started in.
watch(isDisabled, (disabled) => {
    if (disabled) {
        drag.cancel();
    }
});

// Bumped to re-arm the claim prompt (its button and countdown) if a claim doesn't go through.
const claimPromptKey = ref(0);

async function claimPiles() {
    const accepted = await gameStore.claimPiles();
    if (!accepted && readyToClaim.value) {
        notificationStore.add('Could not call PILES! — tap to try again.', 'error');
        claimPromptKey.value++;
    }
}
</script>

<template>
    <!-- Desktop: the board is exactly the viewport below the 48px header, so a
         full table's opponents scroll in their own column instead of
         stretching the page and pushing your piles below the fold. -->
    <div ref="boardRoot" class="flex min-h-full flex-1 flex-col md:h-[calc(100dvh-3rem)] md:min-h-0 md:flex-none md:flex-row" @dragstart.prevent>
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

        <main class="flex shrink-0 flex-col items-center px-4 pb-3 md:flex-1 md:overflow-y-auto md:px-10 md:py-8">
            <div class="flex w-full flex-col gap-3.5 md:my-auto md:w-[660px] md:max-w-full md:gap-7">
                <div class="flex flex-col gap-2.5 md:gap-4">
                    <!-- Centre -->
                    <section
                        data-zone="centre"
                        class="rounded-[14px] border bg-muted p-3 transition-colors md:rounded-[18px] md:p-[18px]"
                        :class="(pickedCard || selectedCentrePile) && !isDisabled ? 'border-[#F2B42B]' : 'border-border'"
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
                            <span
                                v-else-if="selectedCentrePile?.top_card && !isDisabled"
                                class="truncate text-[13px] font-semibold text-muted-foreground md:text-[15px]"
                            >
                                <span class="md:hidden">Tap it again to cancel</span>
                                <span class="hidden md:inline"
                                    >{{ CLOTHING_TYPE_LABELS[selectedCentrePile.top_card.clothing_type] }} chosen · tap it again to put it back</span
                                >
                            </span>
                        </div>
                        <div
                            ref="centreRow"
                            class="mx-auto mt-2.5 grid max-w-[576px] grid-cols-4 gap-2 md:mt-4 md:gap-4"
                            @pointerdown="drag.start(centreZone, $event)"
                        >
                            <GameCard
                                v-for="pile in gameStore.centerPiles"
                                :key="pile.id"
                                :data-centre-index="pile.pile_index + 1"
                                :card="pile.top_card"
                                :card-style="cardStyle"
                                :state="centreCardState(pile)"
                                :interactive="!isDisabled && !!pile.top_card"
                                class="touch-none select-none"
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
                            <h3 class="text-[11px] font-bold uppercase tracking-[0.1em] text-muted-foreground md:text-xs">Your cards</h3>
                            <div class="flex min-w-0 items-center gap-2">
                                <span v-if="justSwapped" class="text-[13px] font-semibold text-[#0E9C6B] md:text-[15px]">Swapped</span>
                                <span v-else-if="pickedCard" class="truncate text-[13px] font-semibold text-muted-foreground md:text-[15px]">
                                    <span class="md:hidden">Tap {{ pickedItemName }} to cancel</span>
                                    <span class="hidden md:inline"
                                        >{{ CLOTHING_TYPE_LABELS[pickedCard.clothing_type] }} lifted · tap it again to put it back</span
                                    >
                                </span>
                                <span
                                    v-else-if="selectedCentrePile && canSwapFromHand"
                                    class="truncate text-[13px] font-semibold text-[#A86E00] dark:text-[#F2B42B] md:text-[15px]"
                                >
                                    Tap a card to swap it in
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
                        <div
                            ref="handRow"
                            class="relative mx-auto mt-2.5 grid max-w-[576px] grid-cols-4 gap-2 md:mt-4 md:gap-4"
                            @pointerdown="drag.start(handZone, $event)"
                        >
                            <GameCard
                                v-for="(card, index) in handSlots"
                                :key="card?.id ?? `empty-${index}`"
                                :card="card"
                                :card-style="cardStyle"
                                :state="handCardState(card)"
                                :interactive="canSwapFromHand"
                                class="touch-none select-none"
                                @select="card && onSelectHandCard(card)"
                            />
                            <p
                                v-if="!activePile"
                                class="absolute inset-0 flex items-center justify-center px-3 text-center text-[13px] font-semibold text-muted-foreground md:text-[15px]"
                            >
                                {{ selectedCentrePile ? 'Open a pile below and tap the card to trade' : 'Tap a pile below to look inside' }}
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
                        <span v-if="!isDisabled" class="text-[13px] text-muted-foreground md:text-[15px]">Drag to rearrange</span>
                    </div>

                    <!-- A plain row, not a TransitionGroup: dragging animates the move itself,
                         and a FLIP on top would replay it from the old slot. -->
                    <div ref="pilesRow" class="grid grid-cols-6 gap-1.5 md:gap-3" @pointerdown="drag.start(pilesZone, $event)">
                        <PileTile
                            v-for="pile in gameStore.myPiles"
                            :key="pile.id"
                            :data-pile-id="pile.id"
                            :pile="pile"
                            :card-style="cardStyle"
                            :is-open="activePileId === pile.id"
                            :disabled="isDisabled"
                            class="touch-none select-none"
                            @open="onOpenPile"
                        />
                    </div>
                </section>
            </div>
        </main>

        <ClaimPrompt v-if="readyToClaim" :key="claimPromptKey" @claim="claimPiles" />
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

@media (prefers-reduced-motion: reduce) {
    .rank-move {
        transition: none;
    }
}
</style>
