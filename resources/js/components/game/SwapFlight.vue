<script setup lang="ts">
import { onMounted, ref } from 'vue';
import type { Card } from '../../types/game';
import type { CardStyle } from '../../utils/cardArt';
import { cardFaceClasses } from '../../utils/cardArt';
import CardArt from './CardArt.vue';

export interface SlotRect {
    left: number;
    top: number;
    width: number;
    height: number;
}

export interface Flight {
    card: Card;
    from: SlotRect;
    to: SlotRect;
    /** Which side of the straight line the arc bows to, so two crossing cards don't collide. */
    bow: 1 | -1;
}

/**
 * Copies of the two swapped cards, flown between their slots in viewport
 * coordinates so they can cross zones. The real slots stay hidden underneath
 * until the flight lands, then GameBoard flashes them.
 */
const props = defineProps<{
    flights: Flight[];
    cardStyle: CardStyle;
}>();

const emit = defineEmits<{
    landed: [];
}>();

const DURATION_MS = 420;
const flyerElements = ref<HTMLElement[]>([]);

/** A point part-way along the arc, relative to the flight's start, in px. */
function arcPoint(flight: Flight, progress: number): { x: number; y: number } {
    const dx = flight.to.left - flight.from.left;
    const dy = flight.to.top - flight.from.top;
    const length = Math.hypot(dx, dy) || 1;
    const bowAmount = flight.from.width * 0.5 * Math.sin(Math.PI * progress) * flight.bow;

    return {
        x: dx * progress + (-dy / length) * bowAmount,
        y: dy * progress + (dx / length) * bowAmount,
    };
}

onMounted(async () => {
    const animations = flyerElements.value.map((element, index) => {
        const flight = props.flights[index];
        const steps = [0, 0.25, 0.5, 0.75, 1];

        return element.animate(
            steps.map((progress) => {
                const { x, y } = arcPoint(flight, progress);
                const tilt = Math.sin(Math.PI * progress) * 6 * flight.bow;
                const lift = 1 + Math.sin(Math.PI * progress) * 0.04;

                return { offset: progress, transform: `translate(${x}px, ${y}px) rotate(${tilt}deg) scale(${lift})` };
            }),
            { duration: DURATION_MS, easing: 'cubic-bezier(0.4, 0, 0.2, 1)', fill: 'forwards' },
        ).finished;
    });

    await Promise.allSettled(animations);
    emit('landed');
});
</script>

<template>
    <Teleport to="body">
        <div class="pointer-events-none fixed inset-0 z-50" aria-hidden="true">
            <div
                v-for="(flight, index) in flights"
                :key="flight.card.id"
                ref="flyerElements"
                data-swap-flyer
                class="absolute rounded-[10px] border-[1.5px] md:rounded-[14px]"
                :class="[
                    cardFaceClasses(flight.card.clothing_type, flight.card.color, cardStyle),
                    // The card you lifted keeps its amber ring on the way out.
                    index === 0 ? 'shadow-[0_0_0_3px_#F2B42B,0_20px_34px_rgba(0,0,0,0.3)]' : 'shadow-[0_20px_34px_rgba(0,0,0,0.3)]',
                ]"
                :style="{
                    left: `${flight.from.left}px`,
                    top: `${flight.from.top}px`,
                    width: `${flight.from.width}px`,
                    height: `${flight.from.height}px`,
                }"
            >
                <!-- Percentage padding resolves against the containing block's width: here
                     that's this card, as it is for GameCard, not the full-screen overlay. -->
                <div class="flex h-full w-full items-center justify-center px-[12%] py-[19%]">
                    <CardArt :type="flight.card.clothing_type" :color="flight.card.color" :theme="cardStyle.theme" />
                </div>
            </div>
        </div>
    </Teleport>
</template>
