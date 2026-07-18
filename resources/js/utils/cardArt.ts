const modules = import.meta.glob('../../images/cards/*.png', {
    eager: true,
    query: '?inline',
    import: 'default',
}) as Record<string, string>;

const CARD_ART_URLS: Record<number, string> = {};

for (const [path, dataUrl] of Object.entries(modules)) {
    const match = path.match(/(\d+)\.png$/);
    if (match) {
        CARD_ART_URLS[Number(match[1])] = dataUrl;
    }
}

export function getCardArtUrl(type: number): string | undefined {
    return CARD_ART_URLS[type];
}