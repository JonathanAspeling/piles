<script setup lang="ts">
import { onMounted, onUnmounted } from 'vue';
import { CardColor, CARD_COLOR_CLASSES, ClothingType } from '../../types/game';
import CardArt from './CardArt.vue';

const emit = defineEmits<{ close: [] }>();

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
                        <div
                            v-for="(color, i) in fanColors"
                            :key="color"
                            class="flex h-20 w-14 items-center justify-center overflow-hidden rounded-lg border-2 border-white/50 p-1 text-white shadow"
                            :class="CARD_COLOR_CLASSES[color]"
                            :style="{ transform: `translateY(${Math.abs(i - 1.5) * 3}px) rotate(${(i - 1.5) * 4}deg)` }"
                        >
                            <CardArt :type="ClothingType.Hoodie" />
                        </div>
                    </div>
                    <p class="mt-2 text-center text-xs text-muted-foreground">Four hoodies, one of each colour = a complete set</p>
                </section>

                <!-- Setup -->
                <section>
                    <h3 class="mb-2 text-sm font-bold uppercase tracking-wider text-emerald-600 dark:text-emerald-400 sm:text-xs">Setup</h3>
                    <ul class="list-disc space-y-1 pl-5">
                        <li>Each player is dealt <strong>6 piles of 4 cards</strong>, face-down.</li>
                        <li>Four extra cards are dealt <strong>face-up in the centre</strong>, shared by everyone.</li>
                        <li>When the countdown ends, everyone starts at once — <strong>no turns</strong>.</li>
                    </ul>
                </section>

                <!-- Gameplay -->
                <section>
                    <h3 class="mb-2 text-sm font-bold uppercase tracking-wider text-emerald-600 dark:text-emerald-400 sm:text-xs">Playing</h3>
                    <ul class="list-disc space-y-1.5 pl-5">
                        <li>Tap one of your piles to open it and pick <strong>one card</strong> into your hand.</li>
                        <li>Then tap a centre pile to <strong>swap</strong> — your card goes down, the centre card comes up.</li>
                        <li>You may only hold <strong>one card at a time</strong>, and only swap with the centre — never with other players.</li>
                        <li>Once a pile is a complete set, it flips <strong>face-up</strong> and locks — no more changes.</li>
                    </ul>
                    <!-- Swap illustration -->
                    <div class="mt-4 flex items-center justify-center gap-3 rounded-xl border border-border bg-muted/20 px-4 py-4 sm:gap-4">
                        <div class="flex flex-col items-center gap-1.5">
                            <div
                                class="flex h-16 w-12 items-center justify-center overflow-hidden rounded-lg border-2 border-white/50 p-1 text-white shadow ring-2 ring-amber-400"
                                :class="CARD_COLOR_CLASSES[CardColor.Red]"
                            >
                                <CardArt :type="ClothingType.Jeans" />
                            </div>
                            <span class="text-[10px] font-medium uppercase tracking-wider text-muted-foreground">Your card</span>
                        </div>
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6 text-muted-foreground" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M8 3 4 7l4 4" />
                            <path d="M4 7h16" />
                            <path d="m16 21 4-4-4-4" />
                            <path d="M20 17H4" />
                        </svg>
                        <div class="flex flex-col items-center gap-1.5">
                            <div
                                class="flex h-16 w-12 items-center justify-center overflow-hidden rounded-lg border-2 border-white/50 p-1 text-white shadow"
                                :class="CARD_COLOR_CLASSES[CardColor.Blue]"
                            >
                                <CardArt :type="ClothingType.Hoodie" />
                            </div>
                            <span class="text-[10px] font-medium uppercase tracking-wider text-muted-foreground">Centre pile</span>
                        </div>
                    </div>
                </section>

                <!-- Winning -->
                <section>
                    <h3 class="mb-2 text-sm font-bold uppercase tracking-wider text-emerald-600 dark:text-emerald-400 sm:text-xs">Winning</h3>
                    <p>
                        When all six of your piles are complete, the big
                        <span class="rounded-md bg-emerald-600 px-2 py-0.5 text-xs font-black tracking-wider text-white">PILES!</span>
                        button appears — hit it to win. If you're wrong the game keeps going, so double-check before you claim.
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