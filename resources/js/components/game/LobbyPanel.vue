<script setup lang="ts">
import { computed, ref } from 'vue';
import { apiFetch } from '../../composables/useFetch';
import type { GamePlayer, GameSession, LobbyPlayer } from '../../types/game';
import { CARD_THEME_LABELS, CardTheme } from '../../types/game';
import { avatarColorClass } from '../../utils/avatar';
import { cardStyleFor } from '../../utils/cardArt';
import HowToPlayModal from './HowToPlayModal.vue';

/**
 * The room before a game starts, in the board's visual language: the code to
 * share, the table's settings, who's in and ready, and one primary action.
 * On phone the actions sit at the bottom, within thumb reach, like the board.
 */
const props = defineProps<{
    game: GameSession;
    currentPlayer: GamePlayer | null;
    players: LobbyPlayer[];
}>();

const emit = defineEmits<{
    left: [];
}>();

const MAX_PLAYERS = 10;

const isHost = computed(() => props.currentPlayer?.user_id === props.game.host_user_id);
const hasEnoughPlayers = computed(() => props.players.length >= 2);
const allReady = computed(() => hasEnoughPlayers.value && props.players.every((player) => player.is_ready));
const myPlayer = computed(() => props.players.find((player) => player.id === props.currentPlayer?.id));
const isMeReady = computed(() => !!myPlayer.value?.is_ready);
const hostName = computed(() => props.players.find((player) => player.user_id === props.game.host_user_id)?.name ?? 'the host');
const notReadyOthers = computed(() => props.players.filter((player) => !player.is_ready && player.id !== myPlayer.value?.id));

const settings = computed(() => {
    const labels = [`${CARD_THEME_LABELS[props.game.theme]} cards`];
    if (props.game.theme !== CardTheme.Classic && props.game.hard_variant) {
        labels.push('Hard colours');
    }
    labels.push(props.game.variant ? '8 centre cards · variant' : '4 centre cards');
    if (props.game.similar_items) {
        labels.push('Similar items');
    }

    return labels;
});

const status = computed(() => {
    if (!hasEnoughPlayers.value) {
        return 'Send the invite link, or the room code, to get someone in.';
    }
    if (allReady.value) {
        return isHost.value ? "Everyone's ready. Start when you like." : `Everyone's ready. Waiting for ${hostName.value} to start.`;
    }
    if (!isMeReady.value) {
        return "Ready up when you're set.";
    }
    if (notReadyOthers.value.length === 1) {
        return `Waiting for ${notReadyOthers.value[0].name} to ready up…`;
    }

    return `Waiting for ${notReadyOthers.value.length} players to ready up…`;
});

const isActing = ref(false);
const showHowToPlay = ref(false);
const copied = ref(false);
const inviteUrl = computed(() => route('games.invite', { code: props.game.code }));
/** Phones get the share sheet (straight into a chat); everything else copies the link. */
const canShare = typeof navigator !== 'undefined' && !!navigator.share && window.matchMedia('(pointer: coarse)').matches;
const canCopy = typeof navigator !== 'undefined' && !!navigator.clipboard;
let copiedTimer: ReturnType<typeof setTimeout> | null = null;

async function shareInvite() {
    if (canShare) {
        try {
            await navigator.share({ title: 'Piles!', text: `Join my game of Piles! Room ${props.game.code}`, url: inviteUrl.value });
        } catch {
            // Closing the share sheet without picking anything isn't an error.
        }
        return;
    }
    try {
        await navigator.clipboard.writeText(inviteUrl.value);
    } catch {
        return;
    }
    copied.value = true;
    if (copiedTimer) {
        clearTimeout(copiedTimer);
    }
    copiedTimer = setTimeout(() => (copied.value = false), 1600);
}

async function act(request: () => Promise<unknown>) {
    if (isActing.value) {
        return;
    }
    isActing.value = true;
    try {
        await request();
    } finally {
        isActing.value = false;
    }
}

function toggleReady() {
    return act(() => apiFetch(route('games.ready', { game: props.game.id }), { method: 'POST' }));
}

/** The host fills an empty seat with a computer player; it sits down ready. */
function addBot() {
    return act(() => apiFetch(route('games.bots.store', { game: props.game.id }), { method: 'POST' }));
}

function removeBot(player: LobbyPlayer) {
    return act(() => apiFetch(route('games.bots.destroy', { game: props.game.id, player: player.id }), { method: 'DELETE' }));
}

function startGame() {
    return act(() => apiFetch(route('games.start', { game: props.game.id }), { method: 'POST' }));
}

async function leaveGame() {
    await act(() => apiFetch(route('games.leave', { game: props.game.id }), { method: 'DELETE' }));
    emit('left');
}
</script>

