<script setup lang="ts">
import { onMounted, onUnmounted } from 'vue';
import { CardColor, CardTheme, ClothingType } from '../../types/game';
import type { CardStyle } from '../../utils/cardArt';
import { cardFaceClasses } from '../../utils/cardArt';
import CardArt from './CardArt.vue';

// Shown from the lobby (the style being picked) and from a game's lobby (that game's style).
const props = withDefaults(
    defineProps<{
        cardStyle?: CardStyle;
    }>(),
    { cardStyle: () => ({ theme: CardTheme.Standard, hardVariant: false, backgroundSeed: 0 }) },
);

const emit = defineEmits<{ close: [] }>();

// Coloured faces keep today's soft white edge; the white standard face keeps its own border.
function exampleFaceClasses(type: ClothingType, color: CardColor): string[] {
    const isColouredFace = props.cardStyle.theme === CardTheme.Classic || props.cardStyle.hardVariant;
    return [cardFaceClasses(type, color, props.cardStyle), isColouredFace ? 'border-white/50' : ''];
}

const fanColors: CardColor[] = [CardColor.Red, CardColor.Blue, CardColor.Green, CardColor.Yellow];

function onKeydown(event: KeyboardEvent) {
    if (event.key === 'Escape') {
        emit('close');
    }
}

onMounted(() => {
    window.addEventListener('keydown', onKeydown);
    document.body.style.overflow = 'hidden';
});

onUnmounted(() => {
    window.removeEventListener('keydown', onKeydown);
    document.body.style.overflow = '';
});
</script>

