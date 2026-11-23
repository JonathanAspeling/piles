<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { Head, router } from '@inertiajs/vue3';
import GameView from '../../components/game/GameView.vue';
import type { CenterPile, GamePlayer, GameSession, LobbyPlayer, OpponentState, PlayerPile, RematchState } from '../../types/game';

const props = defineProps<{
    game: GameSession;
    currentPlayer: GamePlayer | null;
    players: LobbyPlayer[];
    myPiles: PlayerPile[];
    centerPiles: CenterPile[];
    opponents: OpponentState[];
    rematch: RematchState;
}>();
</script>

<template>
    <Head :title="`Game ${props.game.code}`" />

    <AppLayout :show-version="false">
        <!-- Keyed by game: "Play again" visits this same page for the rematch,
             and the view must remount to subscribe to the new game. -->
        <GameView
            :key="props.game.id"
            :game="props.game"
            :current-player="props.currentPlayer"
            :players="props.players"
            :my-piles="props.myPiles"
            :center-piles="props.centerPiles"
            :opponents="props.opponents"
            :rematch="props.rematch"
            @left="router.visit(route('lobby.index'))"
        />
    </AppLayout>
</template>
