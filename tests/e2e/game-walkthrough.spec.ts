import { expect, test, type Browser, type BrowserContextOptions, type Locator, type Page } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import path from 'node:path';

/**
 * Plays one two-player game from the home page to the winner screen and
 * screenshots every state along the way, for design reviews.
 *
 * The host plays on a desktop viewport and the guest on a phone, so one run
 * covers both layouts. Each Playwright project (light / dark) repeats the
 * game in its colour scheme. Screenshots land in tests/e2e/screenshots/<theme>/.
 */

const DESKTOP: BrowserContextOptions = { viewport: { width: 1440, height: 900 }, deviceScaleFactor: 2 };
const PHONE: BrowserContextOptions = {
    viewport: { width: 390, height: 844 },
    deviceScaleFactor: 3,
    isMobile: true,
    hasTouch: true,
    userAgent:
        'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1',
};

const COMPOSE = ['compose', '-p', 'piles-e2e', '--env-file', 'tests/e2e/e2e.env'];

test('a full game, start to finish', async ({ browser }, testInfo) => {
    const colorScheme = testInfo.project.use.colorScheme ?? 'light';
    const screenshotDir = path.join('tests/e2e/screenshots', String(colorScheme));
    let shotNumber = 0;

    async function shot(page: Page, name: string, options: { fullPage?: boolean } = {}) {
        shotNumber += 1;
        const file = `${String(shotNumber).padStart(2, '0')}-${name}.png`;
        await page.screenshot({ path: path.join(screenshotDir, file), fullPage: options.fullPage ?? true, animations: 'disabled' });
    }

    const host = await openPlayer(browser, { ...DESKTOP, colorScheme });
    const guest = await openPlayer(browser, { ...PHONE, colorScheme });

    // Arriving: the home page, then playing under just a name.
    await host.goto('/');
    await shot(host, 'home-desktop');
    await guest.goto('/');
    await shot(guest, 'home-phone');

    await playAsGuest(host, 'Ada');
    await expect(host.getByRole('heading', { name: 'Create a Game' })).toBeVisible();
    await shot(host, 'lobby-empty-desktop');

    await host.getByRole('button', { name: 'How to play' }).click();
    await shot(host, 'how-to-play-desktop', { fullPage: false });
    await host.getByRole('button', { name: 'Close' }).first().click();

    // The host opens a table.
    await host.getByRole('button', { name: 'Create Game' }).click();
    await expect(host.getByRole('heading', { name: 'Game Lobby' })).toBeVisible();
    const code = await readGameCode(host);
    await shot(host, 'game-lobby-alone-desktop');

    // The guest finds it in the open games list and joins by code.
    await playAsGuest(guest, 'Ben');
    await expect(guest.getByText(code)).toBeVisible();
    await shot(guest, 'lobby-open-games-phone');
    await guest.getByPlaceholder('6-letter code').fill(code);
    await guest.getByRole('button', { name: 'Join Game' }).click();
    await expect(guest.getByRole('heading', { name: 'Game Lobby' })).toBeVisible();

    await expect(host.getByText('Ben')).toBeVisible();
    await shot(host, 'game-lobby-two-players-desktop');
    await shot(guest, 'game-lobby-phone');

    // Both ready up and the host starts.
    await guest.getByRole('button', { name: 'Ready Up' }).click();
    await host.getByRole('button', { name: 'Ready Up' }).click();
    const start = host.getByRole('button', { name: 'Start Game' });
    await expect(start).toBeEnabled();
    await shot(host, 'game-lobby-all-ready-desktop');

    // The board only mounts at GO, so any card art fetched before then is the
    // preload warming the dealt cards during the countdown.
    const cardArtRequests: string[] = [];
    host.on('request', (request) => {
        if (/\/assets\/.+\.(webp|png)$/.test(request.url())) {
            cardArtRequests.push(request.url());
        }
    });
    await start.click();

    await expect(host.getByText('Game starting in…')).toBeVisible();
    await expect.poll(() => cardArtRequests.length, { timeout: 2_500 }).toBeGreaterThanOrEqual(8);
    await expect(host.getByText(/Your piles · \d\/6/)).toBeHidden();
    await shot(host, 'countdown-desktop', { fullPage: false });
    await shot(guest, 'countdown-phone', { fullPage: false });

    await waitForBoard(host);
    await waitForBoard(guest);
    await shot(host, 'board-start-desktop');
    await shot(guest, 'board-start-phone', { fullPage: false });

    // A real move: open a pile, pick a card, swap it with the centre.
    await host.getByRole('button', { name: /^Pile 1\b/ }).click();
    await shot(host, 'pile-open-desktop');
    await visiblePileCards(host).first().click();
    await shot(host, 'card-picked-up-desktop');
    await expectSwapFlightMatchesCards(host, () => centerPile(host, 1).click());
    await expect(host.getByText('Tap a card to pick it up')).toBeVisible();
    await shot(host, 'after-swap-desktop');

    await guest.getByRole('button', { name: /^Pile 3\b/ }).click();
    await shot(guest, 'pile-sheet-phone', { fullPage: false });
    await visiblePileCards(guest).nth(1).click();
    await shot(guest, 'card-picked-up-phone', { fullPage: false });
    // Closing puts the lifted card back. Wait for that write to land: the
    // endgame re-deal below shares the SQLite database and must not race it.
    const released = guest.waitForResponse((response) => response.url().endsWith('/release'));
    await guest.getByRole('button', { name: 'Close pile' }).click();
    await released;

    await guest.getByRole('button', { name: 'Rearrange piles' }).click();
    await guest.getByRole('button', { name: /^Pile 1\b/ }).click();
    await shot(guest, 'rearrange-phone', { fullPage: false });
    await guest.getByRole('button', { name: 'Done rearranging' }).click();

    // Fast-forward to the endgame, then reload so both clients pick it up.
    rigEndgame(code);
    await host.reload();
    await guest.reload();
    await waitForBoard(host);
    await waitForBoard(guest);
    await expect(host.getByText('Your piles · 5/6')).toBeVisible();
    await shot(host, 'board-five-done-desktop');
    await shot(guest, 'board-opponent-ahead-phone', { fullPage: false });

    // The guest completes a pile of their own.
    await completePile(guest, 1, 2);
    await expect(guest.getByText('Your piles · 1/6')).toBeVisible();
    await shot(guest, 'pile-completed-phone', { fullPage: false });

    // The host completes the last pile and calls PILES!
    await completePile(host, 6, 1);
    const claim = host.getByRole('button', { name: 'PILES!' });
    await expect(claim).toBeVisible();
    await shot(host, 'ready-to-claim-desktop');
    await claim.click();

    await expect(host.getByRole('heading', { name: 'You won!' })).toBeVisible();
    await expect(guest.getByRole('heading', { name: 'Ada wins' })).toBeVisible();
    await shot(host, 'winner-desktop', { fullPage: false });
    await shot(guest, 'loser-phone', { fullPage: false });

    // Play again: the guest opens the rematch lobby, the host sees them waiting, then follows.
    await guest.getByRole('button', { name: 'Play again' }).click();
    await expect(guest.getByRole('heading', { name: 'Game Lobby' })).toBeVisible();
    await expect(host.getByText('is ready for another round')).toBeVisible();
    await shot(host, 'rematch-waiting-desktop', { fullPage: false });

    await host.getByRole('button', { name: 'Play again' }).click();
    await expect(host.getByRole('heading', { name: 'Game Lobby' })).toBeVisible();
    await expect(guest.getByText('Players · 2/10')).toBeVisible();
    await shot(host, 'rematch-lobby-desktop');
    await shot(guest, 'rematch-lobby-phone');

    await host.context().close();
    await guest.context().close();
});

