<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { Head, router } from '@inertiajs/vue3';
import { onMounted, onUnmounted, ref } from 'vue';
import type { LobbyGame } from '../../types/game';

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

function csrf(): string {
    return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';
}

async function createGame() {
    if (isCreating.value) return;
    isCreating.value = true;
    try {
        const res = await fetch(route('games.store'), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf() },
            body: JSON.stringify({ variant: variant.value }),
        });
        const { game_id } = await res.json();
        router.visit(route('games.show', { game: game_id }));
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
        const res = await fetch(route('games.join'), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf() },
            body: JSON.stringify({ code }),
        });
        if (res.status === 422) {
            const data = await res.json();
            joinError.value = data.message ?? 'Could not join game.';
            return;
        }
        const { game_id } = await res.json();
        router.visit(route('games.show', { game: game_id }));
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
    <Head title="Lobby" />

    <AppLayout>
        <div class="flex flex-1 flex-col gap-6 p-6">
            <div class="grid gap-6 md:grid-cols-2">
                <!-- Create Game -->
                <div class="rounded-xl border border-sidebar-border/70 bg-card p-6 dark:border-sidebar-border">
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
                            class="w-full rounded-lg bg-primary px-4 py-2 text-sm font-medium text-primary-foreground hover:bg-primary/90 disabled:opacity-50"
                        >
                            {{ isCreating ? 'Creating…' : 'Create Game' }}
                        </button>
                    </form>
                </div>

                <!-- Join by Code -->
                <div class="rounded-xl border border-sidebar-border/70 bg-card p-6 dark:border-sidebar-border">
                    <h2 class="mb-4 text-lg font-semibold">Join with Code</h2>
                    <form @submit.prevent="joinGame(joinCode)" class="space-y-4">
                        <div>
                            <input
                                type="text"
                                v-model="joinCode"
                                placeholder="Enter 6-letter code"
                                maxlength="6"
                                class="w-full rounded-lg border border-input bg-background px-3 py-2 text-sm uppercase tracking-widest focus:outline-none focus:ring-2 focus:ring-ring"
                            />
                            <p v-if="joinError" class="mt-1 text-sm text-destructive">{{ joinError }}</p>
                        </div>
                        <button
                            type="submit"
                            :disabled="isJoining || !joinCode"
                            class="w-full rounded-lg bg-primary px-4 py-2 text-sm font-medium text-primary-foreground hover:bg-primary/90 disabled:opacity-50"
                        >
                            {{ isJoining && !joiningGameId ? 'Joining…' : 'Join Game' }}
                        </button>
                    </form>
                </div>
            </div>

            <!-- Open Games -->
            <div class="rounded-xl border border-sidebar-border/70 bg-card p-6 dark:border-sidebar-border">
                <h2 class="mb-4 text-lg font-semibold">Open Games</h2>
                <div v-if="games.length === 0" class="py-8 text-center text-sm text-muted-foreground">No open games right now. Create one!</div>
                <div v-else class="space-y-2">
                    <div
                        v-for="game in games"
                        :key="game.id"
                        class="flex items-center justify-between rounded-lg border border-border px-4 py-3"
                    >
                        <div class="flex items-center gap-4">
                            <span class="font-mono text-lg font-bold tracking-widest">{{ game.code }}</span>
                            <div class="text-sm text-muted-foreground">
                                <span>{{ game.host_name }}</span>
                                <span class="mx-1">·</span>
                                <span>{{ game.player_count }}/7 players</span>
                                <span v-if="game.variant" class="ml-2 rounded bg-muted px-1.5 py-0.5 text-xs">Variant</span>
                            </div>
                        </div>
                        <button
                            @click="joinGame(game.code, game.id)"
                            :disabled="isJoining"
                            class="rounded-lg bg-secondary px-3 py-1.5 text-sm font-medium text-secondary-foreground hover:bg-secondary/80 disabled:opacity-50"
                        >
                            {{ isJoining && joiningGameId === game.id ? 'Joining…' : 'Join' }}
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>