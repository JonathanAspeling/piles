<script setup lang="ts">
import { computed, ref } from 'vue';
import { apiFetch } from '../../composables/useFetch';
import type { GamePlayer, GameSession, LobbyPlayer } from '../../types/game';
import HowToPlayModal from './HowToPlayModal.vue';

const props = defineProps<{
    game: GameSession;
    currentPlayer: GamePlayer | null;
    players: LobbyPlayer[];
}>();

const emit = defineEmits<{
    left: [];
}>();

const isHost = computed(() => props.currentPlayer?.user_id === props.game.host_user_id);
const allReady = computed(() => props.players.length >= 2 && props.players.every((p) => p.is_ready));
const myPlayer = computed(() => props.players.find((p) => p.id === props.currentPlayer?.id));

const isActing = ref(false);
const showHowToPlay = ref(false);

async function toggleReady() {
    if (isActing.value) return;
    isActing.value = true;
    await apiFetch(route('games.ready', { game: props.game.id }), { method: 'POST' });
    isActing.value = false;
}

async function startGame() {
    if (isActing.value) return;
    isActing.value = true;
    await apiFetch(route('games.start', { game: props.game.id }), { method: 'POST' });
    isActing.value = false;
}

async function leaveGame() {
    if (isActing.value) return;
    isActing.value = true;
    await apiFetch(route('games.leave', { game: props.game.id }), { method: 'DELETE' });
    emit('left');
}
</script>

<template>
    <div class="flex flex-1 flex-col items-center justify-center gap-6 p-3 sm:p-6">
        <div class="w-full max-w-md rounded-xl border border-border bg-card shadow-sm">
            <!-- Header -->
            <div class="border-b border-border px-6 py-4">
                <div class="flex items-center justify-between">
                    <h2 class="text-lg font-semibold">Game Lobby</h2>
                    <div class="flex items-center gap-2">
                        <span class="text-xs text-muted-foreground">Code:</span>
                        <span class="font-mono text-xl font-bold tracking-widest">{{ game.code }}</span>
                    </div>
                </div>
                <div class="mt-1 flex items-center justify-between gap-3 text-sm text-muted-foreground">
                    <div class="flex items-center gap-3">
                        <span>{{ players.length }}/7 players</span>
                        <span v-if="game.variant" class="rounded bg-muted px-1.5 py-0.5 text-xs">Variant</span>
                    </div>
                    <button
                        @click="showHowToPlay = true"
                        class="inline-flex items-center gap-1 rounded-md px-2 py-1 text-xs font-medium text-muted-foreground transition-colors hover:bg-muted hover:text-foreground active:scale-95"
                    >
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <circle cx="12" cy="12" r="10" />
                            <path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3" />
                            <line x1="12" y1="17" x2="12.01" y2="17" />
                        </svg>
                        How to play
                    </button>
                </div>
            </div>

            <!-- Player list -->
            <ul class="divide-y divide-border">
                <li v-for="player in players" :key="player.id" class="flex items-center justify-between px-6 py-3">
                    <div class="flex items-center gap-3">
                        <div
                            class="flex h-8 w-8 items-center justify-center rounded-full text-xs font-bold text-white"
                            :class="player.user_id === game.host_user_id ? 'bg-amber-500' : 'bg-muted-foreground/40'"
                        >
                            {{ player.name.charAt(0).toUpperCase() }}
                        </div>
                        <div>
                            <span class="text-sm font-medium">{{ player.name }}</span>
                            <span v-if="player.user_id === game.host_user_id" class="ml-2 text-xs text-amber-600 dark:text-amber-400">
                                Host
                            </span>
                        </div>
                    </div>
                    <span
                        class="rounded-full px-2.5 py-1 text-xs font-medium"
                        :class="player.is_ready ? 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400' : 'bg-muted text-muted-foreground'"
                    >
                        {{ player.is_ready ? 'Ready' : 'Not ready' }}
                    </span>
                </li>
            </ul>

            <!-- Actions -->
            <div class="border-t border-border px-6 py-4">
                <div v-if="!allReady && players.length < 2" class="mb-3 text-center text-sm text-muted-foreground">
                    Waiting for at least 2 players…
                </div>
                <div v-else-if="!allReady" class="mb-3 text-center text-sm text-muted-foreground">
                    Waiting for all players to be ready…
                </div>

                <div class="flex gap-3">
                    <button
                        @click="toggleReady"
                        :disabled="isActing"
                        class="flex-1 rounded-lg px-4 py-2 text-sm font-medium transition-colors disabled:opacity-50"
                        :class="
                            myPlayer?.is_ready
                                ? 'bg-muted text-muted-foreground hover:bg-muted/80'
                                : 'bg-green-500 text-white hover:bg-green-600'
                        "
                    >
                        {{ myPlayer?.is_ready ? 'Unready' : 'Ready Up' }}
                    </button>

                    <button
                        v-if="isHost"
                        @click="startGame"
                        :disabled="!allReady || isActing"
                        class="flex-1 rounded-lg bg-primary px-4 py-2 text-sm font-medium text-primary-foreground hover:bg-primary/90 disabled:cursor-not-allowed disabled:opacity-50"
                    >
                        Start Game
                    </button>

                    <button :disabled="isActing" @click="leaveGame" class="rounded-lg border border-border px-4 py-2 text-sm font-medium hover:bg-muted disabled:opacity-50">
                        Leave
                    </button>
                </div>
            </div>
        </div>

        <HowToPlayModal v-if="showHowToPlay" @close="showHowToPlay = false" />
    </div>
</template>