<template>
    <div
        class="fixed inset-0 z-50 flex items-end justify-center bg-black/60 backdrop-blur-sm sm:items-center"
        @click.self="emit('close')"
    >
        <div
            class="relative flex max-h-[92vh] w-full max-w-2xl flex-col overflow-hidden rounded-t-2xl bg-card shadow-2xl sm:max-h-[85vh] sm:rounded-2xl"
        >
            <!-- Header -->
            <div class="flex items-center justify-between border-b border-border px-5 py-3 sm:px-6 sm:py-4">
                <h2 class="text-base font-bold sm:text-lg">How to play Piles!</h2>
                <button
                    @click="emit('close')"
                    aria-label="Close"
                    class="rounded-full p-1.5 text-muted-foreground hover:bg-muted active:scale-95"
                >
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                        <line x1="6" y1="6" x2="18" y2="18" />
                        <line x1="6" y1="18" x2="18" y2="6" />
                    </svg>
                </button>
            </div>

            <!-- Scrollable body -->
            <div class="flex-1 space-y-6 overflow-y-auto px-5 py-5 text-sm leading-relaxed sm:px-6 sm:py-6 sm:text-base">
                <!-- Goal -->
                <section>
                    <h3 class="mb-2 text-sm font-bold uppercase tracking-wider text-emerald-600 dark:text-emerald-400 sm:text-xs">Goal</h3>
                    <p>
                        Be the first to turn all <strong>six</strong> of your piles into complete matching sets, then shout
                        <span class="font-black tracking-wider text-emerald-600 dark:text-emerald-400">"PILES!"</span>
                    </p>
                    <p class="mt-2 text-muted-foreground">
                        A complete set is <strong>4 cards of the same clothing item</strong>, one in each of the four colours:
                    </p>
                    <!-- Matching set visual -->
                    <div class="mt-3 flex items-center justify-center gap-1.5 rounded-xl border border-border bg-muted/20 p-4">
                        <div v-for="(color, i) in fanColors" :key="color" class="set-deal" :style="{ animationDelay: `${i * 110}ms` }">
                            <div
                                class="set-glow flex h-20 w-14 items-center justify-center overflow-hidden rounded-lg border-2 p-1 shadow"
                                :class="exampleFaceClasses(ClothingType.Hoodie, color)"
                                :style="{
                                    transform: `translateY(${Math.abs(i - 1.5) * 3}px) rotate(${(i - 1.5) * 4}deg)`,
                                    animationDelay: `${i * 120}ms`,
                                }"
                            >
                                <CardArt :type="ClothingType.Hoodie" :color="color" :theme="cardStyle.theme" />
                            </div>
                        </div>
                    </div>
                    <p class="mt-2 text-center text-xs text-muted-foreground">Four hoodies, one of each colour = a complete set</p>
                </section>

                <!-- Setup -->
                <section>
                    <h3 class="mb-2 text-sm font-bold uppercase tracking-wider text-emerald-600 dark:text-emerald-400 sm:text-xs">Setup</h3>
                    <ul class="list-disc space-y-1 pl-5">
                        <li>Playable with <strong>2 to 10 players</strong>.</li>
                        <li>Each player is dealt <strong>6 piles of 4 cards</strong>, face-down.</li>
                        <li>A few extra cards are dealt <strong>face-up in the centre</strong>, shared by everyone — 4 normally, 8 in variant mode.</li>
                        <li>With <strong>Similar items</strong> on, look-alike items, such as two jackets or two hats, always come in at least pairs, so sets are harder to tell apart.</li>
                        <li>When the countdown ends, everyone starts at once — <strong>no turns</strong>.</li>
                    </ul>
                </section>

                <!-- Gameplay -->
                <section>
                    <h3 class="mb-2 text-sm font-bold uppercase tracking-wider text-emerald-600 dark:text-emerald-400 sm:text-xs">Playing</h3>
                    <ul class="list-disc space-y-1.5 pl-5">
                        <li>Tap one of your piles to <strong>open it</strong>. Its four cards appear under the centre.</li>
                        <li>
                            <strong>Drag</strong> one of your cards onto a centre card to <strong>swap</strong> them, or drag a centre card
                            onto one of yours. Let go anywhere else and the card goes back.
                        </li>
                        <li>
                            Prefer tapping? Tap your card, then the centre card, or the centre card first, then yours. Tap a chosen card
                            again to put it back.
                        </li>
                        <li>You only ever swap with the centre, never with other players, and one card at a time.</li>
                        <li>
                            Everyone plays at once. If someone takes the same centre card a moment before you, your card bounces back. Just try
                            again.
                        </li>
                        <li>Drag your piles, or the cards in an open pile, to arrange them however helps you.</li>
                        <li>Once a pile is a complete set, it flips <strong>face-up</strong> and locks — no more changes.</li>
                    </ul>
                    <!-- Illustrations: looping demos of the two drags. Static with reduced motion. -->
                    <div class="mt-4 grid gap-3 sm:grid-cols-2">
                        <figure class="flex flex-col items-center gap-2 rounded-xl border border-border bg-muted/20 px-4 pb-3 pt-5">
                            <div class="relative h-[84px] w-[176px]" aria-hidden="true">
                                <div
                                    class="swap-centre absolute left-[128px] top-3 flex h-16 w-12 items-center justify-center overflow-hidden rounded-lg border-2 p-1 shadow"
                                    :class="exampleFaceClasses(ClothingType.Hoodie, CardColor.Blue)"
                                >
                                    <CardArt :type="ClothingType.Hoodie" :color="CardColor.Blue" :theme="cardStyle.theme" />
                                </div>
                                <div
                                    class="swap-mine absolute left-0 top-3 z-10 flex h-16 w-12 items-center justify-center overflow-hidden rounded-lg border-2 p-1 shadow"
                                    :class="exampleFaceClasses(ClothingType.Jeans, CardColor.Red)"
                                >
                                    <CardArt :type="ClothingType.Jeans" :color="CardColor.Red" :theme="cardStyle.theme" />
                                </div>
                                <span class="demo-touch absolute left-[15px] top-[35px] z-20 h-[18px] w-[18px] rounded-full border-2 border-white bg-[#F2B42B]/70 shadow-[0_2px_6px_rgba(0,0,0,0.3)]" />
                            </div>
                            <div class="flex w-[176px] justify-between text-[10px] font-medium uppercase tracking-wider text-muted-foreground">
                                <span class="w-12 text-center">Yours</span>
                                <span class="w-12 text-center">Centre</span>
                            </div>
                            <figcaption class="text-xs text-muted-foreground">Drag onto the centre to swap</figcaption>
                        </figure>

                        <figure class="flex flex-col items-center gap-2 rounded-xl border border-border bg-muted/20 px-4 pb-3 pt-5">
                            <div class="relative h-[84px] w-[184px]" aria-hidden="true">
                                <div
                                    v-for="slot in [0, 2, 3]"
                                    :key="slot"
                                    class="absolute top-4 flex h-14 w-10 items-center justify-center rounded-lg border-2 border-border bg-card"
                                    :class="slot === 2 ? 'arrange-neighbour' : ''"
                                    :style="{ left: `${slot * 48}px` }"
                                >
                                    <span class="demo-back h-8 w-6 rounded" />
                                </div>
                                <div
                                    class="arrange-moving absolute left-[48px] top-4 z-10 flex h-14 w-10 items-center justify-center rounded-lg border-2 border-[#0E9C6B] bg-[#E5F4ED] p-1 dark:bg-[#123528]"
                                >
                                    <CardArt :type="ClothingType.Hoodie" :color="CardColor.Green" :theme="cardStyle.theme" />
                                </div>
                                <span class="demo-touch arrange-touch absolute left-[59px] top-[35px] z-20 h-[18px] w-[18px] rounded-full border-2 border-white bg-[#F2B42B]/70 shadow-[0_2px_6px_rgba(0,0,0,0.3)]" />
                            </div>
                            <figcaption class="mt-auto text-xs text-muted-foreground">Drag piles or cards to rearrange</figcaption>
                        </figure>
                    </div>
                </section>

                <!-- Winning -->
                <section>
                    <h3 class="mb-2 text-sm font-bold uppercase tracking-wider text-emerald-600 dark:text-emerald-400 sm:text-xs">Winning</h3>
                    <p>
                        When all six of your piles are complete, the big
                        <span class="rounded-md bg-emerald-600 px-2 py-0.5 text-xs font-black tracking-wider text-white">PILES!</span>
                        button pops up. Tap it to win, or it calls it for you after a moment. First to finish wins.
                    </p>
                </section>
            </div>

            <!-- Footer -->
            <div class="border-t border-border px-5 py-3 sm:px-6 sm:py-4">
                <button
                    @click="emit('close')"
                    class="w-full rounded-lg bg-primary px-4 py-2.5 text-sm font-semibold text-primary-foreground transition-transform hover:bg-primary/90 active:scale-[0.98]"
                >
                    Got it
                </button>
            </div>
        </div>
    </div>
