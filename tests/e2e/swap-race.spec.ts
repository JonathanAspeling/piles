import { expect, request, test, type APIRequestContext, type APIResponse, type Browser, type Page } from '@playwright/test';

/**
 * A full 10-player table racing for the centre, over plain HTTP so every
 * request really is in flight at once. Each round every player picks up a
 * card, then all of them swap at the same instant, either all on one centre
 * pile ("pile-on") or spread across the centre ("scatter").
 *
 * After every round the server must have stayed consistent: each response a
 * clean 200 or 409, exactly one winner per contested pile, versions advanced
 * by the number of winners, and every card still in play exactly once.
 *
 * The second test plays the same race through ten real browsers, and checks
 * every player's screen ends up showing the centre the server holds.
 */

const BASE_URL = process.env.E2E_BASE_URL ?? 'http://localhost:8000';
const PLAYER_COUNT = 10;
const ROUNDS_PER_MODE = 8;

interface CardJson {
    id: number;
    clothing_type: number;
    color: number;
}

interface CentrePileJson {
    id: number;
    pile_index: number;
    version: number;
    top_card: CardJson | null;
}

interface PlayerPileJson {
    id: number;
    is_completed: boolean;
    cards: CardJson[];
}

interface BoardJson {
    center_piles: CentrePileJson[];
    my_piles: PlayerPileJson[];
}

interface Player {
    name: string;
    api: APIRequestContext;
}

interface SwapOutcome {
    player: string;
    centrePileId: number;
    status: number;
    body: string;
}

test.describe.configure({ mode: 'serial' });

