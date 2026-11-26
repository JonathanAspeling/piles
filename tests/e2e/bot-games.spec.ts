import { expect, test, type Page } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import path from 'node:path';

/**
 * Whole games against bots on the real stack (queue worker, Reverb, SQLite),
 * at 2, 5 and 10 seats, with and without the variant and Similar items.
 *
 * The host sits out: a player who never moves would keep cards that other
 * sets need, so during the countdown their hand is re-dealt into six whole
 * sets, left unmarked (see support/rig-sit-out.php). Their board reads 0/6
 * and they never call PILES!, so a bot has to win it. Each game must reach a
 * winner with no server errors or database lock-ups along the way.
 *
 * Slow (minutes per game), so it runs in the light project only.
 */

const COMPOSE = ['compose', '-p', 'piles-e2e', '--env-file', 'tests/e2e/e2e.env'];

const TABLES = [
    { seats: 2, variant: false, similarItems: false },
    { seats: 2, variant: true, similarItems: true },
    { seats: 5, variant: false, similarItems: false },
    { seats: 5, variant: true, similarItems: false },
    { seats: 10, variant: false, similarItems: false },
    { seats: 10, variant: true, similarItems: true },
];

for (const table of TABLES) {
    const label = `${table.seats} seats${table.variant ? ', variant' : ''}${table.similarItems ? ', similar items' : ''}`;

    test(`bots play a whole game: ${label}`, async ({ browser }, testInfo) => {
        test.skip(testInfo.project.name !== 'light', 'Whole bot games run once, in the light project.');
        test.setTimeout(10 * 60_000);

        const startedAt = new Date();
        const page = await (await browser.newContext({ viewport: { width: 1440, height: 900 }, colorScheme: 'light' })).newPage();

        await page.goto('/');
        await page.getByPlaceholder('Your name').fill('Ada');
        await page.getByRole('button', { name: 'Play' }).click();
        await page.waitForURL('**/lobby');
        if (table.variant) {
            await page.getByLabel('Variant mode (extra set)').check();
        }
        if (table.similarItems) {
            await page.getByLabel('Similar items').check();
        }
        await page.getByRole('button', { name: 'Create Game' }).click();
        await expect(page.getByRole('heading', { name: 'Game Lobby' })).toBeVisible();
        const code = (await page.locator('[data-room-code]').innerText()).match(/[A-Z0-9]{6}/)![0];

        for (let bot = 1; bot < table.seats; bot++) {
            await page.getByRole('button', { name: 'Add a bot' }).click();
            await expect(page.locator('[data-lobby-player]')).toHaveCount(bot + 1);
        }
        await expect(page.getByRole('button', { name: 'Add a bot' })).toHaveCount(table.seats === 10 ? 0 : 1);

        await page.getByRole('button', { name: 'Ready Up' }).click();
        await page.getByRole('button', { name: 'Start Game' }).click();
        await expect(page.getByText('Game starting in…')).toBeVisible();
        rigSitOut(code);

        await expect(page.getByText(/Your piles · \d\/6/)).toBeVisible({ timeout: 30_000 });
        await expect(page.locator('[data-zone="centre"] button[data-card-id]')).toHaveCount(table.variant ? 8 : 4);
        await expect(page.locator('aside [data-opponent]')).toHaveCount(table.seats - 1);

        // The bots finish sets as they go: progress shows on the host's screen.
        await expect.poll(() => completedOpponentPiles(page), { timeout: 4 * 60_000, intervals: [2_000] }).toBeGreaterThan(0);

        // Checked every frame, not with expect's backing-off retries, so the game-over buttons
        // are still in their first second when expectGameOverPanelUsable() looks at them.
        await page.waitForFunction(() => / wins$/.test(document.getElementById('game-over-title')?.textContent ?? ''), null, {
            polling: 'raf',
            timeout: 8 * 60_000,
        });
        const winnerHeading = page.getByRole('heading', { name: / wins$/ });
        const winner = (await winnerHeading.innerText()).replace(/ wins$/, '');
        expect(await page.locator(`aside [data-opponent="${winner}"]`).innerText()).toContain('6');
        await expectGameOverPanelUsable(page, table.seats);
        await page.screenshot({
            path: path.join(
                'tests/e2e/screenshots',
                'light',
                `bot-game-${table.seats}${table.variant ? '-variant' : ''}${table.similarItems ? '-similar' : ''}.png`,
            ),
        });

        const problems = serverProblemsSince(startedAt);
        expect(problems, problems.join('\n')).toEqual([]);

        await page.context().close();
    });
}

/**
 * The game-over panel opens under the pointer of anyone still racing for
 * PILES!, so its buttons ignore clicks for a moment before they work. And its
 * results list must show every seat: a 10-seat list once got squeezed and
 * clipped its last rows instead of letting the panel scroll.
 */
async function expectGameOverPanelUsable(page: Page, seats: number) {
    const panel = page.getByRole('dialog', { name: / wins$/ });
    const leave = panel.getByRole('button', { name: 'Leave room' });

    await expect(leave).toBeDisabled();
    await expect(panel.getByRole('button', { name: 'Play again' })).toBeDisabled();
    await expect(leave).toBeEnabled({ timeout: 3_000 });

    const results = panel.locator('ol');
    await expect(results.locator('li')).toHaveCount(seats);
    const clipped = await results.evaluate((list) => list.scrollHeight - list.clientHeight);
    expect(clipped, 'results rows hidden by the list clipping').toBeLessThanOrEqual(1);

    const lastRow = results.locator('li').last();
    await lastRow.scrollIntoViewIfNeeded();
    await expect(lastRow).toBeInViewport({ ratio: 1 });
}

/** How many piles the opponents have laid down, read off the sidebar. */
async function completedOpponentPiles(page: Page): Promise<number> {
    const counts = await page.locator('aside [data-opponent] .tabular-nums').allInnerTexts();

    return counts.reduce((total, text) => total + Number.parseInt(text, 10), 0);
}

function rigSitOut(code: string) {
    execFileSync('docker', [...COMPOSE, 'exec', '-T', '-u', 'www-data', '-e', `GAME_CODE=${code}`, 'app', 'php'], {
        input: readFileSync('tests/e2e/support/rig-sit-out.php'),
        stdio: ['pipe', 'pipe', 'inherit'],
    });
}

/** Server errors, failed jobs and lock-ups logged by the app or queue since the game began. */
function serverProblemsSince(since: Date): string[] {
    const logs = execFileSync('docker', [...COMPOSE, 'logs', '--since', since.toISOString(), 'app', 'queue'], {
        encoding: 'utf8',
        maxBuffer: 64 * 1024 * 1024,
    });

    return logs
        .split('\n')
        .filter((line) => /database is locked|local\.ERROR|production\.ERROR|FAIL\b| 500 /.test(line))
        .map((line) => line.slice(0, 300));
}
