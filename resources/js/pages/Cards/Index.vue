<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { Head } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import CardArt from '../../components/game/CardArt.vue';
import { CARD_THEME_LABELS, CardColor, CardTheme, CLOTHING_TYPE_LABELS, ClothingType } from '../../types/game';
import { ALL_CARD_COLORS, ALL_CLOTHING_TYPES, cardFaceClasses, countMissingArt } from '../../utils/cardArt';

type ViewMode = 'sets' | 'shuffled';
type CardSize = 'game' | 'large';

interface PreviewCard {
    key: string;
    clothingType: ClothingType;
    color: CardColor;
}

const SHUFFLED_CARD_COUNT = 40;

const CARD_SIZE_CLASSES: Record<CardSize, string> = {
    // Matches the center pile card face, the largest a card gets in play.
    game: 'h-24 w-16 p-2 sm:h-28 sm:w-20',
    large: 'h-40 w-28 p-3 sm:h-48 sm:w-32',
};

const allClothingTypes = ALL_CLOTHING_TYPES;
const allColors = ALL_CARD_COLORS;
const cardThemes = Object.values(CardTheme);

const viewMode = ref<ViewMode>('sets');
const cardSize = ref<CardSize>('game');
const theme = ref<CardTheme>(CardTheme.Standard);
const hardVariant = ref(false);
const search = ref('');

// Classic counts per set (its silhouette is shared by all four colours); standard counts every card face.
const missingArt = computed(() => countMissingArt(theme.value));
const hardVariantApplies = computed(() => theme.value !== CardTheme.Classic);

const visibleClothingTypes = computed(() => {
    const term = search.value.trim().toLowerCase();
    if (term === '') {
        return allClothingTypes;
    }
    return allClothingTypes.filter((type) => CLOTHING_TYPE_LABELS[type].toLowerCase().includes(term));
});

function drawShuffledCards(): PreviewCard[] {
    const deck: PreviewCard[] = allClothingTypes.flatMap((clothingType) =>
        allColors.map((color) => ({ key: `${clothingType}-${color}`, clothingType, color })),
    );
    for (let index = deck.length - 1; index > 0; index--) {
        const swapIndex = Math.floor(Math.random() * (index + 1));
        [deck[index], deck[swapIndex]] = [deck[swapIndex], deck[index]];
    }
    return deck.slice(0, SHUFFLED_CARD_COUNT);
}

const shuffledCards = ref<PreviewCard[]>(drawShuffledCards());

function reshuffle() {
    shuffledCards.value = drawShuffledCards();
}

const cardClasses = computed(
    () =>
        `relative flex shrink-0 items-center justify-center overflow-hidden rounded-xl border-2 shadow-md ${CARD_SIZE_CLASSES[cardSize.value]}`,
);

function faceClasses(color: CardColor): string[] {
    return [cardClasses.value, cardFaceClasses(color, theme.value, hardVariant.value)];
}

function toggleButtonClasses(isActive: boolean): string {
    return isActive ? 'bg-foreground text-background' : 'text-muted-foreground hover:bg-muted hover:text-foreground';
}
</script>

