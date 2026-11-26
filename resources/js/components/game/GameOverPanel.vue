<script setup lang="ts">
import { computed, ref } from 'vue';
import { useGameStore } from '../../stores/game';
import { avatarColorClass } from '../../utils/avatar';

/**
 * The end of a game. Keeps the table together: "Play again" takes everyone who
 * taps it into one new lobby with the same settings, and shows who's already in.
 */
const emit = defineEmits<{
    leave: [];
}>();

const gameStore = useGameStore();
const isRequesting = ref(false);

const myUserId = computed(() => gameStore.currentPlayer?.user_id ?? null);
const winner = computed(() => gameStore.winner);
const iWon = computed(() => !!winner.value && winner.value.user_id === myUserId.value);
const rematchUserIds = computed(() => gameStore.rematch.user_ids);

/** Everyone, winner first, then by piles done. */
const results = computed(() =>
    gameStore.leaderboard
        .map((row) => ({
            ...row,
            completed: row.piles.filter((pile) => pile.is_completed).length,
            isMe: row.user_id === myUserId.value,
            isWinner: row.user_id === winner.value?.user_id,
        }))
        .sort((a, b) => Number(b.isWinner) - Number(a.isWinner) || b.completed - a.completed || a.seat_index - b.seat_index),
);

const myResult = computed(() => results.value.find((row) => row.isMe) ?? null);
const myRank = computed(() => results.value.findIndex((row) => row.isMe) + 1);
/** The other people at the table: bots don't decide, they come along with whoever starts the next round. */
const otherPeople = computed(() => results.value.filter((row) => !row.isMe && !row.is_bot));
const hasBots = computed(() => results.value.some((row) => row.is_bot));

const title = computed(() => {
    if (gameStore.forfeitedBy) {
        return `${gameStore.forfeitedBy} forfeited`;
    }
    if (iWon.value) {
        return 'You won!';
    }
    if (winner.value) {
        return `${winner.value.name} wins`;
    }

    return 'Game over';
});

const subtitle = computed(() => {
    if (gameStore.forfeitedBy) {
        return 'No winner this time.';
    }
    if (iWon.value) {
        return 'All 6 piles complete.';
    }
    if (winner.value && myResult.value) {
        return `You finished ${ordinal(myRank.value)} with ${myResult.value.completed} of 6 piles.`;
    }

    return 'Result unavailable.';
});

function ordinal(position: number): string {
    const suffixes: Record<number, string> = { 1: 'st', 2: 'nd', 3: 'rd' };
    const lastTwo = position % 100;

    return `${position}${lastTwo >= 11 && lastTwo <= 13 ? 'th' : (suffixes[position % 10] ?? 'th')}`;
}

async function playAgain() {
    if (isRequesting.value) {
        return;
    }
    isRequesting.value = true;
    try {
        const joined = await gameStore.requestRematch();
        if (!joined) {
            isRequesting.value = false;
        }
    } catch {
        isRequesting.value = false;
    }
}
</script>

<template>
    <div
        class="fixed inset-0 z-50 flex items-end justify-center bg-[rgba(22,21,20,0.5)] dark:bg-[rgba(0,0,0,0.62)] md:items-center"
        role="dialog"
        aria-modal="true"
        aria-labelledby="game-over-title"
    >
        <div
            class="flex max-h-full w-full flex-col gap-5 overflow-y-auto rounded-t-[26px] bg-card p-7 shadow-[0_30px_80px_rgba(0,0,0,0.35)] md:w-[480px] md:rounded-[22px]"
        >
            <div class="flex flex-col gap-1.5">
                <span class="text-[13px] font-semibold uppercase tracking-[0.08em] text-muted-foreground">Game over</span>
                <h2 id="game-over-title" class="text-4xl font-bold leading-[1.05] tracking-[-0.01em]">{{ title }}</h2>
                <p class="text-base text-muted-foreground">{{ subtitle }}</p>
            </div>

            <ol class="flex flex-col overflow-hidden rounded-[14px] border border-border">
                <li
                    v-for="(row, index) in results"
                    :key="row.id"
                    class="flex items-center gap-3 border-b border-border px-3.5 py-3 last:border-b-0"
                    :class="row.isWinner ? 'bg-[#E5F4ED] dark:bg-[rgba(14,156,107,0.16)]' : 'bg-card'"
                >
                    <span class="w-[18px] text-[15px] font-bold text-muted-foreground">{{ index + 1 }}</span>
                    <span
                        class="flex h-[30px] w-[30px] shrink-0 items-center justify-center rounded-full text-sm font-bold text-white"
                        :class="avatarColorClass(row.seat_index)"
                    >
                        {{ row.name.charAt(0).toUpperCase() }}
                    </span>
                    <span class="flex min-w-0 flex-1 items-center gap-2">
                        <span class="truncate text-base font-semibold">{{ row.name }}{{ row.isMe ? ' (you)' : '' }}</span>
                        <span
                            v-if="row.is_bot"
                            class="shrink-0 rounded-full border border-border px-1.5 py-px text-[10px] font-bold uppercase tracking-[0.08em] text-muted-foreground"
                        >
                            Bot
                        </span>
                    </span>
                    <span class="flex gap-[3px]" aria-hidden="true">
                        <span
                            v-for="slot in 6"
                            :key="slot"
                            class="h-3.5 w-2.5 rounded-[3px]"
                            :class="slot <= row.completed ? 'bg-[#0E9C6B]' : 'bg-border'"
                        />
                    </span>
                    <span class="w-8 text-right text-[15px] font-bold tabular-nums">{{ row.completed }}/6</span>
                </li>
            </ol>

            <ul v-if="otherPeople.length || hasBots" class="flex flex-col gap-1.5 text-[15px]">
                <li v-for="row in otherPeople" :key="row.id" class="flex items-center gap-2.5">
                    <span
                        class="h-2.5 w-2.5 shrink-0 rounded-full"
                        :class="rematchUserIds.includes(row.user_id) ? 'bg-[#0E9C6B]' : 'bg-stone-300 dark:bg-zinc-600'"
                    />
                    <span>
                        <strong>{{ row.name }}</strong>
                        <span class="text-muted-foreground">
                            {{ rematchUserIds.includes(row.user_id) ? ' is ready for another round' : ' is deciding…' }}
                        </span>
                    </span>
                </li>
                <li v-if="hasBots" class="flex items-center gap-2.5 text-muted-foreground">
                    <span class="h-2.5 w-2.5 shrink-0 rounded-full bg-[#0E9C6B]" />
                    The bots come along for another round.
                </li>
            </ul>

            <div class="flex flex-col gap-2.5">
                <button
                    type="button"
                    :disabled="isRequesting"
                    class="h-14 rounded-[14px] bg-[#0E9C6B] text-[19px] font-bold text-white transition-transform hover:bg-[#0B8A5E] active:scale-[0.98] disabled:opacity-70"
                    @click="playAgain"
                >
                    {{ isRequesting ? 'Joining…' : 'Play again' }}
                </button>
                <p class="text-center text-[13px] text-muted-foreground">Same room, same players. The next round starts when everyone's in.</p>
                <button type="button" class="h-11 text-[15px] font-semibold text-muted-foreground hover:text-foreground" @click="emit('leave')">
                    Leave room
                </button>
            </div>
        </div>
    </div>
</template>
