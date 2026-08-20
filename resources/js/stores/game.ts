import { defineStore } from 'pinia';
import { ref } from 'vue';
import { apiFetch } from '../composables/useFetch';
import type { Card, CenterPile, GamePlayer, GameSession, GameWinner, LobbyPlayer, OpponentState, PlayerPile } from '../types/game';
import { GameStatus } from '../types/game';
import { useNotificationStore } from './notification';

export const useGameStore = defineStore('game', () => {
    const notificationStore = useNotificationStore();

    const session = ref<GameSession | null>(null);
    const currentPlayer = ref<GamePlayer | null>(null);
    const players = ref<LobbyPlayer[]>([]);
    const myPiles = ref<PlayerPile[]>([]);
    const centerPiles = ref<CenterPile[]>([]);
    const opponents = ref<OpponentState[]>([]);
    const countdownDurationMs = ref<number | null>(null);
    const countdownStartedAtLocalMs = ref<number | null>(null);
    // Server clock minus local clock at the moment GameCountdownStarted arrived.
    // Added to Date.now() to reason about the countdown in server time and
    // keep all clients visually aligned regardless of receipt latency.
    const countdownServerOffsetMs = ref<number>(0);
    const winner = ref<GameWinner | null>(null);
    const forfeitedBy = ref<string | null>(null);
    const resyncInFlight = ref(false);
    const myPickedUpCard = ref<Card | null>(null);
    const myPickedUpPileId = ref<number | null>(null);
    // User IDs currently subscribed to the presence channel. Seeded from
    // .here() on subscribe and kept live by .joining() / .leaving() in
    // useEchoStore. Not persisted — Reverb-restart-tolerant by design.
    const connectedUserIds = ref<number[]>([]);
    // Serializes gameplay HTTP so the server always sees requests in the
    // exact order the user made them. Parallel fetches let a swap arrive
    // before its pickup (or before a prior swap), which the server rejects
    // as "you have not picked up a card" or corrupts state.
    let gameplayChain: Promise<unknown> = Promise.resolve();

    function serializeGameplay<T>(fn: () => Promise<T>): Promise<T> {
        const result = gameplayChain.then(fn, fn);
        // Prevent a rejected chain from poisoning future requests.
        gameplayChain = result.then(
            () => undefined,
            () => undefined,
        );
        return result;
    }

    function initialize(
        gameData: GameSession,
        currentPlayerData: GamePlayer | null,
        playersData: LobbyPlayer[],
        myPilesData: PlayerPile[],
        centerPilesData: CenterPile[],
        opponentsData: OpponentState[],
    ) {
        session.value = gameData;
        currentPlayer.value = currentPlayerData;
        players.value = playersData;
        myPiles.value = myPilesData;
        centerPiles.value = centerPilesData;
        opponents.value = opponentsData;
        // Reset presence — the fresh subscribe's .here() will repopulate.
        connectedUserIds.value = [];
        // Rejoin mid-countdown: data already dealt server-side, confirm immediately
        if (gameData.status === GameStatus.Countdown && myPilesData.length > 0) {
            confirmClientReady();
        }
    }

    function setConnectedUsers(userIds: number[]) {
        connectedUserIds.value = [...new Set(userIds)];
    }

    function markConnected(userId: number) {
        if (!connectedUserIds.value.includes(userId)) {
            connectedUserIds.value = [...connectedUserIds.value, userId];
        }
    }

    function markDisconnected(userId: number) {
        connectedUserIds.value = connectedUserIds.value.filter((id) => id !== userId);
    }

    function applyLobbyUpdate(newPlayers: LobbyPlayer[], status: string) {
        players.value = newPlayers;
        if (session.value) {
            session.value.status = status as GameStatus;
        }
    }

    function applyCountdown(durationMs: number, serverTimeMs?: number) {
        const now = Date.now();
        countdownDurationMs.value = durationMs;
        countdownStartedAtLocalMs.value = now;
        countdownServerOffsetMs.value = typeof serverTimeMs === 'number' ? serverTimeMs - now : 0;
        // Do not downgrade an already-Playing client back into Countdown —
        // handles a late-arriving GameCountdownStarted after GameActivated.
        if (session.value && session.value.status !== GameStatus.Playing && session.value.status !== GameStatus.Ended) {
            session.value.status = GameStatus.Countdown;
        }
    }

    function applyGameStarted(newCenterPiles: { id: number; pile_index: number; top_card: Card | null }[], allPlayers: OpponentState[]) {
        if (session.value) {
            session.value.status = GameStatus.Countdown;
        }
        centerPiles.value = newCenterPiles.map((cp) => ({
            id: cp.id,
            pile_index: cp.pile_index,
            version: 0,
            top_card: cp.top_card,
        }));
        opponents.value = allPlayers.filter((p) => p.id !== currentPlayer.value?.id);
    }

    function applyHandDealt(piles: PlayerPile[]) {
        myPiles.value = piles;
    }

    async function confirmClientReady() {
        if (!session.value) {
            return;
        }
        await apiFetch(route('games.client-ready', { game: session.value.id }), { method: 'POST' });
    }

    function applyGameActivated() {
        if (session.value) {
            session.value.status = GameStatus.Playing;
        }
    }

    /**
     * Mark a card as the one to discard. The card deliberately stays in its
     * pile — the UI raises it in place rather than moving it to a hand slot —
     * so only the selection refs change here.
     */
    async function pickUpCard(cardId: number, pileId: number) {
        if (!session.value || !currentPlayer.value) {
            return;
        }

        const myPile = myPiles.value.find((p) => p.id === pileId);
        const card = myPile?.cards.find((c) => c.id === cardId);
        if (!myPile || !card) {
            return;
        }

        const previousCard = myPickedUpCard.value;
        const previousPileId = myPickedUpPileId.value;
        myPickedUpCard.value = card;
        myPickedUpPileId.value = pileId;

        const revert = () => {
            myPickedUpCard.value = previousCard;
            myPickedUpPileId.value = previousPileId;
        };

        try {
            const response = await serializeGameplay(() =>
                apiFetch(route('gameplay.pickup', { game: session.value!.id }), {
                    method: 'POST',
                    body: JSON.stringify({ card_id: cardId }),
                }),
            );
            if (!response.ok) {
                revert();
                notificationStore.add('Pickup rejected — please try again.', 'warning');
            }
        } catch {
            revert();
            notificationStore.add('Failed to pick up card — check your connection.', 'error');
        }
    }

    /**
     * Put the selected card back down. Fired by tapping the raised card again,
     * or by opening a different pile / closing the pile viewer.
     */
    async function releaseCard() {
        if (!session.value || !myPickedUpCard.value) {
            return;
        }

        const previousCard = myPickedUpCard.value;
        const previousPileId = myPickedUpPileId.value;
        myPickedUpCard.value = null;
        myPickedUpPileId.value = null;

        try {
            const response = await serializeGameplay(() =>
                apiFetch(route('gameplay.release', { game: session.value!.id }), { method: 'POST' }),
            );
            if (!response.ok) {
                myPickedUpCard.value = previousCard;
                myPickedUpPileId.value = previousPileId;
            }
        } catch {
            myPickedUpCard.value = previousCard;
            myPickedUpPileId.value = previousPileId;
            notificationStore.add('Failed to put the card back — check your connection.', 'error');
        }
    }

    async function swapCard(centerPileId: number, centerCardId: number, expectedVersion: number) {
        if (!session.value || !currentPlayer.value || !myPickedUpCard.value || myPickedUpPileId.value === null) {
            return;
        }

        const myPile = myPiles.value.find((p) => p.id === myPickedUpPileId.value);
        const centerPile = centerPiles.value.find((p) => p.id === centerPileId);
        if (!centerPile || !centerPile.top_card) {
            return;
        }

        const heldCard = myPickedUpCard.value;
        const centerCard = { ...centerPile.top_card };
        const previousVersion = centerPile.version;
        const nextVersion = expectedVersion + 1;
        // The held card never left its pile, so the incoming card takes its
        // exact slot — cards don't jump position mid-game.
        const heldCardIndex = myPile?.cards.findIndex((c) => c.id === heldCard.id) ?? -1;

        if (myPile && heldCardIndex !== -1) {
            myPile.cards.splice(heldCardIndex, 1, centerCard);
        }
        centerPile.top_card = heldCard;
        centerPile.version = nextVersion;
        myPickedUpCard.value = null;
        myPickedUpPileId.value = null;

        window.Echo.join(`game.${session.value.id}`).whisper('center-card-swapped', {
            center_pile_id: centerPileId,
            center_pile_version: nextVersion,
            incoming_card: heldCard,
            outgoing_card_id: centerCardId,
        });

        const revert = () => {
            // Find the incoming card by id now rather than reusing heldCardIndex:
            // another swap may have landed in that slot while this one was in
            // flight, and writing over it by position is what used to leave a
            // pile looking like a completed set the server disagreed with. If the
            // card is already gone, leave the array alone — resyncBoard() is
            // authoritative.
            const incomingIndex = myPile?.cards.findIndex((c) => c.id === centerCard.id) ?? -1;
            if (myPile && incomingIndex !== -1) {
                myPile.cards.splice(incomingIndex, 1, heldCard);
            }
            centerPile.top_card = centerCard;
            centerPile.version = previousVersion;
            myPickedUpCard.value = heldCard;
            myPickedUpPileId.value = myPile?.id ?? null;
            window.Echo.join(`game.${session.value!.id}`).whisper('swap-cancelled', {
                center_pile_id: centerPileId,
                previous_top_card: centerCard,
                previous_version: previousVersion,
            });
        };

        try {
            const response = await serializeGameplay(() =>
                apiFetch(route('gameplay.swap', { game: session.value!.id }), {
                    method: 'POST',
                    body: JSON.stringify({
                        center_pile_id: centerPileId,
                        center_card_id: centerCardId,
                        expected_version: expectedVersion,
                    }),
                }),
            );

            if (response.status === 409) {
                revert();
                notificationStore.add('Someone else swapped first — try again!', 'warning');
                resyncBoard();
            } else if (!response.ok) {
                revert();
                notificationStore.add('Swap failed — try again.', 'error');
                resyncBoard();
            }
        } catch {
            revert();
            notificationStore.add('Swap failed — check your connection.', 'error');
            resyncBoard();
        }
    }

    /**
     * Replace the centre and the player's own hand with the server's view.
     *
     * Chained through serializeGameplay so it can't overtake a queued swap —
     * that ordering is what makes replacing myPiles wholesale safe, since no
     * optimistic mutation can still be waiting behind it.
     */
    async function resyncBoard() {
        if (resyncInFlight.value || !session.value) {
            return;
        }
        resyncInFlight.value = true;
        try {
            const response = await serializeGameplay(() => apiFetch(route('gameplay.state', { game: session.value!.id })));
            if (!response.ok) {
                return;
            }
            const fresh: {
                center_piles: { id: number; pile_index: number; version: number; top_card: Card | null }[];
                my_piles: PlayerPile[];
            } = await response.json();

            for (const pile of fresh.center_piles) {
                const existing = centerPiles.value.find((p) => p.id === pile.id);
                if (existing) {
                    existing.version = pile.version;
                    existing.top_card = pile.top_card;
                }
            }

            myPiles.value = fresh.my_piles;
        } catch {
            // Silent — next swap or manual refresh will recover.
        } finally {
            resyncInFlight.value = false;
        }
    }

    function applyCenterCardSwapped(centerPileId: number, version: number, incomingCard: Card) {
        const pile = centerPiles.value.find((p) => p.id === centerPileId);
        if (pile) {
            pile.version = version;
            if (!pile.top_card || pile.top_card.id !== incomingCard.id) {
                pile.top_card = incomingCard;
            }
        }
    }

    function applySwapCancelled(centerPileId: number, previousTopCard: Card, previousVersion: number) {
        const pile = centerPiles.value.find((p) => p.id === centerPileId);
        if (pile) {
            pile.top_card = previousTopCard;
            pile.version = previousVersion;
        }
    }

    function applyPileCompleted(gamePlayerId: number, pileId: number, cards: Card[], playerName: string, completedCount: number) {
        if (currentPlayer.value && gamePlayerId === currentPlayer.value.id) {
            const pile = myPiles.value.find((p) => p.id === pileId);
            if (pile) {
                pile.is_completed = true;
                pile.cards = cards;
            }
            notificationStore.add(`You completed pile ${completedCount} of 6`, 'success', 2500);
        } else {
            const opponent = opponents.value.find((o) => o.id === gamePlayerId);
            if (opponent) {
                const pile = opponent.piles.find((p) => p.id === pileId);
                if (pile) {
                    pile.is_completed = true;
                    // All four cards share a clothing type by definition of a
                    // completed pile, so the first one names the set.
                    pile.completed_type = cards[0]?.clothing_type ?? null;
                }
            }
            notificationStore.add(`${playerName} completed pile ${completedCount} of 6`, 'success', 2500);
        }
    }

    function applyGameEnded(gameWinner: GameWinner | null, forfeitBy?: string) {
        if (session.value) {
            session.value.status = GameStatus.Ended;
            session.value.winner_user_id = gameWinner?.user_id ?? null;
        }
        winner.value = gameWinner;
        forfeitedBy.value = forfeitBy ?? null;
    }

    function applyGameResumed(claimantName: string) {
        if (session.value) {
            session.value.status = GameStatus.Playing;
        }
        notificationStore.add(`${claimantName}'s PILES! claim was invalid — game resumes!`, 'warning');
    }

    async function claimPiles() {
        if (!session.value) {
            return;
        }
        await apiFetch(route('gameplay.claim', { game: session.value.id }), { method: 'POST' });
    }

    async function forfeit() {
        if (!session.value) {
            return;
        }
        await apiFetch(route('gameplay.forfeit', { game: session.value.id }), { method: 'POST' });
    }

    return {
        session,
        currentPlayer,
        players,
        myPiles,
        centerPiles,
        opponents,
        countdownDurationMs,
        countdownStartedAtLocalMs,
        countdownServerOffsetMs,
        winner,
        forfeitedBy,
        myPickedUpCard,
        myPickedUpPileId,
        connectedUserIds,
        initialize,
        setConnectedUsers,
        markConnected,
        markDisconnected,
        applyLobbyUpdate,
        applyCountdown,
        applyGameStarted,
        applyHandDealt,
        confirmClientReady,
        applyGameActivated,
        pickUpCard,
        releaseCard,
        swapCard,
        resyncBoard,
        applyCenterCardSwapped,
        applySwapCancelled,
        applyPileCompleted,
        applyGameEnded,
        applyGameResumed,
        claimPiles,
        forfeit,
    };
});
