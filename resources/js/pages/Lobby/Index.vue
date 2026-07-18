<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { Head, router } from '@inertiajs/vue3';
import { onMounted, onUnmounted, ref } from 'vue';
import { apiFetch } from '../../composables/useFetch';
import GameView from '../../components/game/GameView.vue';
import type { CenterPile, GamePlayer, GameSession, LobbyGame, LobbyPlayer, OpponentState, PlayerPile } from '../../types/game';

const props = defineProps<{
    games: LobbyGame[];
}>();

const games = ref<LobbyGame[]>(props.games);

const variant = ref(false);
const joinCode = ref('');
const joinError = ref('');
const isCreating = ref(false);
const isJoining = ref(false);
const joiningGameId = ref<number | null>(null);

const inGame = ref(false);
const gameData = ref<GameSession | null>(null);
const currentPlayerData = ref<GamePlayer | null>(null);
const playersData = ref<LobbyPlayer[]>([]);
const myPilesData = ref<PlayerPile[]>([]);
const centerPilesData = ref<CenterPile[]>([]);
const opponentsData = ref<OpponentState[]>([]);

function enterGame(data: { game: GameSession; current_player: GamePlayer; players: LobbyPlayer[] }, gameId: number) {
    gameData.value = data.game;
    currentPlayerData.value = data.current_player;
    playersData.value = data.players;
    myPilesData.value = [];
    centerPilesData.value = [];
    opponentsData.value = [];
    window.history.pushState({}, '', route('games.show', { game: gameId }));
    inGame.value = true;
}

function leaveGame() {
    inGame.value = false;
    gameData.value = null;
    currentPlayerData.value = null;
    playersData.value = [];
    myPilesData.value = [];
    centerPilesData.value = [];
    opponentsData.value = [];
    window.history.pushState({}, '', route('lobby.index'));
}

async function createGame() {
    if (isCreating.value) return;
    isCreating.value = true;
    try {
        const res = await apiFetch(route('games.store'), {
            method: 'POST',
            body: JSON.stringify({ variant: variant.value }),
        });
        const data = await res.json();
        enterGame(data, data.game.id);
    } finally {
        isCreating.value = false;
    }
}

async function joinGame(code: string, gameId?: number) {
    if (isJoining.value) return;
    joinError.value = '';
    isJoining.value = true;
    joiningGameId.value = gameId ?? null;
    try {
        const res = await apiFetch(route('games.join'), {
            method: 'POST',
            body: JSON.stringify({ code }),
        });
        if (res.status === 422) {
            const data = await res.json();
            joinError.value = data.message ?? 'Could not join game.';
            return;
        }
        const data = await res.json();
        enterGame(data, data.game.id);
    } finally {
        isJoining.value = false;
        joiningGameId.value = null;
    }
}

onMounted(() => {
    window.Echo.channel('lobby').listen('LobbyUpdated', (event: { games: LobbyGame[] }) => {
        games.value = event.games;
    });
});

onUnmounted(() => {
    window.Echo.leaveChannel('lobby');
});
</script>