<template>
    <div class="flex flex-1 flex-col px-4 pb-4 pt-16 md:items-center md:justify-center md:px-10 md:py-10">
        <div
            class="flex w-full flex-1 flex-col gap-5 md:max-w-[520px] md:flex-none md:rounded-[22px] md:border md:border-border md:bg-card md:p-7 md:shadow-[0_1px_2px_rgba(20,18,14,0.06),0_18px_40px_rgba(20,18,14,0.08)] md:dark:shadow-[0_18px_40px_rgba(0,0,0,0.5)]"
        >
            <!-- Heading and status -->
            <div class="flex items-start justify-between gap-3">
                <div class="flex min-w-0 flex-col gap-1.5">
                    <h2 class="text-3xl font-bold leading-[1.05] tracking-[-0.01em] md:text-4xl">Game Lobby</h2>
                    <p class="text-[15px] text-muted-foreground md:text-base" aria-live="polite">{{ status }}</p>
                </div>
                <button
                    type="button"
                    class="inline-flex shrink-0 items-center gap-1.5 rounded-full border border-border px-3 py-1 text-[13px] text-muted-foreground transition-colors hover:text-foreground active:scale-95 md:text-[15px]"
                    @click="showHowToPlay = true"
                >
                    <svg
                        xmlns="http://www.w3.org/2000/svg"
                        class="h-3.5 w-3.5"
                        viewBox="0 0 24 24"
                        fill="none"
                        stroke="currentColor"
                        stroke-width="2.5"
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        aria-hidden="true"
                    >
                        <circle cx="12" cy="12" r="10" />
                        <path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3" />
                        <line x1="12" y1="17" x2="12.01" y2="17" />
                    </svg>
                    How to play
                </button>
            </div>

            <!-- Room code and settings -->
            <section class="flex flex-col gap-3 rounded-[14px] border border-border bg-muted p-3.5 md:rounded-[18px] md:p-[18px]">
                <div class="flex items-center justify-between gap-3">
                    <div class="flex flex-col gap-1">
                        <span class="text-[11px] font-bold uppercase tracking-[0.1em] text-muted-foreground md:text-xs">Room code</span>
                        <span data-room-code class="font-mono text-[28px] font-bold leading-none tracking-[0.18em] md:text-[32px]">{{
                            game.code
                        }}</span>
                    </div>
                    <button
                        v-if="canShare || canCopy"
                        data-invite-link
                        type="button"
                        class="inline-flex shrink-0 items-center gap-1.5 rounded-full border px-3 py-1.5 text-[13px] font-semibold transition-colors active:scale-95 md:text-sm"
                        :class="copied ? 'border-[#0E9C6B] text-[#0E9C6B]' : 'border-border bg-card text-muted-foreground hover:text-foreground'"
                        @click="shareInvite"
                    >
                        <svg
                            xmlns="http://www.w3.org/2000/svg"
                            class="h-3.5 w-3.5"
                            viewBox="0 0 24 24"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="2.5"
                            stroke-linecap="round"
                            stroke-linejoin="round"
                            aria-hidden="true"
                        >
                            <polyline v-if="copied" points="20 6 9 17 4 12" />
                            <template v-else>
                                <rect x="9" y="9" width="13" height="13" rx="2" />
                                <path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1" />
                            </template>
                        </svg>
                        {{ copied ? 'Link copied' : canShare ? 'Share link' : 'Copy invite link' }}
                    </button>
                </div>
                <ul class="flex flex-wrap gap-1.5">
                    <li
                        v-for="setting in settings"
                        :key="setting"
                        class="rounded-full border border-border bg-card px-2.5 py-0.5 text-[13px] text-muted-foreground"
                    >
                        {{ setting }}
                    </li>
                </ul>
            </section>

            <!-- Players -->
            <section class="flex flex-col gap-2.5">
                <h3 class="text-[11px] font-bold uppercase tracking-[0.1em] text-muted-foreground md:text-xs">
                    Players · <span class="text-foreground">{{ players.length }}/{{ MAX_PLAYERS }}</span>
                </h3>
                <ol class="flex flex-col overflow-hidden rounded-[14px] border border-border">
                    <li
                        v-for="player in players"
                        :key="player.id"
                        class="flex items-center gap-3 border-b border-border px-3.5 py-3 transition-colors duration-300 last:border-b-0"
                        :class="player.is_ready ? 'bg-[#E5F4ED] dark:bg-[rgba(14,156,107,0.16)]' : 'bg-card'"
                        :data-lobby-player="player.name"
                    >
                        <span
                            class="flex h-[30px] w-[30px] shrink-0 items-center justify-center rounded-full text-sm font-bold text-white"
                            :class="avatarColorClass(player.seat_index)"
                        >
                            {{ player.name.charAt(0).toUpperCase() }}
                        </span>
                        <span class="flex min-w-0 flex-1 items-baseline gap-2">
                            <span class="truncate text-base font-semibold">{{ player.name }}{{ player.id === myPlayer?.id ? ' (you)' : '' }}</span>
                            <span
                                v-if="player.user_id === game.host_user_id"
                                class="shrink-0 text-[13px] font-semibold text-[#A86E00] dark:text-[#F2B42B]"
                            >
                                Host
                            </span>
                            <span
                                v-if="player.is_bot"
                                class="shrink-0 rounded-full border border-border bg-card px-2 py-px text-[11px] font-bold uppercase tracking-[0.08em] text-muted-foreground"
                            >
                                Bot
                            </span>
                        </span>
                        <span
                            class="flex shrink-0 items-center gap-2 text-sm font-semibold"
                            :class="player.is_ready ? 'text-[#0E9C6B]' : 'text-muted-foreground'"
                        >
                            <span class="h-2.5 w-2.5 rounded-full" :class="player.is_ready ? 'bg-[#0E9C6B]' : 'bg-stone-300 dark:bg-zinc-600'" />
                            {{ player.is_ready ? 'Ready' : 'Not ready' }}
                        </span>
                        <button
                            v-if="isHost && player.is_bot"
                            type="button"
                            :aria-label="`Remove ${player.name}`"
                            :disabled="isActing"
                            class="-mr-1 shrink-0 rounded-full p-1 text-muted-foreground hover:bg-background hover:text-foreground active:scale-95 disabled:opacity-50"
                            @click="removeBot(player)"
                        >
                            <svg
                                xmlns="http://www.w3.org/2000/svg"
                                class="h-4 w-4"
                                viewBox="0 0 24 24"
                                fill="none"
                                stroke="currentColor"
                                stroke-width="2.5"
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                aria-hidden="true"
                            >
                                <line x1="6" y1="6" x2="18" y2="18" />
                                <line x1="6" y1="18" x2="18" y2="6" />
                            </svg>
                        </button>
                    </li>
                    <!-- An open seat, until there's someone to play against. -->
                    <li v-if="!hasEnoughPlayers" class="flex items-center gap-3 bg-card px-3.5 py-3 text-[15px] text-muted-foreground">
                        <span class="h-[30px] w-[30px] shrink-0 rounded-full border-[1.5px] border-dashed border-stone-300 dark:border-zinc-600" />
                        Waiting for someone to join…
                    </li>
                </ol>
                <button
                    v-if="isHost && players.length < MAX_PLAYERS"
                    type="button"
                    :disabled="isActing"
                    class="flex items-center justify-center gap-2 rounded-[14px] border-[1.5px] border-dashed border-border px-3.5 py-2.5 text-sm font-semibold text-muted-foreground transition-colors hover:border-foreground/40 hover:text-foreground active:scale-[0.99] disabled:opacity-50"
                    @click="addBot"
                >
                    <svg
                        xmlns="http://www.w3.org/2000/svg"
                        class="h-4 w-4"
                        viewBox="0 0 24 24"
                        fill="none"
                        stroke="currentColor"
                        stroke-width="2.5"
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        aria-hidden="true"
                    >
                        <line x1="12" y1="5" x2="12" y2="19" />
                        <line x1="5" y1="12" x2="19" y2="12" />
                    </svg>
                    Add a bot
                </button>
            </section>

            <!-- Actions: one primary at a time -->
            <div class="mt-auto flex flex-col gap-2.5 pt-1 md:mt-0">
                <button
                    v-if="!isMeReady"
                    type="button"
                    :disabled="isActing"
                    class="h-14 rounded-[14px] bg-[#0E9C6B] text-[19px] font-bold text-white transition-transform hover:bg-[#0B8A5E] active:scale-[0.98] disabled:opacity-70"
                    @click="toggleReady"
                >
                    Ready Up
                </button>
                <button
                    v-else-if="isHost"
                    type="button"
                    :disabled="!allReady || isActing"
                    class="h-14 rounded-[14px] text-[19px] font-bold transition-transform"
                    :class="
                        allReady
                            ? 'bg-[#0E9C6B] text-white hover:bg-[#0B8A5E] active:scale-[0.98]'
                            : 'cursor-not-allowed border border-dashed border-border bg-muted text-muted-foreground'
                    "
                    @click="startGame"
                >
                    Start Game
                </button>
                <div
                    v-else
                    class="flex h-14 items-center justify-center gap-2.5 rounded-[14px] border border-[#0E9C6B] text-[17px] font-semibold text-[#0E9C6B]"
                >
                    <span class="h-2.5 w-2.5 rounded-full bg-[#0E9C6B]" />
                    Ready · waiting to start
                </div>

                <div class="flex items-center justify-center gap-6">
                    <button
                        v-if="isMeReady"
                        type="button"
                        :disabled="isActing"
                        class="h-11 text-[15px] font-semibold text-muted-foreground hover:text-foreground disabled:opacity-50"
                        @click="toggleReady"
                    >
                        Unready
                    </button>
                    <button
                        type="button"
                        :disabled="isActing"
                        class="h-11 text-[15px] font-semibold text-muted-foreground hover:text-foreground disabled:opacity-50"
                        @click="leaveGame"
                    >
                        Leave room
                    </button>
                </div>
            </div>
        </div>

        <HowToPlayModal v-if="showHowToPlay" :card-style="cardStyleFor(game)" @close="showHowToPlay = false" />
    </div>
</template>
