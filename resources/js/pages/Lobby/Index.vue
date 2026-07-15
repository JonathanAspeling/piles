<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import type { BreadcrumbItem } from '@/types';
import { Head, router, useForm } from '@inertiajs/vue3';
import type { LobbyGame } from '../../types/game';

defineProps<{
    games: LobbyGame[];
}>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Lobby', href: route('lobby.index') }];

const createForm = useForm({ variant: false });
const joinForm = useForm({ code: '' });

function createGame() {
    createForm.post(route('games.store'));
}

function joinGame() {
    joinForm.post(route('games.join'));
}
</script>

<template>
    <Head title="Lobby" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-1 flex-col gap-6 p-6">
            <div class="grid gap-6 md:grid-cols-2">
                <!-- Create Game -->
                <div class="rounded-xl border border-sidebar-border/70 bg-card p-6 dark:border-sidebar-border">
                    <h2 class="mb-4 text-lg font-semibold">Create a Game</h2>
                    <form @submit.prevent="createGame" class="space-y-4">
                        <label class="flex cursor-pointer items-center gap-3">
                            <input
                                type="checkbox"
                                v-model="createForm.variant"
                                class="h-4 w-4 rounded border-gray-300 text-primary focus:ring-primary"
                            />
                            <span class="text-sm">Variant mode (extra set)</span>
                        </label>
                        <button
                            type="submit"
                            :disabled="createForm.processing"
                            class="w-full rounded-lg bg-primary px-4 py-2 text-sm font-medium text-primary-foreground hover:bg-primary/90 disabled:opacity-50"
                        >
                            {{ createForm.processing ? 'Creating…' : 'Create Game' }}
                        </button>
                    </form>
                </div>

                <!-- Join Game -->
                <div class="rounded-xl border border-sidebar-border/70 bg-card p-6 dark:border-sidebar-border">
                    <h2 class="mb-4 text-lg font-semibold">Join with Code</h2>
                    <form @submit.prevent="joinGame" class="space-y-4">
                        <div>
                            <input
                                type="text"
                                v-model="joinForm.code"
                                placeholder="Enter 6-letter code"
                                maxlength="6"
                                class="w-full rounded-lg border border-input bg-background px-3 py-2 text-sm uppercase tracking-widest focus:outline-none focus:ring-2 focus:ring-ring"
                            />
                            <p v-if="joinForm.errors.code" class="mt-1 text-sm text-destructive">{{ joinForm.errors.code }}</p>
                        </div>
                        <button
                            type="submit"
                            :disabled="joinForm.processing || !joinForm.code"
                            class="w-full rounded-lg bg-primary px-4 py-2 text-sm font-medium text-primary-foreground hover:bg-primary/90 disabled:opacity-50"
                        >
                            {{ joinForm.processing ? 'Joining…' : 'Join Game' }}
                        </button>
                    </form>
                </div>
            </div>

            <!-- Open Games -->
            <div class="rounded-xl border border-sidebar-border/70 bg-card p-6 dark:border-sidebar-border">
                <h2 class="mb-4 text-lg font-semibold">Open Games</h2>
                <div v-if="games.length === 0" class="py-8 text-center text-sm text-muted-foreground">
                    No open games right now. Create one!
                </div>
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
                            @click="router.visit(route('games.join'), { method: 'post', data: { code: game.code } })"
                            class="rounded-lg bg-secondary px-3 py-1.5 text-sm font-medium text-secondary-foreground hover:bg-secondary/80"
                        >
                            Join
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>