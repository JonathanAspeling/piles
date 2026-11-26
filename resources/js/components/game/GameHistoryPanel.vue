<script setup lang="ts">
import { computed } from 'vue';
import type { HistoryGame, PlayerHistory } from '../../types/game';

/**
 * Your record in the lobby: totals, your fastest win, who you play most and
 * your latest games. The history arrives just after the lobby renders (a
 * deferred prop), so until then the card holds its shape with a skeleton.
 */
const props = defineProps<{
    history: PlayerHistory | undefined;
}>();

const stats = computed(() => {
    if (!props.history) {
        return [];
    }

    return [
        { label: 'Played', value: String(props.history.played) },
        { label: 'Won', value: String(props.history.won) },
        { label: 'Win streak', value: String(props.history.win_streak) },
        { label: 'Fastest win', value: props.history.fastest_win_seconds === null ? '—' : formatDuration(props.history.fastest_win_seconds) },
    ];
});

function formatDuration(totalSeconds: number): string {
    const minutes = Math.floor(totalSeconds / 60);
    const seconds = totalSeconds % 60;

    return minutes === 0 ? `${seconds}s` : `${minutes}m ${String(seconds).padStart(2, '0')}s`;
}

const relativeTime = new Intl.RelativeTimeFormat(undefined, { numeric: 'auto' });

function formatWhen(isoDate: string | null): string {
    if (!isoDate) {
        return '';
    }
    const minutesAgo = Math.round((Date.now() - new Date(isoDate).getTime()) / 60_000);
    if (minutesAgo < 1) {
        return 'just now';
    }
    if (minutesAgo < 60) {
        return relativeTime.format(-minutesAgo, 'minute');
    }
    const hoursAgo = Math.round(minutesAgo / 60);
    if (hoursAgo < 24) {
        return relativeTime.format(-hoursAgo, 'hour');
    }

    return relativeTime.format(-Math.round(hoursAgo / 24), 'day');
}

function resultLabel(game: HistoryGame): string {
    if (game.result === 'won') {
        return 'You won';
    }

    return game.result === 'lost' ? `${game.winner_name} won` : 'No winner';
}

function resultClasses(game: HistoryGame): string {
    if (game.result === 'won') {
        return 'border-[#0E9C6B] bg-[#E5F4ED] text-[#0B7A54] dark:bg-[rgba(14,156,107,0.16)] dark:text-[#5FD3A5]';
    }

    return 'border-border bg-muted text-muted-foreground';
}
</script>

<template>
    <section data-game-history class="rounded-xl border border-sidebar-border/70 bg-card p-4 dark:border-sidebar-border sm:p-6">
        <h2 class="mb-4 text-lg font-semibold">Your Games</h2>

        <!-- Loading: the same layout, pulsing. -->
        <div v-if="!history" class="flex animate-pulse flex-col gap-4" aria-busy="true" aria-label="Loading your games">
            <div class="grid grid-cols-2 gap-2 sm:grid-cols-4">
                <div v-for="tile in 4" :key="tile" class="h-[62px] rounded-lg bg-muted" />
            </div>
            <div class="h-4 w-1/3 rounded bg-muted" />
            <div v-for="row in 3" :key="row" class="h-12 rounded-lg bg-muted" />
        </div>

        <p v-else-if="history.recent.length === 0" class="py-8 text-center text-sm text-muted-foreground">
            No finished games yet. Your record shows up here after your first game.
        </p>

        <div v-else class="flex flex-col gap-5">
            <dl class="grid grid-cols-2 gap-2 sm:grid-cols-4">
                <div v-for="stat in stats" :key="stat.label" class="flex flex-col gap-0.5 rounded-lg border border-border px-3 py-2.5">
                    <dt class="text-[11px] font-bold uppercase tracking-[0.1em] text-muted-foreground">{{ stat.label }}</dt>
                    <dd class="text-xl font-bold tabular-nums">{{ stat.value }}</dd>
                </div>
            </dl>

            <div v-if="history.rivals.length" class="flex flex-col gap-2">
                <h3 class="text-[11px] font-bold uppercase tracking-[0.1em] text-muted-foreground">Played most with</h3>
                <ul class="flex flex-wrap gap-2">
                    <li
                        v-for="rival in history.rivals"
                        :key="rival.name"
                        class="flex items-baseline gap-2 rounded-full border border-border px-3 py-1 text-sm"
                    >
                        <span class="font-semibold">{{ rival.name }}</span>
                        <span class="text-muted-foreground">
                            {{ rival.games }} {{ rival.games === 1 ? 'game' : 'games' }} ·
                            <span class="tabular-nums">{{ rival.my_wins }}–{{ rival.their_wins }}</span>
                        </span>
                    </li>
                </ul>
            </div>

            <div class="flex flex-col gap-2">
                <div class="flex items-baseline justify-between gap-3">
                    <h3 class="text-[11px] font-bold uppercase tracking-[0.1em] text-muted-foreground">Recent games</h3>
                    <span v-if="history.recent.some((game) => game.with_bots)" class="text-xs text-muted-foreground"
                        >Games with bots don't count</span
                    >
                </div>
                <ol class="flex flex-col gap-2">
                    <li
                        v-for="game in history.recent"
                        :key="game.id"
                        data-history-game
                        class="flex flex-col gap-1.5 rounded-lg border border-border px-3 py-2.5 sm:flex-row sm:items-center sm:justify-between sm:gap-4"
                    >
                        <div class="flex min-w-0 items-center gap-2.5">
                            <span class="shrink-0 rounded-full border px-2 py-0.5 text-xs font-semibold" :class="resultClasses(game)">
                                {{ resultLabel(game) }}
                            </span>
                            <span class="truncate text-sm"> <span class="text-muted-foreground">vs</span> {{ game.opponents.join(', ') }} </span>
                        </div>
                        <div class="flex shrink-0 flex-wrap items-center gap-x-2 gap-y-0.5 text-xs text-muted-foreground sm:text-sm">
                            <span v-if="game.with_bots" class="rounded bg-muted px-1.5 py-0.5 text-[10px] font-medium uppercase tracking-wide"
                                >Bots</span
                            >
                            <span v-if="game.variant" class="rounded bg-muted px-1.5 py-0.5 text-[10px] font-medium uppercase tracking-wide"
                                >Variant</span
                            >
                            <span v-if="game.similar_items" class="rounded bg-muted px-1.5 py-0.5 text-[10px] font-medium uppercase tracking-wide"
                                >Similar items</span
                            >
                            <span v-if="game.duration_seconds !== null" class="tabular-nums">{{ formatDuration(game.duration_seconds) }}</span>
                            <span v-if="game.duration_seconds !== null && game.ended_at" class="text-muted-foreground/50">·</span>
                            <span v-if="game.ended_at">{{ formatWhen(game.ended_at) }}</span>
                        </div>
                    </li>
                </ol>
            </div>
        </div>
    </section>
</template>
