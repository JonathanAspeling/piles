<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { computed, onMounted, onUnmounted } from 'vue';
import { useEchoStore } from '../../stores/echo';
import { useGameStore } from '../../stores/game';
import { useNotificationStore } from '../../stores/notification';
import type { CenterPile, GamePlayer, GameSession, LobbyPlayer, OpponentState, PlayerPile } from '../../types/game';
import { GameStatus } from '../../types/game';
import CountdownOverlay from './CountdownOverlay.vue';
import GameBoard from './GameBoard.vue';
import LobbyPanel from './LobbyPanel.vue';
import PilesClaimOverlay from './PilesClaimOverlay.vue';

const props = defineProps<{
    game: GameSession;
    currentPlayer: GamePlayer | null;
    players: LobbyPlayer[];
    myPiles: PlayerPile[];
    centerPiles: CenterPile[];
    opponents: OpponentState[];
}>();

const emit = defineEmits<{
    left: [];
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
const forfeitedBy = computed(() => gameStore.forfeitedBy);

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
    <div class="flex h-full flex-1 flex-col">
        <!-- Countdown overlay: loading until all clients ready, then 3-2-1-GO! -->
        <CountdownOverlay
            v-if="isCountdown"
            :duration-ms="gameStore.countdownDurationMs"
            :started-at-local-ms="gameStore.countdownStartedAtLocalMs"
            @ended="gameStore.applyGameActivated()"
        />

        <!-- PILES! claim overlay -->
        <PilesClaimOverlay
            v-if="pendingClaim"
            :player-name="pendingClaim.playerName"
            :is-current-player="pendingClaim.gamePlayerId === currentPlayer?.id"
        />

        <!-- Winner/forfeit announcement — always shows on Ended so players are never stuck -->
        <div
            v-if="isEnded"
            class="fixed inset-0 z-50 flex items-center justify-center bg-black/70 backdrop-blur-sm"
        >
            <div class="flex flex-col items-center gap-4 px-6 text-center text-white">
                <p class="text-xl font-semibold sm:text-2xl">Game Over!</p>
                <template v-if="forfeitedBy">
                    <p class="text-3xl font-black sm:text-5xl">{{ forfeitedBy }} forfeited</p>
                    <p class="text-lg text-white/70">No winner this time</p>
                </template>
                <template v-else-if="winner">
                    <p class="text-3xl font-black sm:text-5xl">{{ winner.name }} wins!</p>
                </template>
                <template v-else>
                    <p class="text-3xl font-black sm:text-5xl">Game ended</p>
                    <p class="text-lg text-white/70">Result unavailable — return to lobby</p>
                </template>
                <button
                    @click="emit('left')"
                    class="mt-4 rounded-lg bg-white px-6 py-2 text-sm font-bold text-black hover:bg-white/90"
                >
                    Back to Lobby
                </button>
            </div>
        </div>

        <!-- Toast notifications: pushed above sticky hand on mobile -->
        <div class="fixed bottom-32 right-2 z-40 flex flex-col gap-2 sm:bottom-4 sm:right-4">
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
            @left="emit('left')"
        />

        <GameBoard v-else-if="gameStore.session" :game="gameStore.session" />
    </div>
</template>
