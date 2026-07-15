import { defineStore } from 'pinia';
import { ref } from 'vue';
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
    const countdownEndsAt = ref<string | null>(null);
    const pendingClaim = ref<{ gamePlayerId: number; playerName: string } | null>(null);
    const winner = ref<GameWinner | null>(null);
    const isSwapping = ref(false);

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
    }

    function applyLobbyUpdate(newPlayers: LobbyPlayer[], status: string) {
        players.value = newPlayers;
        if (session.value) {
            session.value.status = status as GameStatus;
        }
    }

    function applyCountdown(startsAt: string) {
        countdownEndsAt.value = startsAt;
        if (session.value) {
            session.value.status = GameStatus.Countdown;
        }
    }

    function applyGameStarted(newCenterPiles: { id: number; pile_index: number; top_card: Card | null }[], allPlayers: OpponentState[]) {
        if (session.value) {
            session.value.status = GameStatus.Playing;
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

    async function swapCard(myPileId: number, myCardId: number, centerPileId: number) {
        if (isSwapping.value || !session.value || !currentPlayer.value) {
            return;
        }

        const myPile = myPiles.value.find((p) => p.id === myPileId);
        const centerPile = centerPiles.value.find((p) => p.id === centerPileId);
        if (!myPile || !centerPile || !centerPile.top_card) {
            return;
        }

        const myCard = myPile.cards.find((c) => c.id === myCardId);
        if (!myCard) {
            return;
        }

        const centerCard = { ...centerPile.top_card };
        const cardIndex = myPile.cards.findIndex((c) => c.id === myCardId);

        isSwapping.value = true;

        // Optimistic update
        myPile.cards.splice(cardIndex, 1, centerCard);
        centerPile.top_card = myCard;

        try {
            const response = await fetch(route('gameplay.swap', { game: session.value.id }), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '',
                    Accept: 'application/json',
                },
                body: JSON.stringify({
                    pile_id: myPile.id,
                    my_card_id: myCardId,
                    center_pile_id: centerPileId,
                    center_card_id: centerCard.id,
                    expected_version: centerPile.version,
                }),
            });

            if (response.status === 409) {
                // Stale version — revert optimistic update
                myPile.cards.splice(cardIndex, 1, myCard);
                centerPile.top_card = centerCard;
                notificationStore.add('Someone else swapped first — try again!', 'warning');
            }
        } catch {
            // Network error — revert
            myPile.cards.splice(cardIndex, 1, myCard);
            centerPile.top_card = centerCard;
            notificationStore.add('Swap failed — check your connection.', 'error');
        } finally {
            isSwapping.value = false;
        }
    }

    function applyCenterCardSwapped(centerPileId: number, version: number, incomingCard: Card) {
        const pile = centerPiles.value.find((p) => p.id === centerPileId);
        if (pile) {
            pile.version = version;
            // Only update top_card if it differs — our optimistic update may have already set it
            if (!pile.top_card || pile.top_card.id !== incomingCard.id) {
                pile.top_card = incomingCard;
            }
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
    }

    function applyGameEnded(gameWinner: GameWinner) {
        if (session.value) {
            session.value.status = GameStatus.Ended;
            session.value.winner_user_id = gameWinner.user_id;
        }
        winner.value = gameWinner;
        pendingClaim.value = null;
    }

    function applyGameResumed(claimantName: string) {
        if (session.value) {
            session.value.status = GameStatus.Playing;
        }
        pendingClaim.value = null;
        notificationStore.add(`${claimantName}'s PILES! claim was invalid — game resumes!`, 'warning');
    }

    async function claimPiles() {
        if (!session.value) {
            return;
        }
        await fetch(route('gameplay.claim', { game: session.value.id }), {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '',
                Accept: 'application/json',
            },
        });
    }

    return {
        session,
        currentPlayer,
        players,
        myPiles,
        centerPiles,
        opponents,
        countdownEndsAt,
        pendingClaim,
        winner,
        isSwapping,
        initialize,
        applyLobbyUpdate,
        applyCountdown,
        applyGameStarted,
        applyHandDealt,
        swapCard,
        applyCenterCardSwapped,
        applyPileCompleted,
        applyClaimMade,
        applyGameEnded,
        applyGameResumed,
        claimPiles,
    };
});