<template>
    <Head title="Card gallery" />

    <AppLayout>
        <div class="flex flex-1 flex-col gap-4 p-3 sm:gap-6 sm:p-6">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                <div>
                    <h1 class="text-xl font-bold uppercase tracking-widest">Card gallery</h1>
                    <p class="text-xs text-muted-foreground">
                        {{ allClothingTypes.length }} sets × {{ allColors.length }} colours.
                        <span v-if="missingArt.missing > 0" class="font-semibold text-rose-500">
                            {{ missingArt.missing }}/{{ missingArt.total }} missing {{ CARD_THEME_LABELS[theme].toLowerCase() }} art.
                        </span>
                    </p>
                </div>

                <div class="flex flex-wrap items-center gap-2">
                    <div class="flex rounded-md border border-border p-0.5 text-xs" role="group" aria-label="Card art theme">
                        <button
                            v-for="option in cardThemes"
                            :key="option"
                            @click="theme = option"
                            :aria-pressed="theme === option"
                            class="rounded px-2.5 py-1 font-semibold transition-colors"
                            :class="toggleButtonClasses(theme === option)"
                        >
                            {{ CARD_THEME_LABELS[option] }}
                        </button>
                    </div>

                    <button
                        @click="hardVariant = !hardVariant"
                        :disabled="!hardVariantApplies"
                        :aria-pressed="hardVariant"
                        :title="hardVariantApplies ? undefined : 'Classic cards are always coloured'"
                        class="rounded-md border border-border px-2.5 py-1 text-xs font-semibold transition-colors disabled:cursor-not-allowed disabled:opacity-50"
                        :class="toggleButtonClasses(hardVariant && hardVariantApplies)"
                    >
                        Hard colours
                    </button>

                    <div class="flex rounded-md border border-border p-0.5 text-xs">
                        <button
                            v-for="mode in ['sets', 'shuffled'] as const"
                            :key="mode"
                            @click="viewMode = mode"
                            class="rounded px-2.5 py-1 font-semibold capitalize transition-colors"
                            :class="toggleButtonClasses(viewMode === mode)"
                        >
                            {{ mode }}
                        </button>
                    </div>

                    <div class="flex rounded-md border border-border p-0.5 text-xs">
                        <button
                            v-for="size in ['game', 'large'] as const"
                            :key="size"
                            @click="cardSize = size"
                            class="rounded px-2.5 py-1 font-semibold transition-colors"
                            :class="toggleButtonClasses(cardSize === size)"
                        >
                            {{ size === 'game' ? 'In-game size' : 'Large' }}
                        </button>
                    </div>

                    <input
                        v-if="viewMode === 'sets'"
                        v-model="search"
                        type="search"
                        placeholder="Filter sets"
                        aria-label="Filter sets"
                        class="w-36 rounded-md border border-border bg-background px-2.5 py-1 text-xs"
                    />
                    <button
                        v-else
                        @click="reshuffle"
                        class="rounded-md border border-border px-2.5 py-1 text-xs font-semibold transition-colors hover:bg-muted"
                    >
                        Reshuffle
                    </button>
                </div>
            </div>

            <!-- Every set, colours in suit order: checks each item reads in all four colours -->
            <div v-if="viewMode === 'sets'" class="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-3">
                <section v-for="type in visibleClothingTypes" :key="type" class="flex flex-col gap-2 rounded-xl border border-border bg-card p-3">
                    <h2 class="flex items-baseline justify-between text-sm font-semibold">
                        <span>{{ CLOTHING_TYPE_LABELS[type] }}</span>
                        <span class="text-[10px] font-normal text-muted-foreground">#{{ type }}</span>
                    </h2>
                    <div class="flex gap-2 overflow-x-auto">
                        <div
                            v-for="color in allColors"
                            :key="color"
                            :class="faceClasses(color)"
                            :title="CLOTHING_TYPE_LABELS[type]"
                        >
                            <CardArt :type="type" :color="color" :theme="theme" />
                        </div>
                    </div>
                </section>
                <p v-if="visibleClothingTypes.length === 0" class="text-sm text-muted-foreground">No sets match "{{ search }}".</p>
            </div>

            <!-- A random spread, unlabelled: checks sets can be told apart at a glance -->
            <div v-else class="flex flex-wrap justify-center gap-2 sm:gap-3">
                <div
                    v-for="card in shuffledCards"
                    :key="card.key"
                    :class="faceClasses(card.color)"
                    :title="CLOTHING_TYPE_LABELS[card.clothingType]"
                >
                    <CardArt :type="card.clothingType" :color="card.color" :theme="theme" />
                </div>
            </div>
        </div>
    </AppLayout>
</template>
