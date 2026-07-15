<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { Head, router } from '@inertiajs/vue3';
import { computed, onMounted, onUnmounted } from 'vue';
import CountdownOverlay from '../../components/game/CountdownOverlay.vue';
import GameBoard from '../../components/game/GameBoard.vue';
import LobbyPanel from '../../components/game/LobbyPanel.vue';
import PilesClaimOverlay from '../../components/game/PilesClaimOverlay.vue';
import { useEchoStore } from '../../stores/echo';
import { useGameStore } from '../../stores/game';
import { useNotificationStore } from '../../stores/notification';
import type { CenterPile, GamePlayer, GameSession, LobbyPlayer, OpponentState, PlayerPile } from '../../types/game';
import { GameStatus } from '../../types/game';

const props = defineProps<{
    game: GameSession;
    currentPlayer: GamePlayer | null;
    players: LobbyPlayer[];
    myPiles: PlayerPile[];
    centerPiles: CenterPile[];
    opponents: OpponentState[];
}>();

const gameStore = useGameStore();
const echoStore = useEchoStore();
const notificationStore = useNotificationStore();


const isPlaying = computed(
    () => gameStore.session?.status === GameStatus.Playing || gameStore.session?.status === GameStatus.Verifying,
);
const isEnded = computed(() => gameStore.session?.status === GameStatus.Ended);
const isCountdown = computed(() => gameStore.session?.status === GameStatus.Countdown);
const pendingClaim = computed(() => gameStore.pendingClaim);
const winner = computed(() => gameStore.winner);

onMounted(() => {
    gameStore.initialize(props.game, props.currentPlayer, props.players, props.myPiles, props.centerPiles, props.opponents);

    if (props.currentPlayer) {
        echoStore.subscribe(props.game.id, props.currentPlayer.id);
    }
});

onUnmounted(() => {
    if (props.currentPlayer) {
        echoStore.unsubscribe(props.game.id, props.currentPlayer.id);
    }
});
</script>

<template>
    <Head :title="`Game ${game.code}`" />

    <AppLayout>
        <div class="flex h-full flex-1 flex-col">
            <!-- Countdown overlay -->
            <CountdownOverlay v-if="isCountdown && gameStore.countdownEndsAt" :ends-at="gameStore.countdownEndsAt" />

            <!-- PILES! claim overlay -->
            <PilesClaimOverlay
                v-if="pendingClaim"
                :player-name="pendingClaim.playerName"
                :is-current-player="pendingClaim.gamePlayerId === currentPlayer?.id"
            />

            <!-- Winner announcement -->
            <div
                v-if="isEnded && winner"
                class="fixed inset-0 z-50 flex items-center justify-center bg-black/70 backdrop-blur-sm"
            >
                <div class="flex flex-col items-center gap-4 text-center text-white">
                    <p class="text-2xl font-semibold">Game Over!</p>
                    <p class="text-5xl font-black">{{ winner.name }} wins!</p>
                    <button
                        @click="router.visit(route('lobby.index'))"
                        class="mt-4 rounded-lg bg-white px-6 py-2 text-sm font-bold text-black hover:bg-white/90"
                    >
                        Back to Lobby
                    </button>
                </div>
            </div>

            <!-- Toast notifications -->
            <div class="fixed bottom-4 right-4 z-40 flex flex-col gap-2">
                <div
                    v-for="notification in notificationStore.notifications"
                    :key="notification.id"
                    class="max-w-xs rounded-lg px-4 py-3 text-sm font-medium text-white shadow-lg"
                    :class="{
                        'bg-green-600': notification.level === 'success',
                        'bg-red-600': notification.level === 'error',
                        'bg-amber-500': notification.level === 'warning',
                        'bg-slate-700': notification.level === 'info',
                    }"
                >
                    {{ notification.message }}
                </div>
            </div>

            <!-- Main content -->
            <LobbyPanel
                v-if="!isPlaying && !isEnded"
                :game="gameStore.session ?? game"
                :current-player="currentPlayer"
                :players="gameStore.players"
            />

            <GameBoard v-else-if="gameStore.session" :game="gameStore.session" />
        </div>
    </AppLayout>
</template>