async function openPlayer(browser: Browser, options: BrowserContextOptions): Promise<Page> {
    const context = await browser.newContext(options);

    return context.newPage();
}

async function playAsGuest(page: Page, name: string) {
    await page.goto('/');
    await page.getByPlaceholder('Your name').fill(name);
    await page.getByRole('button', { name: 'Play' }).click();
    await page.waitForURL('**/lobby');
}

async function readGameCode(page: Page): Promise<string> {
    const text = await page.locator('[data-room-code]').innerText();
    const match = text.match(/[A-Z0-9]{6}/);

    if (!match) {
        throw new Error(`No game code in "${text}"`);
    }

    return match[0];
}

async function waitForBoard(page: Page) {
    await expect(page.getByText(/Your piles · \d\/6|You're ready/)).toBeVisible({ timeout: 30_000 });
}

/** The card buttons of the open pile, in the always-present "Your cards" row. */
function visiblePileCards(page: Page): Locator {
    return page.locator('[data-zone="hand"] button[data-card-id]');
}

function centerPile(page: Page, number: number): Locator {
    return page.locator(`[data-zone="centre"] [data-centre-index="${number}"]`);
}

/** Opens a pile holding three of a kind plus one stray, and swaps the stray for the match in the centre. */
async function completePile(page: Page, pileNumber: number, centerNumber: number) {
    await page.getByRole('button', { name: new RegExp(`^Pile ${pileNumber}\\b`) }).click();

    const cards = visiblePileCards(page);
    await expect(cards).toHaveCount(4);
    const titles = await cards.evaluateAll((buttons) => buttons.map((button) => button.getAttribute('title')));
    const stray = titles.findIndex((title) => titles.filter((other) => other === title).length === 1);

    await cards.nth(stray).click();
    await expectSwapFlightMatchesCards(page, () => centerPile(page, centerNumber).click());
}

interface FlyerSize {
    width: number;
    height: number;
    artWidth: number;
    artHeight: number;
}

interface LandedSlot {
    opacity: string;
    artLoaded: boolean;
}

interface SwapFlightRecord {
    flyers: FlyerSize[];
    landed: LandedSlot[];
    done: boolean;
}

/**
 * Runs a swap and checks its animation, watching the DOM as it happens:
 * - the moment the flying copies appear, each is exactly the size of a centre
 *   card's slot, with its item art visible;
 * - the moment they're removed, the real cards underneath are already fully
 *   shown, art loaded, so the hand-off doesn't blink.
 */
async function expectSwapFlightMatchesCards(page: Page, swap: () => Promise<void>) {
    await page.evaluate(() => {
        const record: SwapFlightRecord = { flyers: [], landed: [], done: false };
        (window as unknown as { __swapFlight: SwapFlightRecord }).__swapFlight = record;
        new MutationObserver((mutations, observer) => {
            for (const mutation of mutations) {
                for (const node of mutation.addedNodes) {
                    if (!(node instanceof HTMLElement)) {
                        continue;
                    }
                    for (const flyer of node.querySelectorAll<HTMLElement>('[data-swap-flyer]')) {
                        const { width, height } = flyer.getBoundingClientRect();
                        const art = flyer.querySelector('img')?.getBoundingClientRect();
                        record.flyers.push({ width, height, artWidth: art?.width ?? 0, artHeight: art?.height ?? 0 });
                    }
                }
                for (const node of mutation.removedNodes) {
                    if (!(node instanceof HTMLElement)) {
                        continue;
                    }
                    for (const flyer of node.querySelectorAll<HTMLElement>('[data-swap-flyer]')) {
                        const slot = document.querySelector<HTMLElement>(`[data-zone] [data-card-id="${flyer.dataset.swapFlyer}"]`);
                        // A swap that completes the pile closes it, so that card has no slot left to show.
                        if (!slot) {
                            continue;
                        }
                        const art = slot.querySelector('img');
                        record.landed.push({
                            opacity: getComputedStyle(slot).opacity,
                            artLoaded: !!art && art.complete && art.naturalWidth > 0,
                        });
                    }
                }
            }
            if (
                mutations.some((mutation) =>
                    [...mutation.removedNodes].some((node) => node instanceof HTMLElement && node.querySelector('[data-swap-flyer]')),
                )
            ) {
                record.done = true;
                observer.disconnect();
            }
        }).observe(document.body, { childList: true, subtree: true });
    });

    const slot = await page.locator('[data-zone="centre"] button[data-card-id]').first().boundingBox();
    await swap();

    const recorded = await page.waitForFunction(() => {
        const record = (window as unknown as { __swapFlight: SwapFlightRecord }).__swapFlight;

        return record.done ? record : null;
    });
    const { flyers, landed } = (await recorded.jsonValue()) as SwapFlightRecord;

    expect(flyers).toHaveLength(2);
    for (const flyer of flyers) {
        expect(flyer.width).toBeCloseTo(slot!.width, 0);
        expect(flyer.height).toBeCloseTo(slot!.height, 0);
        expect(flyer.artWidth * flyer.artHeight, 'the flying card shows its item').toBeGreaterThan(0);
    }
    expect(landed.length, 'at least the card sent to the centre still has a slot').toBeGreaterThan(0);
    for (const slot of landed) {
        expect(slot, 'the real cards are fully shown, art loaded, as the flyers go').toEqual({ opacity: '1', artLoaded: true });
    }
}

function rigEndgame(code: string) {
    execFileSync('docker', [...COMPOSE, 'exec', '-T', '-u', 'www-data', '-e', `GAME_CODE=${code}`, 'app', 'php'], {
        input: readFileSync('tests/e2e/support/rig-game.php'),
        stdio: ['pipe', 'pipe', 'inherit'],
    });
}
