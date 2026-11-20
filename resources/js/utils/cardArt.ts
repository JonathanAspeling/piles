import cardArtSchemes from '../data/cardArtSchemes.json';
import type { Card, GameSession } from '../types/game';
import { CARD_COLOR_CLASSES, CardColor, CardTheme, ClothingType } from '../types/game';

/**
 * Card art is loaded as separate, hashed files (`?no-inline`), never as data
 * URLs: the bundle only carries the file paths, and the browser fetches an
 * image the first time a card of that theme is drawn. A game in one theme
 * never downloads the other theme's art.
 */

// Classic: one white silhouette per clothing type, `{type}.png`.
const classicModules = import.meta.glob('../../images/cards/*.png', {
    eager: true,
    query: '?no-inline',
    import: 'default',
}) as Record<string, string>;

// Standard: one pre-coloured illustration per (type, colour), `standard/{type}-{color}.webp`.
// Missing files simply aren't in the glob, and CardArt falls back to the label.
const standardModules = import.meta.glob('../../images/cards/standard/*.webp', {
    eager: true,
    query: '?no-inline',
    import: 'default',
}) as Record<string, string>;

const CLASSIC_ART_URLS = new Map<number, string>();
const STANDARD_ART_URLS = new Map<string, string>();

for (const [path, url] of Object.entries(classicModules)) {
    const match = path.match(/\/(\d+)\.png$/);
    if (match) {
        CLASSIC_ART_URLS.set(Number(match[1]), url);
    }
}

for (const [path, url] of Object.entries(standardModules)) {
    const match = path.match(/\/(\d+)-(\d)\.webp$/);
    if (match) {
        STANDARD_ART_URLS.set(`${Number(match[1])}-${Number(match[2])}`, url);
    }
}

export const ALL_CLOTHING_TYPES = Object.values(ClothingType).filter((value): value is ClothingType => typeof value === 'number');
export const ALL_CARD_COLORS = Object.values(CardColor).filter((value): value is CardColor => typeof value === 'number');

export function getCardArtUrl(type: ClothingType, color: CardColor, theme: CardTheme): string | undefined {
    if (theme === CardTheme.Classic) {
        return CLASSIC_ART_URLS.get(type);
    }
    return STANDARD_ART_URLS.get(`${type}-${color}`);
}

/** How many card faces in a theme have no image yet (classic: per type, since colour is only the background). */
export function countMissingArt(theme: CardTheme): { missing: number; total: number } {
    if (theme === CardTheme.Classic) {
        return {
            missing: ALL_CLOTHING_TYPES.filter((type) => !CLASSIC_ART_URLS.has(type)).length,
            total: ALL_CLOTHING_TYPES.length,
        };
    }
    const faces = ALL_CLOTHING_TYPES.flatMap((type) => ALL_CARD_COLORS.map((color) => `${type}-${color}`));
    return {
        missing: faces.filter((face) => !STANDARD_ART_URLS.has(face)).length,
        total: faces.length,
    };
}

/** How a game draws its cards. Every player in a game shares the same style. */
export interface CardStyle {
    theme: CardTheme;
    hardVariant: boolean;
    /** Seeds the per-game shuffle of hard colour variant backgrounds. */
    backgroundSeed: number;
}

export function cardStyleFor(game: Pick<GameSession, 'theme' | 'hard_variant' | 'background_seed'>): CardStyle {
    return { theme: game.theme, hardVariant: game.hard_variant, backgroundSeed: game.background_seed };
}

/** mulberry32: a tiny seeded PRNG returning floats in [0, 1). Same seed, same sequence. */
function seededRandom(seed: number): () => number {
    let state = seed >>> 0;
    return () => {
        state = (state + 0x6d2b79f5) >>> 0;
        let t = state;
        t = Math.imul(t ^ (t >>> 15), t | 1);
        t ^= t + Math.imul(t ^ (t >>> 7), t | 61);
        return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
    };
}

/**
 * The hard colour variant's background for each of an item's four cards: a
 * permutation of the four CardColors, indexed by the card's CardColor.
 * Each item gets its own permutation, fixed for a given seed (so for a whole game).
 */
export function shuffledBackgrounds(seed: number, type: ClothingType): CardColor[] {
    // Mix the item into the seed so items don't share a permutation.
    const random = seededRandom((seed ^ Math.imul(type + 1, 0x9e3779b1)) >>> 0);
    const backgrounds = [...ALL_CARD_COLORS];
    for (let index = backgrounds.length - 1; index > 0; index--) {
        const swapIndex = Math.floor(random() * (index + 1));
        [backgrounds[index], backgrounds[swapIndex]] = [backgrounds[swapIndex], backgrounds[index]];
    }
    return backgrounds;
}