test('10 players swapping at the same time keep the board consistent', async ({}, testInfo) => {
    test.skip(testInfo.project.name !== 'light', 'Server-side only: one colour scheme is enough.');
    test.setTimeout(240_000);

    const players: Player[] = [];
    for (let seat = 0; seat < PLAYER_COUNT; seat++) {
        players.push(await signInAsGuest(`Racer ${seat + 1}`));
    }
    const [host, ...guests] = players;

    // Table: the host creates, everyone joins, readies up, and the host starts.
    const created = await send(host, 'post', '/games', {});
    expect(created.status(), await created.text()).toBe(200);
    const { game } = (await created.json()) as { game: { id: number; code: string } };

    for (const guest of guests) {
        const joined = await send(guest, 'post', '/games/join', { code: game.code });
        expect(joined.status(), `${guest.name} joining: ${await joined.text()}`).toBe(200);
    }
    for (const player of players) {
        expect((await send(player, 'post', `/games/${game.id}/ready`, {})).status()).toBeLessThan(300);
    }
    expect((await send(host, 'post', `/games/${game.id}/start`, {})).status()).toBeLessThan(300);
    for (const player of players) {
        expect((await send(player, 'post', `/games/${game.id}/client-ready`, {})).status()).toBeLessThan(300);
    }
    await expect
        .poll(async () => ((await (await send(host, 'get', `/games/${game.id}/status`)).json()) as { status: string }).status, {
            timeout: 20_000,
        })
        .toBe('playing');

    const deck = await allCardIds(players, game.id);
    expect(deck.duplicates, 'no card dealt twice').toEqual([]);

    const summary: string[] = [];
    let unexpected: SwapOutcome[] = [];

    for (const mode of ['pile-on', 'scatter'] as const) {
        for (let round = 1; round <= ROUNDS_PER_MODE; round++) {
            const boards = await Promise.all(players.map((player) => board(player, game.id)));
            const centre = boards[0].center_piles;

            // Everyone lifts a card from an unfinished pile of theirs.
            const racers: { player: Player; centrePile: CentrePileJson }[] = [];
            for (const [index, player] of players.entries()) {
                const pile = boards[index].my_piles.find((candidate) => !candidate.is_completed);
                if (!pile) {
                    continue;
                }
                const card = pile.cards[round % pile.cards.length];
                expect((await send(player, 'post', `/games/${game.id}/pickup`, { card_id: card.id })).status()).toBe(204);

                const target = mode === 'pile-on' ? centre[round % centre.length] : centre[Math.floor(Math.random() * centre.length)];
                racers.push({ player, centrePile: target });
            }

            // Then all swap at once, each against the version they last saw.
            const outcomes: SwapOutcome[] = await Promise.all(
                racers.map(async ({ player, centrePile }) => {
                    const response = await send(player, 'post', `/games/${game.id}/swap`, {
                        center_pile_id: centrePile.id,
                        center_card_id: centrePile.top_card!.id,
                        expected_version: centrePile.version,
                    });

                    return {
                        player: player.name,
                        centrePileId: centrePile.id,
                        status: response.status(),
                        body: (await response.text()).slice(0, 300),
                    };
                }),
            );

            // Put down anything still held, so the next round starts clean.
            await Promise.all(players.map((player) => send(player, 'post', `/games/${game.id}/release`, {})));

            const after = await board(host, game.id);
            const contested = [...new Set(outcomes.map((outcome) => outcome.centrePileId))];
            const winsPerPile = contested.map(
                (pileId) => outcomes.filter((outcome) => outcome.centrePileId === pileId && outcome.status === 200).length,
            );
            const versionSteps = contested.map(
                (pileId) => after.center_piles.find((pile) => pile.id === pileId)!.version - centre.find((pile) => pile.id === pileId)!.version,
            );
            const cards = await allCardIds(players, game.id);
            const roundUnexpected = outcomes.filter((outcome) => outcome.status !== 200 && outcome.status !== 409);
            unexpected = [...unexpected, ...roundUnexpected];

            summary.push(
                `${mode.padEnd(7)} #${round}: ${outcomes.length} swaps on ${contested.length} pile(s) → ` +
                    `200×${outcomes.filter((o) => o.status === 200).length} 409×${outcomes.filter((o) => o.status === 409).length} ` +
                    `other×${roundUnexpected.length} | wins/pile ${winsPerPile.join(',')} | version steps ${versionSteps.join(',')} | ` +
                    `cards ${cards.ids.length}/${deck.ids.length}${cards.duplicates.length ? ` DUPES ${cards.duplicates.join(',')}` : ''}`,
            );

            expect.soft(roundUnexpected, `${mode} #${round}: only 200s and 409s`).toEqual([]);
            expect
                .soft(
                    winsPerPile.every((wins) => wins === 1),
                    `${mode} #${round}: exactly one winner per contested pile (${winsPerPile})`,
                )
                .toBe(true);
            expect.soft(versionSteps, `${mode} #${round}: versions advance by the winners`).toEqual(winsPerPile);
            expect.soft(cards.duplicates, `${mode} #${round}: no card in two places`).toEqual([]);
            expect.soft([...cards.ids].sort(), `${mode} #${round}: every card still in play`).toEqual([...deck.ids].sort());
        }
    }

    console.log(['', 'Swap race summary', ...summary].join('\n'));
    if (unexpected.length) {
        console.log(['Unexpected responses:', ...unexpected.map((o) => `  ${o.player} → ${o.status} ${o.body}`)].join('\n'));
    }

    await Promise.all(players.map((player) => player.api.dispose()));
});

