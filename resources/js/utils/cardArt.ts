import cardArtSchemes from '../data/cardArtSchemes.json';
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

// The white face standard cards sit on; a light border keeps it off a white page.
const STANDARD_FACE_CLASSES = 'bg-white border-slate-200 text-slate-700';

/**
 * Background, border and text colour for a card face.
 *
 * - classic: the CardColor background behind a white silhouette (hard variant has no effect).
 * - standard: a white face, or the CardColor background when the hard colour variant is on.
 *
 * Text colour only shows when an image is missing and CardArt falls back to the label.
 */
export function cardFaceClasses(color: CardColor, theme: CardTheme, hardVariant: boolean): string {
    if (theme === CardTheme.Classic || hardVariant) {
        return `${CARD_COLOR_CLASSES[color]} text-white`;
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
 * Classic, and standard with the hard colour variant, use the CardColor colour
 * — the same colour as that card's face. Plain standard has no fixed colour per
 * CardColor, so it uses the item's own main colour from its colourway.
 */
export function cardSwatch(type: ClothingType, color: CardColor, theme: CardTheme, hardVariant: boolean): CardSwatch {
    const scheme = getCardArtScheme(type, color);
    if (theme === CardTheme.Classic || hardVariant || scheme === undefined) {
        return { class: CARD_COLOR_CLASSES[color] };
    }
    return {
        class: 'border-white/70',
        style: { backgroundColor: scheme.main },
    };
}
