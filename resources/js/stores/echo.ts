import { defineStore } from 'pinia';
import { ref } from 'vue';
import type { Card, GameWinner, LobbyPlayer, OpponentState, PlayerPile } from '../types/game';
import { useGameStore } from './game';

export const useEchoStore = defineStore('echo', () => {
    const gameStore = useGameStore();
    const isConnected = ref(false);

    function subscribe(gameId: number, playerId: number) {
        window.Echo.join(`game.${gameId}`)
            .here(() => {
                isConnected.value = true;
            })
            .listen('GameLobbyUpdated', (event: { players: LobbyPlayer[]; status: string }) => {
                gameStore.applyLobbyUpdate(event.players, event.status);
            })
            .listen('GameCountdownStarted', (event: { duration_ms: number }) => {
                gameStore.applyCountdown(event.duration_ms);
            })
            .listen('GameStarted', (event: { center_piles: { id: number; pile_index: number; top_card: Card | null }[]; players: OpponentState[] }) => {
                gameStore.applyGameStarted(event.center_piles, event.players);
                gameStore.confirmClientReady();
            })
            .listen('GameActivated', () => {
                gameStore.applyGameActivated();
            })
            .listenForWhisper('card-picked-up', (event: { game_player_id: number; card: Card }) => {
                gameStore.applyCardPickedUp(event.game_player_id, event.card);
            })
            .listenForWhisper('card-pickup-cancelled', (event: { game_player_id: number }) => {
                gameStore.applyCardPickupCancelled(event.game_player_id);
            })
            .listenForWhisper(
                'center-card-swapped',
                (event: { center_pile_id: number; center_pile_version: number; incoming_card: Card; outgoing_card_id: number; game_player_id: number }) => {
                    gameStore.applyCenterCardSwapped(event.center_pile_id, event.center_pile_version, event.incoming_card, event.game_player_id);
                },
            )
            .listenForWhisper(
                'swap-cancelled',
                (event: { center_pile_id: number; previous_top_card: Card; previous_version: number; held_card: Card; game_player_id: number }) => {
                    gameStore.applySwapCancelled(event.center_pile_id, event.previous_top_card, event.previous_version, event.held_card, event.game_player_id);
                },
            )
            .listen(
                'PlayerPileCompleted',
                (event: { game_player_id: number; pile_id: number; pile_index: number; cards: Card[] }) => {
                    gameStore.applyPileCompleted(event.game_player_id, event.pile_id, event.cards);
                },
            )
            .listen('GameEnded', (event: { winner: GameWinner | null; forfeited_by: string | null }) => {
                gameStore.applyGameEnded(event.winner, event.forfeited_by ?? undefined);
            })
            .listen('GameResumed', (event: { claimant_name: string }) => {
                gameStore.applyGameResumed(event.claimant_name);
            });

        window.Echo.private(`game.${gameId}.player.${playerId}`).listen('PlayerHandDealt', (event: { piles: PlayerPile[] }) => {
            gameStore.applyHandDealt(event.piles);
        });
    }

    function unsubscribe(gameId: number, playerId: number) {
        window.Echo.leave(`game.${gameId}`);
        window.Echo.leave(`game.${gameId}.player.${playerId}`);
        isConnected.value = false;
    }

    return { isConnected, subscribe, unsubscribe };
});