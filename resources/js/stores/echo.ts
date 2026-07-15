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
            .listen('GameCountdownStarted', (event: { starts_at: string }) => {
                gameStore.applyCountdown(event.starts_at);
            })
            .listen('GameStarted', (event: { center_piles: { id: number; pile_index: number; top_card: Card | null }[]; players: OpponentState[] }) => {
                gameStore.applyGameStarted(event.center_piles, event.players);
            })
            .listen(
                'CenterCardSwapped',
                (event: { center_pile_id: number; center_pile_version: number; incoming_card: Card; outgoing_card_id: number }) => {
                    gameStore.applyCenterCardSwapped(event.center_pile_id, event.center_pile_version, event.incoming_card);
                },
            )
            .listen('PlayerPilePickedUp', (_event: { game_player_id: number; pile_id: number }) => {
                // Reserved for future animation
            })
            .listen(
                'PlayerPileCompleted',
                (event: { game_player_id: number; pile_id: number; pile_index: number; cards: Card[] }) => {
                    gameStore.applyPileCompleted(event.game_player_id, event.pile_id, event.cards);
                },
            )
            .listen('PilesClaimMade', (event: { game_player_id: number; player_name: string }) => {
                gameStore.applyClaimMade(event.game_player_id, event.player_name);
            })
            .listen('GameEnded', (event: { winner: GameWinner }) => {
                gameStore.applyGameEnded(event.winner);
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