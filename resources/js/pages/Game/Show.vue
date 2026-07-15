<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { Head, router } from '@inertiajs/vue3';
import GameView from '../../components/game/GameView.vue';
import type { CenterPile, GamePlayer, GameSession, LobbyPlayer, OpponentState, PlayerPile } from '../../types/game';

const props = defineProps<{
    game: GameSession;
    currentPlayer: GamePlayer | null;
    players: LobbyPlayer[];
    myPiles: PlayerPile[];
    centerPiles: CenterPile[];
    opponents: OpponentState[];
}>();
</script>

<template>
    <Head :title="`Game ${props.game.code}`" />

    <AppLayout>
        <GameView
            :game="props.game"
            :current-player="props.currentPlayer"
            :players="props.players"
            :my-piles="props.myPiles"
            :center-piles="props.centerPiles"
            :opponents="props.opponents"
            @left="router.visit(route('lobby.index'))"
        />
    </AppLayout>
</template>
