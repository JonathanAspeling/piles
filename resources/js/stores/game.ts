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
    const pendingClaim = ref<{ gamePlayerId: number; playerName: string } | null>(null);
    const winner = ref<GameWinner | null>(null);
    const forfeitedBy = ref<string | null>(null);
    const isSwapping = ref(false);
    const myPickedUpCard = ref<Card | null>(null);
    const myPickedUpPileId = ref<number | null>(null);
    let verifyingTimeoutHandle: ReturnType<typeof setTimeout> | null = null;

    function clearVerifyingTimeout() {
        if (verifyingTimeoutHandle) {
            clearTimeout(verifyingTimeoutHandle);
            verifyingTimeoutHandle = null;
        }
    }

    async function reconcileStatus() {
        if (!session.value) {
            return;
        }
        if (session.value.status !== GameStatus.Verifying) {
            return;
        }
        try {
            const response = await apiFetch(route('games.status', { game: session.value.id }));
            if (!response.ok) {
                notificationStore.add('Sync issue — please refresh.', 'error');
                return;
            }
            const data: { status: string; winner: GameWinner | null } = await response.json();
            if (data.status === GameStatus.Playing) {
                applyGameResumed(pendingClaim.value?.playerName ?? 'A player');
            } else if (data.status === GameStatus.Ended) {
                applyGameEnded(data.winner);
            } else {
                notificationStore.add('Sync issue — please refresh.', 'error');
            }
        } catch {
            notificationStore.add('Sync issue — please refresh.', 'error');
        }
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
        // Rejoin mid-countdown: data already dealt server-side, confirm immediately
        if (gameData.status === GameStatus.Countdown && myPilesData.length > 0) {
            confirmClientReady();
        }
    }

    function applyLobbyUpdate(newPlayers: LobbyPlayer[], status: string) {
        players.value = newPlayers;
        if (session.value) {
            session.value.status = status as GameStatus;
        }
    }

    function applyCountdown(durationMs: number) {
        countdownDurationMs.value = durationMs;
        countdownStartedAtLocalMs.value = Date.now();
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
        opponents.value = allPlayers
            .filter((p) => p.id !== currentPlayer.value?.id)
            .map((p) => ({ ...p, picked_up_card: null }));
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

    async function pickUpCard(cardId: number, pileId: number) {
        if (!session.value || !currentPlayer.value) {
            return;
        }

        const myPile = myPiles.value.find((p) => p.id === pileId);
        const card = myPile?.cards.find((c) => c.id === cardId);
        if (!myPile || !card) {
            return;
        }

        const cardIndex = myPile.cards.findIndex((c) => c.id === cardId);
        myPile.cards.splice(cardIndex, 1);
        myPickedUpCard.value = card;
        myPickedUpPileId.value = pileId;

        window.Echo.join(`game.${session.value.id}`).whisper('card-picked-up', {
            game_player_id: currentPlayer.value.id,
            card,
        });

        try {
            await apiFetch(route('gameplay.pickup', { game: session.value.id }), {
                method: 'POST',
                body: JSON.stringify({ card_id: cardId }),
            });
        } catch {
            myPile.cards.splice(cardIndex, 0, card);
            myPickedUpCard.value = null;
            myPickedUpPileId.value = null;
            window.Echo.join(`game.${session.value.id}`).whisper('card-pickup-cancelled', {
                game_player_id: currentPlayer.value.id,
            });
            notificationStore.add('Failed to pick up card — check your connection.', 'error');
        }
    }

    async function swapCard(centerPileId: number, centerCardId: number, expectedVersion: number) {
        if (isSwapping.value || !session.value || !currentPlayer.value || !myPickedUpCard.value || myPickedUpPileId.value === null) {
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
        const actorPlayerId = currentPlayer.value.id;

        isSwapping.value = true;

        if (myPile) {
            myPile.cards.push(centerCard);
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
            game_player_id: actorPlayerId,
        });

        const revert = () => {
            if (myPile) {
                myPile.cards.pop();
            }
            centerPile.top_card = centerCard;
            centerPile.version = previousVersion;
            myPickedUpCard.value = heldCard;
            myPickedUpPileId.value = myPile?.id ?? null;
            window.Echo.join(`game.${session.value!.id}`).whisper('swap-cancelled', {
                center_pile_id: centerPileId,
                previous_top_card: centerCard,
                previous_version: previousVersion,
                held_card: heldCard,
                game_player_id: actorPlayerId,
            });
        };

        try {
            const response = await apiFetch(route('gameplay.swap', { game: session.value.id }), {
                method: 'POST',
                body: JSON.stringify({
                    center_pile_id: centerPileId,
                    center_card_id: centerCardId,
                    expected_version: expectedVersion,
                }),
            });

            if (response.status === 409) {
                revert();
                notificationStore.add('Someone else swapped first — try again!', 'warning');
            }
        } catch {
            revert();
            notificationStore.add('Swap failed — check your connection.', 'error');
        } finally {
            isSwapping.value = false;
        }
    }

    function applyCardPickedUp(gamePlayerId: number, card: Card) {
        const opponent = opponents.value.find((o) => o.id === gamePlayerId);
        if (opponent) {
            opponent.picked_up_card = card;
        }
    }

    function applyCenterCardSwapped(centerPileId: number, version: number, incomingCard: Card, gamePlayerId: number) {
        const pile = centerPiles.value.find((p) => p.id === centerPileId);
        if (pile) {
            pile.version = version;
            if (!pile.top_card || pile.top_card.id !== incomingCard.id) {
                pile.top_card = incomingCard;
            }
        }
        const opponent = opponents.value.find((o) => o.id === gamePlayerId);
        if (opponent) {
            opponent.picked_up_card = null;
        }
    }

    function applySwapCancelled(centerPileId: number, previousTopCard: Card, previousVersion: number, heldCard: Card, gamePlayerId: number) {
        const pile = centerPiles.value.find((p) => p.id === centerPileId);
        if (pile) {
            pile.top_card = previousTopCard;
            pile.version = previousVersion;
        }
        const opponent = opponents.value.find((o) => o.id === gamePlayerId);
        if (opponent) {
            opponent.picked_up_card = heldCard;
        }
    }

    function applyCardPickupCancelled(gamePlayerId: number) {
        const opponent = opponents.value.find((o) => o.id === gamePlayerId);
        if (opponent) {
            opponent.picked_up_card = null;
        }
    }

    function applyPileCompleted(gamePlayerId: number, pileId: number, cards: Card[]) {
        if (currentPlayer.value && gamePlayerId === currentPlayer.value.id) {
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
                }
            }
        }
    }

    function applyClaimMade(gamePlayerId: number, playerName: string) {
        if (session.value) {
            session.value.status = GameStatus.Verifying;
        }
        pendingClaim.value = { gamePlayerId, playerName };
        clearVerifyingTimeout();
        verifyingTimeoutHandle = setTimeout(() => {
            void reconcileStatus();
        }, 15000);
    }

    function applyGameEnded(gameWinner: GameWinner | null, forfeitBy?: string) {
        if (session.value) {
            session.value.status = GameStatus.Ended;
            session.value.winner_user_id = gameWinner?.user_id ?? null;
        }
        winner.value = gameWinner;
        forfeitedBy.value = forfeitBy ?? null;
        pendingClaim.value = null;
        clearVerifyingTimeout();
    }

    function applyGameResumed(claimantName: string) {
        if (session.value) {
            session.value.status = GameStatus.Playing;
        }
        pendingClaim.value = null;
        clearVerifyingTimeout();
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
        pendingClaim,
        winner,
        forfeitedBy,
        isSwapping,
        myPickedUpCard,
        myPickedUpPileId,
        initialize,
        applyLobbyUpdate,
        applyCountdown,
        applyGameStarted,
        applyHandDealt,
        confirmClientReady,
        applyGameActivated,
        pickUpCard,
        swapCard,
        applyCardPickedUp,
        applyCardPickupCancelled,
        applyCenterCardSwapped,
        applySwapCancelled,
        applyPileCompleted,
        applyClaimMade,
        applyGameEnded,
        applyGameResumed,
        claimPiles,
        forfeit,
    };
});
