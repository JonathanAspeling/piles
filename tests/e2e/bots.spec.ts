import { expect, test } from '@playwright/test';
import path from 'node:path';

/**
 * Playing against bots: the host fills the table with two, starts on their
 * own, and the bots really play (the centre changes without anyone touching
 * it). Afterwards the practice game is listed in the lobby but not counted.
 */
test('a game against bots', async ({ browser }, testInfo) => {
    const colorScheme = testInfo.project.use.colorScheme ?? 'light';
    const screenshotDir = path.join('tests/e2e/screenshots', String(colorScheme));
    const page = await (await browser.newContext({ viewport: { width: 1440, height: 900 }, colorScheme })).newPage();

    await page.goto('/');
    await page.getByPlaceholder('Your name').fill('Ada');
    await page.getByRole('button', { name: 'Play' }).click();
    await page.waitForURL('**/lobby');
    await page.getByRole('button', { name: 'Create Game' }).click();
    await expect(page.getByRole('heading', { name: 'Game Lobby' })).toBeVisible();

    // Two bots sit down, ready; one is taken out and another added.
    await page.getByRole('button', { name: 'Add a bot' }).click();
    await page.getByRole('button', { name: 'Add a bot' }).click();
    await page.getByRole('button', { name: 'Add a bot' }).click();
    await expect(page.locator('[data-lobby-player]')).toHaveCount(4);
    await page.getByRole('button', { name: 'Remove Widget' }).click();
    await expect(page.locator('[data-lobby-player]')).toHaveCount(3);
    await expect(page.locator('[data-lobby-player="Sprocket"]')).toContainText('Bot');
    await expect(page.locator('[data-lobby-player="Sprocket"]')).toContainText('Ready');
    await page.screenshot({ path: path.join(screenshotDir, 'bots-lobby-desktop.png'), animations: 'disabled' });

    await page.getByRole('button', { name: 'Ready Up' }).click();
    await page.getByRole('button', { name: 'Start Game' }).click();
    await expect(page.getByText(/Your piles · \d\/6/)).toBeVisible({ timeout: 30_000 });

    // Bots are never shown as disconnected.
    await expect(page.locator('[data-opponent="Sprocket"]:visible [title="Bot"]')).toBeVisible();

    // Nobody touches the centre, yet it changes: the bots are playing.
    const centreIds = () =>
        page
            .locator('[data-zone="centre"] button[data-card-id]')
            .evaluateAll((cards) => cards.map((card) => card.getAttribute('data-card-id')).join(','));
    const before = await centreIds();
    await expect.poll(centreIds, { timeout: 30_000 }).not.toBe(before);
    await page.screenshot({ path: path.join(screenshotDir, 'bots-playing-desktop.png'), animations: 'disabled' });

    await page.getByRole('button', { name: 'Forfeit game' }).click();
    await page.getByRole('button', { name: 'Tap again to forfeit' }).click();
    await expect(page.getByText('No winner this time.')).toBeVisible();
    // Bots don't decide about another round; they come along.
    await expect(page.getByText('The bots come along for another round.')).toBeVisible();
    await expect(page.getByText('is deciding…')).toHaveCount(0);

    // The practice game is listed, tagged, and doesn't count.
    await page.goto('/lobby');
    const history = page.locator('[data-game-history]');
    await expect(history.locator('[data-history-game]').first()).toContainText('Bots');
    await expect(history.getByText("Games with bots don't count")).toBeVisible();
    await expect(history.locator('dt', { hasText: 'Played' }).locator('xpath=..')).toContainText('0');
});