/**
 * The CardColor whose colour a card shows when drawn with a coloured face:
 * its own colour in classic, its shuffled background in standard with hard colours.
 */
function faceColor(type: ClothingType, color: CardColor, style: CardStyle): CardColor {
    if (style.theme === CardTheme.Classic || !style.hardVariant) {
        return color;
    }
    return shuffledBackgrounds(style.backgroundSeed, type)[color];
}

// The white face standard cards sit on; a light border keeps it off a white page.
const STANDARD_FACE_CLASSES = 'bg-white border-slate-200 text-slate-700';

/**
 * Background, border and text colour for a card face.
 *
 * - classic: the CardColor background behind a white silhouette (hard variant has no effect).
 * - standard: a white face, or with the hard colour variant one of the four
 *   CardColor backgrounds, shuffled per item and per game.
 *
 * Text colour only shows when an image is missing and CardArt falls back to the label.
 */
export function cardFaceClasses(type: ClothingType, color: CardColor, style: CardStyle): string {
    if (style.theme === CardTheme.Classic || style.hardVariant) {
        return `${CARD_COLOR_CLASSES[faceColor(type, color, style)]} text-white`;
    }
    return STANDARD_FACE_CLASSES;
}

/** The face for a completed set shown as one card (it holds all four colours, so no CardColor applies). */
export function completedSetFaceClasses(theme: CardTheme): string {
    return theme === CardTheme.Classic ? 'border-green-500 bg-green-400 text-white' : 'border-green-500 bg-white text-slate-700';
}

interface CardArtScheme {
    family: string;
    main: string;
    secondary: string;
    accent: string;
}

const SCHEMES = cardArtSchemes as Record<string, CardArtScheme[]>;

export function getCardArtScheme(type: ClothingType, color: CardColor): CardArtScheme | undefined {
    return SCHEMES[String(type)]?.[color];
}

export interface CardSwatch {
    class: string;
    style?: Record<string, string>;
}

/**
 * A card's colour drawn without its art (e.g. the completed-pile fan).
 *
 * Classic, and standard with the hard colour variant, use the colour of that
 * card's face (shuffled with hard colours, matching cardFaceClasses). Plain
 * standard has no fixed colour per CardColor, so it uses the item's own main
 * colour from its colourway.
 */
export function cardSwatch(type: ClothingType, color: CardColor, style: CardStyle): CardSwatch {
    const scheme = getCardArtScheme(type, color);
    if (style.theme === CardTheme.Classic || style.hardVariant || scheme === undefined) {
        return { class: CARD_COLOR_CLASSES[faceColor(type, color, style)] };
    }
    return {
        class: 'border-white/70',
        style: { backgroundColor: scheme.main },
    };
}

// Kept alive so a preloaded image stays decoded in memory, not just in the HTTP cache.
const preloadedArt = new Map<string, HTMLImageElement>();

/**
 * Download and decode card art before it's first drawn, so cards don't pop in
 * when the board appears. Each image is fetched once; repeat calls are free.
 * Requests go out in the order given, so pass the most urgent faces first.
 */
export function preloadCardArt(faces: Pick<Card, 'clothing_type' | 'color'>[], theme: CardTheme): void {
    for (const face of faces) {
        const url = getCardArtUrl(face.clothing_type, face.color, theme);
        if (url === undefined || preloadedArt.has(url)) {
            continue;
        }
        const image = new Image();
        image.decoding = 'async';
        image.src = url;
        image.decode().catch(() => undefined);
        preloadedArt.set(url, image);
    }
}

/**
 * Preload a dealt game's art: first the faces on screen at GO (your cards and
 * the centre's top cards), then every colourway of those items, since those
 * are the cards you'll be collecting.
 */
export function preloadDealtCardArt(visibleCards: Pick<Card, 'clothing_type' | 'color'>[], theme: CardTheme): void {
    preloadCardArt(visibleCards, theme);

    const dealtTypes = [...new Set(visibleCards.map((card) => card.clothing_type))];
    preloadCardArt(
        dealtTypes.flatMap((clothingType) => ALL_CARD_COLORS.map((color) => ({ clothing_type: clothingType, color }))),
        theme,
    );
}
