<script setup lang="ts">
import { computed } from 'vue';
import { useGameStore } from '../../stores/game';
import type { OpponentState } from '../../types/game';
import { CardColor, CardTheme, CLOTHING_TYPE_LABELS } from '../../types/game';
import { avatarColorClass } from '../../utils/avatar';
import { completedSetFaceClasses } from '../../utils/cardArt';
import CardArt from './CardArt.vue';

/**
 * An opponent's progress. It's the opponents you need to watch in a race,
 * so they get the space; your own count lives on "Your piles".
 */
const props = defineProps<{
    player: OpponentState;
    theme: CardTheme;
    /** sidebar: the desktop column. strip: the phone's top row, tighter. */
    layout: 'sidebar' | 'strip';
}>();

const gameStore = useGameStore();

const completedCount = computed(() => props.player.piles.filter((pile) => pile.is_completed).length);
// Bots have no connection to lose.
const isOnline = computed(() => props.player.is_bot || gameStore.connectedUserIds.includes(props.player.user_id));
/** One pile from winning: the moment everyone else needs to notice. */
const isClose = computed(() => completedCount.value === 5);
const isStrip = computed(() => props.layout === 'strip');

// A completed set stands for all four colours; the illustrated themes show it
// in its first colourway (classic's silhouette is colourless anyway).
const COMPLETED_SET_COLOR = CardColor.Red;
</script>

<template>
    <div
        class="flex flex-col rounded-[14px] border-2 transition-[border-color,background-color,box-shadow] duration-300"
        :class="[
            isStrip ? 'gap-2 px-3 py-2.5' : 'gap-3 p-3.5',
            isClose
                ? 'border-[#E0442F] bg-[#FCE9E6] shadow-[0_0_0_5px_#FCE9E6] dark:bg-[rgba(224,68,47,0.16)] dark:shadow-[0_0_0_5px_rgba(224,68,47,0.16)]'
                : 'border-border bg-card',
        ]"
        :data-opponent="player.name"
    >
        <div class="flex items-center" :class="isStrip ? 'gap-2' : 'gap-2.5'">
            <span
                class="relative flex shrink-0 items-center justify-center rounded-full font-bold text-white"
                :class="[
                    avatarColorClass(player.seat_index),
                    isStrip ? 'h-[26px] w-[26px] text-[13px]' : 'h-[34px] w-[34px] text-[15px]',
                    { 'opacity-50': !isOnline },
                ]"
                :title="player.is_bot ? 'Bot' : isOnline ? 'Online' : 'Disconnected'"
            >
                {{ player.name.charAt(0).toUpperCase() }}
            </span>
            <span class="min-w-0 truncate font-semibold" :class="[isStrip ? 'text-base' : 'flex-1 text-lg', { 'text-muted-foreground': !isOnline }]">
                {{ player.name }}
            </span>
            <span
                v-if="player.is_bot"
                class="shrink-0 rounded-full border border-border px-1.5 py-px text-[10px] font-bold uppercase tracking-[0.08em] text-muted-foreground"
            >
                Bot
            </span>
            <span v-if="isClose && isStrip" class="shrink-0 whitespace-nowrap text-[13px] font-bold text-[#E0442F]">· 1 pile from winning</span>
            <span class="ml-auto shrink-0 font-bold tabular-nums" :class="[isStrip ? 'text-[19px]' : 'text-[22px]', isClose ? 'text-[#E0442F]' : '']">
                {{ completedCount }}<span class="text-sm font-medium text-muted-foreground" :class="{ 'text-[13px]': isStrip }">/6</span>
            </span>
        </div>

        <div class="grid grid-cols-6" :class="isStrip ? 'gap-[5px]' : 'gap-1.5'">
            <div
                v-for="pile in player.piles"
                :key="pile.id"
                class="flex items-center justify-center rounded-md border-[1.5px]"
                :class="[
                    isStrip ? 'h-7' : 'h-10 rounded-[7px]',
                    pile.is_completed
                        ? isClose
                            ? 'border-[#E0442F] bg-card'
                            : 'border-[#0E9C6B] bg-[#E5F4ED] dark:bg-[rgba(14,156,107,0.16)]'
                        : 'border-border bg-muted',
                ]"
                :title="
                    pile.is_completed && pile.completed_type !== null ? `Completed: ${CLOTHING_TYPE_LABELS[pile.completed_type]}` : 'Pile in progress'
                "
            >
                <span
                    v-if="pile.is_completed && pile.completed_type !== null"
                    class="flex h-[72%] w-[74%] items-center justify-center rounded-sm"
                    :class="theme === CardTheme.Classic ? completedSetFaceClasses(theme) : ''"
                >
                    <CardArt :type="pile.completed_type" :color="COMPLETED_SET_COLOR" :theme="theme" />
                </span>
            </div>
        </div>

        <div v-if="isClose && !isStrip" class="flex items-center gap-2 text-sm font-bold text-[#E0442F]">
            <span class="h-2 w-2 rounded-full bg-[#E0442F] shadow-[0_0_0_4px_#FCE9E6] dark:shadow-[0_0_0_4px_rgba(224,68,47,0.16)]" />
            1 pile from winning
        </div>
    </div>
</template>