</template>

<style scoped>
/* Small looping demos, built from the real card art so they always match the
   chosen theme. Each loop pauses at rest so it doesn't nag. */

/* Goal: the set deals in once, then a green glow sweeps across it now and then. */
.set-deal {
    animation: set-deal 420ms cubic-bezier(0.2, 0.8, 0.2, 1) both;
}

.set-glow {
    animation: set-glow 4.2s ease-in-out 600ms infinite;
}

@keyframes set-deal {
    from {
        opacity: 0;
        transform: translateY(14px);
    }
}

@keyframes set-glow {
    0%,
    62%,
    90%,
    100% {
        box-shadow: 0 1px 3px rgba(0, 0, 0, 0.15);
    }
    74% {
        box-shadow:
            0 0 0 3px #0e9c6b,
            0 0 18px 4px rgba(14, 156, 107, 0.45);
    }
}

/* Swap: pick up, drag across, the centre card rises, they trade places. */
.swap-mine,
.swap-centre,
.demo-touch {
    animation-duration: 5s;
    animation-iteration-count: infinite;
    animation-timing-function: ease-in-out;
}

.swap-mine {
    animation-name: swap-mine;
}

.swap-centre {
    animation-name: swap-centre;
}

.demo-touch {
    opacity: 0;
    animation-name: swap-touch;
}

@keyframes swap-mine {
    0%,
    8% {
        transform: translate(0, 0) scale(1) rotate(0);
        opacity: 1;
        box-shadow: 0 1px 3px rgba(0, 0, 0, 0.15);
    }
    14% {
        transform: translate(0, -6px) scale(1.08) rotate(0);
        box-shadow: 0 10px 18px rgba(0, 0, 0, 0.25);
    }
    38% {
        transform: translate(112px, -10px) scale(1.08) rotate(5deg);
    }
    46% {
        transform: translate(112px, -10px) scale(1.08) rotate(0);
        box-shadow: 0 10px 18px rgba(0, 0, 0, 0.25);
    }
    58% {
        transform: translate(112px, 0) scale(1) rotate(0);
        box-shadow: 0 0 0 3px #0e9c6b;
    }
    72%,
    100% {
        box-shadow: 0 1px 3px rgba(0, 0, 0, 0.15);
    }
    88% {
        transform: translate(112px, 0);
        opacity: 1;
    }
    94% {
        transform: translate(112px, 0);
        opacity: 0;
    }
    95% {
        transform: translate(0, 0);
        opacity: 0;
    }
    100% {
        transform: translate(0, 0);
        opacity: 1;
    }
}