test('after simultaneous swaps, every player sees the real centre', async ({ browser }, testInfo) => {
    test.skip(testInfo.project.name !== 'light', 'One colour scheme is enough.');
    test.setTimeout(300_000);

    const pages: Page[] = [];
    for (let seat = 0; seat < PLAYER_COUNT; seat++) {
        pages.push(await openBrowserPlayer(browser, `Screen ${seat + 1}`));
    }
    const players = pages.map((page, seat) => ({ name: `Screen ${seat + 1}`, api: page.request }));
    const [host, ...guests] = players;

    const created = await send(host, 'post', '/games', {});
    const { game } = (await created.json()) as { game: { id: number; code: string } };
    for (const guest of guests) {
        expect((await send(guest, 'post', '/games/join', { code: game.code })).status()).toBe(200);
    }
    for (const player of players) {
        await send(player, 'post', `/games/${game.id}/ready`, {});
    }
    // Everyone opens the game page; the page itself reports client-ready.
    await Promise.all(pages.map((page) => page.goto(`/games/${game.id}`)));
    await Promise.all(pages.map((page) => expect(page.getByRole('heading', { name: 'Game Lobby' })).toBeVisible()));
    // No waiting for the pages to subscribe: they load moments before the
    // start, so some miss its broadcasts and must catch up from the server.
    expect((await send(host, 'post', `/games/${game.id}/start`, {})).status()).toBeLessThan(300);
    const onBoard = await Promise.all(
        pages.map((page) =>
            expect(page.getByText(/Your piles · \d\/6/))
                .toBeVisible({ timeout: 30_000 })
                .then(() => true)
                .catch(() => false),
        ),
    );
    if (onBoard.includes(false)) {
        const status = await (await send(host, 'get', `/games/${game.id}/status`)).text();
        const states = await Promise.all(
            pages.map(
                async (page, seat) =>
                    `screen ${seat + 1}: ${onBoard[seat] ? 'board' : (await page.locator('main').innerText()).replace(/\s+/g, ' ').slice(0, 120)}`,
            ),
        );
        throw new Error(`Not every screen reached the board. Server: ${status}\n${states.join('\n')}`);
    }

    // A full table: nine opponents scroll in their own column, and your piles stay on screen.
    await pages[0].screenshot({ path: 'tests/e2e/screenshots/ten-player-board-desktop.png' });
    await expect(pages[0].getByRole('button', { name: /^Pile 6\b/ })).toBeInViewport();

    const report: string[] = [];
    let divergentTotal = 0;

    for (const mode of ['pile-on', 'scatter'] as const) {
        for (let round = 1; round <= 4; round++) {
            // Everyone opens their first pile and lifts a card.
            await Promise.all(
                pages.map(async (page, seat) => {
                    try {
                        await page.getByRole('button', { name: /^Pile 1\b/ }).click({ timeout: 10_000 });
                        const picked = page.waitForResponse((response) => response.url().endsWith('/pickup'), { timeout: 10_000 });
                        await page
                            .locator('[data-zone="hand"] button[data-card-id]')
                            .nth(round % 4)
                            .click({ timeout: 10_000 });
                        await picked;
                    } catch (error) {
                        await page.screenshot({ path: `test-results/stuck-screen-${seat + 1}.png` });
                        throw new Error(
                            `${mode} #${round}, screen ${seat + 1}, lifting a card: ${(error as Error).message.split('\n').slice(0, 12).join(' | ')}`,
                        );
                    }
                }),
            );

            // Then all tap the centre at once.
            const target = round % 4;
            await Promise.all(
                pages.map(async (page, seat) => {
                    const index = mode === 'pile-on' ? target : Math.floor(Math.random() * 4);
                    try {
                        const swapped = page.waitForResponse((response) => response.url().endsWith('/swap'), { timeout: 10_000 });
                        await page.locator(`[data-zone="centre"] [data-centre-index="${index + 1}"]`).click({ timeout: 10_000 });
                        await swapped;
                    } catch (error) {
                        throw new Error(
                            `${mode} #${round}, screen ${seat + 1}, swapping: ${(error as Error).message.split('\n').slice(0, 12).join(' | ')}`,
                        );
                    }
                }),
            );

            // Give broadcasts, whispers and resyncs time to settle, then compare every screen to the server.
            await pages[0].waitForTimeout(2_500);
            const truth = await board(host, game.id);
            const truthByIndex = truth.center_piles.sort((a, b) => a.pile_index - b.pile_index).map((pile) => pile.top_card?.id ?? null);
            const screens = await Promise.all(pages.map(centreOnScreen));
            const divergent = screens.filter((screen) => screen.join() !== truthByIndex.join()).length;
            divergentTotal += divergent;
            report.push(`${mode.padEnd(7)} #${round}: ${divergent}/${PLAYER_COUNT} screens show a different centre from the server`);

            // Close the pile (putting back anything still lifted) for a clean next round.
            await Promise.all(
                pages.map(async (page) => {
                    const close = page.getByRole('button', { name: 'Close pile' });
                    if (await close.isVisible()) {
                        // Only a player still holding a card (a loser) sends a release.
                        const released = page
                            .waitForResponse((response) => response.url().endsWith('/release'), { timeout: 3_000 })
                            .catch(() => null);
                        await close.click({ timeout: 10_000 });
                        await released;
                    }
                }),
            );
        }
    }

    console.log(['', 'Screen convergence', ...report].join('\n'));
    expect(divergentTotal, 'every screen shows the centre the server holds').toBe(0);

    await Promise.all(pages.map((page) => page.context().close()));
});

