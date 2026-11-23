import { router } from '@inertiajs/vue3';
import { defineStore } from 'pinia';
import { computed, ref, toRaw } from 'vue';
import { apiFetch } from '../composables/useFetch';
import type { Card, CenterPile, GamePlayer, GameSession, GameWinner, LobbyPlayer, OpponentState, PlayerPile, RematchState } from '../types/game';
import { GameStatus } from '../types/game';
import { preloadDealtCardArt } from '../utils/cardArt';
import { useNotificationStore } from './notification';

export const useGameStore = defineStore('game', () => {
    const notificationStore = useNotificationStore();

    const session = ref<GameSession | null>(null);
    const currentPlayer = ref<GamePlayer | null>(null);
    const players = ref<LobbyPlayer[]>([]);
    const myPiles = ref<PlayerPile[]>([]);
    const centerPiles = ref<CenterPile[]>([]);
    const opponents = ref<OpponentState[]>([]);
    // Not reactive: bookkeeping for confirmClientReadyIfDealt(), never rendered.
    let clientReadyConfirmed = false;
    let clientReadyInFlight = false;
    const CLIENT_READY_ATTEMPTS = 6;
    const countdownDurationMs = ref<number | null>(null);
    const countdownStartedAtLocalMs = ref<number | null>(null);
    // Server clock minus local clock at the moment GameCountdownStarted arrived.
    // Added to Date.now() to reason about the countdown in server time and
    // keep all clients visually aligned regardless of receipt latency.
    const countdownServerOffsetMs = ref<number>(0);
    const winner = ref<GameWinner | null>(null);
    const forfeitedBy = ref<string | null>(null);
    const rematch = ref<RematchState>({ rematch_game_id: null, user_ids: [] });
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

    // Your own row, shaped like an opponent's so the scoreboard can render
    // everyone through one component. Only completed piles surrender a clothing
    // type, matching what the server tells opponents about you.
    const selfRow = computed<OpponentState | null>(() => {
        if (!currentPlayer.value) {
            return null;
        }

        const me = currentPlayer.value;

        return {
            id: me.id,
            user_id: me.user_id,
            name: players.value.find((p) => p.id === me.id)?.name ?? 'You',
            seat_index: me.seat_index,
            piles: myPiles.value.map((pile) => ({
                id: pile.id,
                pile_index: pile.pile_index,
                is_completed: pile.is_completed,
                completed_type: pile.is_completed ? (pile.cards[0]?.clothing_type ?? null) : null,
            })),
        };
    });

    // Everyone ranked by piles done. seat_index breaks ties so the order is
    // identical on every client and never jitters between equal players.
    const leaderboard = computed<OpponentState[]>(() => {
        const rows = selfRow.value ? [...opponents.value, selfRow.value] : [...opponents.value];
        const done = (row: OpponentState) => row.piles.filter((p) => p.is_completed).length;

        return rows.sort((a, b) => done(b) - done(a) || a.seat_index - b.seat_index);
    });

    /**
     * Warm the card art as soon as cards are dealt. The board only mounts at
     * GO, so this uses the countdown to fetch what's about to be drawn.
     */
    function preloadGameCardArt() {
        if (!session.value) {
            return;
        }
        const visibleCards = [
            ...myPiles.value.flatMap((pile) => pile.cards),
            ...centerPiles.value.flatMap((pile) => (pile.top_card ? [pile.top_card] : [])),
        ];
        preloadDealtCardArt(visibleCards, session.value.theme);
    }

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
        rematchData: RematchState = { rematch_game_id: null, user_ids: [] },
    ) {
        session.value = gameData;
        rematch.value = rematchData;
        currentPlayer.value = currentPlayerData;
        players.value = playersData;
        myPiles.value = myPilesData;
        centerPiles.value = centerPilesData;
        opponents.value = opponentsData;
        // Reset presence — the fresh subscribe's .here() will repopulate.
        connectedUserIds.value = [];
        clientReadyConfirmed = false;
        preloadGameCardArt();
        // Rejoin mid-countdown: data already dealt server-side, confirm immediately
        confirmClientReadyIfDealt();
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
        preloadGameCardArt();
        confirmClientReadyIfDealt();
    }

    function applyHandDealt(piles: PlayerPile[]) {
        myPiles.value = piles;
        preloadGameCardArt();
        confirmClientReadyIfDealt();
    }

    /**
     * Tell the server this screen has everything it needs to play. The
     * countdown waits for every player's confirmation, so it's only sent once
     * both the centre and this player's hand have arrived (they come in
     * separate broadcasts), and retried until the server takes it: one lost
     * request would otherwise hold the whole table at "Get ready…".
     */
    async function confirmClientReadyIfDealt() {
        const isDealt = centerPiles.value.length > 0 && myPiles.value.length > 0;
        if (!session.value || session.value.status !== GameStatus.Countdown || !isDealt || clientReadyConfirmed || clientReadyInFlight) {
            return;
        }
        clientReadyInFlight = true;
        const gameId = session.value.id;
        try {
            for (let attempt = 1; attempt <= CLIENT_READY_ATTEMPTS; attempt++) {
                try {
                    const response = await apiFetch(route('games.client-ready', { game: gameId }), { method: 'POST' });
                    if (response.ok) {
                        clientReadyConfirmed = true;
                        return;
                    }
                    // 422: the game has moved past the countdown; nothing left to confirm.
                    if (response.status === 422) {
                        return;
                    }
                } catch {
                    // Network blip: fall through to the retry.
                }
                await new Promise((resolve) => setTimeout(resolve, attempt * 500));
            }
        } finally {
            clientReadyInFlight = false;
        }
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
            const response = await serializeGameplay(() => apiFetch(route('gameplay.release', { game: session.value!.id }), { method: 'POST' }));
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
        // Only this screen shows the swap before the server confirms it. Everyone
        // else waits for CenterPileSwapped, so no screen ever shows a guess.

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
            // Only undo our own optimistic card. If the winner's swap has already
            // been confirmed here, rolling back would bury it under a stale card.
            if (centerPile.version === nextVersion && centerPile.top_card?.id === heldCard.id) {
                centerPile.top_card = centerCard;
                centerPile.version = previousVersion;
            }
            myPickedUpCard.value = heldCard;
            myPickedUpPileId.value = myPile?.id ?? null;
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
     * Rearrange your own piles. Presentational only — nothing in the rules reads
     * pile order — but it is persisted so it survives a refresh.
     */
    async function reorderPiles(orderedPileIds: number[]) {
        if (!session.value) {
            return;
        }

        // Copy the array but keep the same pile objects, so a card mutation
        // landing mid-flight isn't thrown away by the rollback below.
        const snapshot = [...myPiles.value];

        const reordered = orderedPileIds.map((id) => myPiles.value.find((p) => p.id === id)).filter((p): p is PlayerPile => p !== undefined);

        if (reordered.length !== myPiles.value.length) {
            return;
        }

        const renumber = (piles: PlayerPile[]) => piles.forEach((pile, index) => (pile.pile_index = index));

        renumber(reordered);
        myPiles.value = reordered;

        const revert = (message: string) => {
            myPiles.value = snapshot;
            renumber(snapshot);
            notificationStore.add(message, 'error');
        };

        try {
            const response = await serializeGameplay(() =>
                apiFetch(route('gameplay.reorder-piles', { game: session.value!.id }), {
                    method: 'POST',
                    body: JSON.stringify({ pile_ids: orderedPileIds }),
                }),
            );

            if (!response.ok) {
                revert('Could not reorder your piles — try again.');
            }
        } catch {
            revert('Could not reorder your piles — check your connection.');
        }
    }

    /**
     * Rearrange the cards within one of your piles. Presentational like pile
     * order, and persisted the same way. A swap that lands first changes the
     * pile's cards, so the server answers 409 and the board resyncs.
     */
    async function reorderPileCards(pileId: number, orderedCardIds: number[]) {
        const pile = myPiles.value.find((p) => p.id === pileId);
        if (!session.value || !pile) {
            return;
        }

        const reordered = orderedCardIds.map((id) => pile.cards.find((c) => c.id === id)).filter((c): c is Card => c !== undefined);
        if (reordered.length !== pile.cards.length) {
            return;
        }

        const snapshot = pile.cards;
        pile.cards = reordered;

        const revert = (message: string) => {
            // Only undo our own order: if a swap has since changed the pile, the resync owns it.
            if (toRaw(pile.cards) === reordered) {
                pile.cards = snapshot;
            }
            notificationStore.add(message, 'error');
        };

        try {
            const response = await serializeGameplay(() =>
                apiFetch(route('gameplay.reorder-pile-cards', { game: session.value!.id, pile: pileId }), {
                    method: 'POST',
                    body: JSON.stringify({ card_ids: orderedCardIds }),
                }),
            );

            if (response.status === 409) {
                resyncBoard();
            } else if (!response.ok) {
                revert('Could not rearrange your cards — try again.');
            }
        } catch {
            revert('Could not rearrange your cards — check your connection.');
        }
    }

    /**
     * Replace the centre and the player's own hand with the server's view.
     *
     * Chained through serializeGameplay so it can't overtake a queued swap —
     * that ordering is what makes replacing myPiles wholesale safe, since no
     * optimistic mutation can still be waiting behind it.
     */
    /**
     * Catch up with the server's game: status, centre, hand and opponents.
     *
     * Broadcasts aren't replayed, so a screen that subscribed late, dropped
     * its connection, or lost a swap race calls this instead of trusting what
     * it has. It never moves a centre pile back to an older version (a newer
     * CenterPileSwapped can overtake the response) or the game back a phase.
     */
    async function resyncBoard() {
        if (resyncInFlight.value || !session.value) {
            return;
        }
        resyncInFlight.value = true;
        const gameId = session.value.id;
        try {
            const response = await serializeGameplay(() => apiFetch(route('gameplay.state', { game: gameId })));
            if (!response.ok) {
                return;
            }
            const fresh: {
                status: GameStatus;
                center_piles: CenterPile[];
                my_piles: PlayerPile[];
                opponents: OpponentState[];
            } = await response.json();

            const sameCentre =
                centerPiles.value.length === fresh.center_piles.length &&
                fresh.center_piles.every((pile) => centerPiles.value.some((existing) => existing.id === pile.id));
            if (!sameCentre) {
                centerPiles.value = fresh.center_piles;
            } else {
                for (const pile of fresh.center_piles) {
                    const existing = centerPiles.value.find((p) => p.id === pile.id)!;
                    if (pile.version >= existing.version) {
                        existing.version = pile.version;
                        existing.top_card = pile.top_card;
                    }
                }
            }

            myPiles.value = fresh.my_piles;
            if (fresh.opponents.length > 0) {
                opponents.value = fresh.opponents;
            }

            await catchUpStatus(fresh.status);
            preloadGameCardArt();
            confirmClientReadyIfDealt();
        } catch {
            // Silent — the next trigger (swap, reconnect, watchdog) will recover.
        } finally {
            resyncInFlight.value = false;
        }
    }

    const PHASE_ORDER: GameStatus[] = [GameStatus.Lobby, GameStatus.Countdown, GameStatus.Playing, GameStatus.Ended];

    /** Moves this screen forward to the server's phase if it missed the broadcast that would have. */
    async function catchUpStatus(serverStatus: GameStatus) {
        if (!session.value || serverStatus === session.value.status) {
            return;
        }
        // Verifying is a pause within Playing, in either direction.
        const phase = (status: GameStatus) => PHASE_ORDER.indexOf(status === GameStatus.Verifying ? GameStatus.Playing : status);
        if (phase(serverStatus) < phase(session.value.status)) {
            return;
        }
        if (serverStatus === GameStatus.Ended) {
            const status = await apiFetch(route('games.status', { game: session.value.id }));
            const { winner: gameWinner } = (await status.json()) as { winner: GameWinner | null };
            applyGameEnded(gameWinner);

            return;
        }
        session.value.status = serverStatus;
    }

    /**
     * The server's committed result of a swap. It applies unless this screen
     * already shows a newer version of the pile; at the same version it
     * replaces this player's own unconfirmed swap, which lost the race.
     */
    function applyCenterPileConfirmed(centerPileId: number, version: number, topCard: Card) {
        const pile = centerPiles.value.find((p) => p.id === centerPileId);
        if (pile && version >= pile.version) {
            pile.version = version;
            pile.top_card = topCard;
        }
    }

    function applyPileCompleted(gamePlayerId: number, pileId: number, cards: Card[]) {
        const isSelf = !!currentPlayer.value && gamePlayerId === currentPlayer.value.id;

        if (isSelf) {
            const pile = myPiles.value.find((p) => p.id === pileId);
            if (pile) {
                pile.is_completed = true;
                pile.cards = cards;
            }
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

    function applyRematchUpdate(rematchData: RematchState) {
        rematch.value = rematchData;
    }

    /**
     * Join the ended game's rematch lobby (opening it if you're first), then
     * go there. Returns false if the server turned you away.
     */
    async function requestRematch(): Promise<boolean> {
        if (!session.value) {
            return false;
        }
        const response = await apiFetch(route('games.rematch', { game: session.value.id }), { method: 'POST' });
        if (!response.ok) {
            const data = await response.json().catch(() => ({}));
            notificationStore.add(data.message ?? 'Could not start a rematch.', 'warning');
            return false;
        }
        const data = await response.json();
        router.visit(route('games.show', { game: data.game.id }));
        return true;
    }

    /** Call PILES! Resolves whether the server took the claim; the result itself arrives as GameEnded. */
    async function claimPiles(): Promise<boolean> {
        if (!session.value) {
            return false;
        }
        try {
            return (await apiFetch(route('gameplay.claim', { game: session.value.id }), { method: 'POST' })).ok;
        } catch {
            return false;
        }
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
        rematch,
        myPickedUpCard,
        myPickedUpPileId,
        connectedUserIds,
        leaderboard,
        initialize,
        setConnectedUsers,
        markConnected,
        markDisconnected,
        applyLobbyUpdate,
        applyCountdown,
        applyGameStarted,
        applyHandDealt,
        confirmClientReadyIfDealt,
        applyGameActivated,
        pickUpCard,
        releaseCard,
        swapCard,
        reorderPiles,
        reorderPileCards,
        resyncBoard,
        applyCenterPileConfirmed,
        applyPileCompleted,
        applyGameEnded,
        applyGameResumed,
        applyRematchUpdate,
        requestRematch,
        claimPiles,
        forfeit,
    };
});