@keyframes swap-centre {
    0%,
    34% {
        transform: translate(0, 0) scale(1);
        opacity: 1;
        box-shadow: 0 1px 3px rgba(0, 0, 0, 0.15);
    }
    42%,
    48% {
        transform: translate(0, 0) scale(1.07);
        box-shadow:
            0 0 0 3px #f2b42b,
            0 0 18px 4px rgba(242, 180, 43, 0.5);
    }
    60% {
        transform: translate(-56px, -24px) scale(1);
    }
    70% {
        transform: translate(-112px, 0) scale(1);
        box-shadow: 0 0 0 3px #0e9c6b;
    }
    82%,
    100% {
        box-shadow: 0 1px 3px rgba(0, 0, 0, 0.15);
    }
    88% {
        transform: translate(-112px, 0);
        opacity: 1;
    }
    94% {
        transform: translate(-112px, 0);
        opacity: 0;
    }
    95% {
        transform: translate(0, 0);
        opacity: 0;
    }
    100% {
        transform: translate(0, 0);
        opacity: 1;
    }
}

@keyframes swap-touch {
    0%,
    8% {
        opacity: 0;
        transform: translate(0, 0) scale(0.6);
    }
    13% {
        opacity: 1;
        transform: translate(0, -6px) scale(1);
    }
    38%,
    46% {
        opacity: 1;
        transform: translate(112px, -10px) scale(1);
    }
    54%,
    100% {
        opacity: 0;
        transform: translate(112px, -10px) scale(1.6);
    }
}

/* Rearrange: a finished pile is dragged one place along and back; its neighbour makes room. */
.arrange-moving,
.arrange-neighbour,
.arrange-touch {
    animation-duration: 5s;
    animation-iteration-count: infinite;
    animation-timing-function: ease-in-out;
}

.arrange-moving {
    animation-name: arrange-moving;
}

.arrange-neighbour {
    animation-name: arrange-neighbour;
}

.arrange-touch {
    animation-name: arrange-touch;
}

@keyframes arrange-moving {
    0%,
    8%,
    92%,
    100% {
        transform: translate(0, 0) scale(1);
        box-shadow: none;
    }
    14%,
    86% {
        transform: translate(0, -4px) scale(1.08);
        box-shadow: 0 8px 14px rgba(0, 0, 0, 0.22);
    }
    34%,
    66% {
        transform: translate(48px, -4px) scale(1.08);
        box-shadow: 0 8px 14px rgba(0, 0, 0, 0.22);
    }
    42%,
    58% {
        transform: translate(48px, 0) scale(1);
        box-shadow: none;
    }
}

@keyframes arrange-neighbour {
    0%,
    20%,
    80%,
    100% {
        transform: translateX(0);
    }
    30%,
    70% {
        transform: translateX(-48px);
    }
}

@keyframes arrange-touch {
    0%,
    8% {
        opacity: 0;
        transform: translate(0, 0) scale(0.6);
    }
    13% {
        opacity: 1;
        transform: translate(0, -4px) scale(1);
    }
    34% {
        opacity: 1;
        transform: translate(48px, -4px) scale(1);
    }
    42%,
    58% {
        opacity: 0;
        transform: translate(48px, 0) scale(1.5);
    }
    63% {
        opacity: 1;
        transform: translate(48px, -4px) scale(1);
    }
    86% {
        opacity: 1;
        transform: translate(0, -4px) scale(1);
    }
    92%,
    100% {
        opacity: 0;
        transform: translate(0, 0) scale(1.5);
    }
}

.demo-back {
    background: repeating-linear-gradient(45deg, #2a2926 0 3px, #3a3935 3px 6px);
}

@media (prefers-reduced-motion: reduce) {
    .set-deal,
    .set-glow,
    .swap-mine,
    .swap-centre,
    .demo-touch,
    .arrange-moving,
    .arrange-neighbour {
        animation: none;
    }
}
</style>