async function openBrowserPlayer(browser: Browser, name: string): Promise<Page> {
    const context = await browser.newContext({ viewport: { width: 1280, height: 800 } });
    const page = await context.newPage();
    await page.goto('/');
    await page.getByPlaceholder('Your name').fill(name);
    await page.getByRole('button', { name: 'Play' }).click();
    await page.waitForURL('**/lobby');

    return page;
}

/** The card ids this screen shows in the centre, in centre order. */
async function centreOnScreen(page: Page): Promise<(number | null)[]> {
    return page
        .locator('[data-zone="centre"] [data-centre-index]')
        .evaluateAll((slots) =>
            slots
                .sort((a, b) => Number(a.getAttribute('data-centre-index')) - Number(b.getAttribute('data-centre-index')))
                .map((slot) => (slot.getAttribute('data-card-id') ? Number(slot.getAttribute('data-card-id')) : null)),
        );
}

async function signInAsGuest(name: string): Promise<Player> {
    const api = await request.newContext({ baseURL: BASE_URL });
    await api.get('/');
    const player = { name, api };
    const response = await api.post('/play-as-guest', { form: { name }, headers: await xsrfHeader(player) });
    expect(response.ok(), `${name} signing in`).toBe(true);

    return player;
}

async function xsrfHeader(player: Pick<Player, 'api'>): Promise<Record<string, string>> {
    const { cookies } = await player.api.storageState();
    const token = cookies.find((cookie) => cookie.name === 'XSRF-TOKEN')?.value ?? '';

    return { 'X-XSRF-TOKEN': decodeURIComponent(token), Accept: 'application/json' };
}

async function send(player: Player, method: 'get' | 'post', path: string, data?: object): Promise<APIResponse> {
    const headers = await xsrfHeader(player);

    return method === 'get' ? player.api.get(path, { headers }) : player.api.post(path, { headers, data: data ?? {} });
}

async function board(player: Player, gameId: number): Promise<BoardJson> {
    const response = await send(player, 'get', `/games/${gameId}/state`);
    expect(response.status(), `${player.name} reading the board`).toBe(200);

    return (await response.json()) as BoardJson;
}

/** Every card in play, as seen from all hands plus the centre, with any that appear twice. */
async function allCardIds(players: Player[], gameId: number): Promise<{ ids: number[]; duplicates: number[] }> {
    const boards = await Promise.all(players.map((player) => board(player, gameId)));
    const ids = [
        ...boards[0].center_piles.flatMap((pile) => (pile.top_card ? [pile.top_card.id] : [])),
        ...boards.flatMap((playerBoard) => playerBoard.my_piles.flatMap((pile) => pile.cards.map((card) => card.id))),
    ];
    const seen = new Set<number>();
    const duplicates = ids.filter((id) => (seen.has(id) ? true : (seen.add(id), false)));

    return { ids, duplicates };
}