<template>
    <Head :title="inGame && gameData ? `Game ${gameData.code}` : 'Lobby'" />

    <AppLayout>
        <template v-if="inGame && gameData && currentPlayerData">
            <GameView
                :game="gameData"
                :current-player="currentPlayerData"
                :players="playersData"
                :my-piles="myPilesData"
                :center-piles="centerPilesData"
                :opponents="opponentsData"
                @left="leaveGame"
            />
        </template>

        <template v-else>
            <div class="flex flex-1 flex-col gap-4 p-3 sm:gap-6 sm:p-6">
                <div class="grid gap-4 md:grid-cols-2 md:gap-6">
                    <!-- Create Game -->
                    <div class="rounded-xl border border-sidebar-border/70 bg-card p-4 dark:border-sidebar-border sm:p-6">
                        <h2 class="mb-4 text-lg font-semibold">Create a Game</h2>
                        <form @submit.prevent="createGame" class="space-y-4">
                            <label class="flex cursor-pointer items-center gap-3">
                                <input
                                    type="checkbox"
                                    v-model="variant"
                                    class="h-4 w-4 rounded border-gray-300 text-primary focus:ring-primary"
                                />
                                <span class="text-sm">Variant mode (extra set)</span>
                            </label>
                            <button
                                type="submit"
                                :disabled="isCreating"
                                class="w-full rounded-lg bg-primary px-4 py-3 text-sm font-medium text-primary-foreground transition-transform hover:bg-primary/90 active:scale-[0.98] disabled:opacity-50 sm:py-2"
                            >
                                {{ isCreating ? 'Creating…' : 'Create Game' }}
                            </button>
                        </form>
                    </div>

                    <!-- Join by Code -->
                    <div class="rounded-xl border border-sidebar-border/70 bg-card p-4 dark:border-sidebar-border sm:p-6">
                        <h2 class="mb-4 text-lg font-semibold">Join with Code</h2>
                        <form @submit.prevent="joinGame(joinCode)" class="space-y-4">
                            <div>
                                <input
                                    type="text"
                                    v-model="joinCode"
                                    placeholder="6-letter code"
                                    maxlength="6"
                                    autocapitalize="characters"
                                    autocomplete="off"
                                    class="w-full rounded-lg border border-input bg-background px-3 py-3 text-center text-lg uppercase tracking-[0.4em] focus:outline-none focus:ring-2 focus:ring-ring sm:py-2 sm:text-sm sm:tracking-widest"
                                />
                                <p v-if="joinError" class="mt-1 text-sm text-destructive">{{ joinError }}</p>
                            </div>
                            <button
                                type="submit"
                                :disabled="isJoining || !joinCode"
                                class="w-full rounded-lg bg-primary px-4 py-3 text-sm font-medium text-primary-foreground transition-transform hover:bg-primary/90 active:scale-[0.98] disabled:opacity-50 sm:py-2"
                            >
                                {{ isJoining && !joiningGameId ? 'Joining…' : 'Join Game' }}
                            </button>
                        </form>
                    </div>
                </div>

                <!-- Open Games -->
                <div class="rounded-xl border border-sidebar-border/70 bg-card p-4 dark:border-sidebar-border sm:p-6">
                    <h2 class="mb-4 text-lg font-semibold">Open Games</h2>
                    <div v-if="games.length === 0" class="py-8 text-center text-sm text-muted-foreground">No open games right now. Create one!</div>
                    <div v-else class="space-y-2">
                        <div
                            v-for="game in games"
                            :key="game.id"
                            class="flex flex-col gap-2 rounded-lg border border-border px-3 py-3 sm:flex-row sm:items-center sm:justify-between sm:gap-4 sm:px-4"
                        >
                            <div class="flex items-center gap-3 sm:gap-4">
                                <span class="font-mono text-lg font-bold tracking-widest">{{ game.code }}</span>
                                <div class="flex flex-wrap items-center gap-x-2 gap-y-0.5 text-xs text-muted-foreground sm:text-sm">
                                    <span>{{ game.host_name }}</span>
                                    <span class="text-muted-foreground/50">·</span>
                                    <span>{{ game.player_count }}/7 players</span>
                                    <span v-if="game.variant" class="rounded bg-muted px-1.5 py-0.5 text-[10px] font-medium uppercase tracking-wide">Variant</span>
                                </div>
                            </div>
                            <button
                                @click="joinGame(game.code, game.id)"
                                :disabled="isJoining"
                                class="rounded-lg bg-secondary px-4 py-2 text-sm font-medium text-secondary-foreground transition-transform hover:bg-secondary/80 active:scale-[0.98] disabled:opacity-50 sm:px-3 sm:py-1.5"
                            >
                                {{ isJoining && joiningGameId === game.id ? 'Joining…' : 'Join' }}
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </template>
    </AppLayout>
</template>
