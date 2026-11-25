import { expect, test, type Browser, type Page } from '@playwright/test';
import path from 'node:path';

/**
 * The whole table has to be on one screen: on a laptop, a variant game's two
 * rows of centre cards used to push your piles below the fold. For each common
 * desktop size this starts a variant game and checks the board, piles and all,
 * fits without scrolling. A short screen also gets a pile opened and a card
 * lifted, since a lifted card is drawn raised.
 */

const VIEWPORTS = [
    { width: 1280, height: 680 },
    { width: 1366, height: 657 },
    { width: 1536, height: 730 },
    { width: 1440, height: 900 },
    { width: 1920, height: 960 },
    { width: 1024, height: 680 },
];

test('a variant board fits on one desktop screen', async ({ browser }, testInfo) => {
    const colorScheme = testInfo.project.use.colorScheme ?? 'light';
    const host = await openPlayer(browser, colorScheme);
    const guest = await openPlayer(browser, colorScheme);

    await playAsGuest(host, 'Ada');
    await host.getByLabel('Variant mode (extra set)').check();
    await host.getByRole('button', { name: 'Create Game' }).click();
    await expect(host.getByRole('heading', { name: 'Game Lobby' })).toBeVisible();
    const code = (await host.locator('[data-room-code]').innerText()).match(/[A-Z0-9]{6}/)![0];

    await playAsGuest(guest, 'Ben');
    await guest.getByPlaceholder('6-letter code').fill(code);
    await guest.getByRole('button', { name: 'Join Game' }).click();
    await guest.getByRole('button', { name: 'Ready Up' }).click();
    await host.getByRole('button', { name: 'Ready Up' }).click();
    await host.getByRole('button', { name: 'Start Game' }).click();
    await expect(host.getByText(/Your piles · \d\/6/)).toBeVisible({ timeout: 30_000 });
    await expect(host.locator('[data-zone="centre"] button[data-card-id]')).toHaveCount(8);

    for (const viewport of VIEWPORTS) {
        await host.setViewportSize(viewport);
        await host.waitForTimeout(200);
        await expectBoardFits(host, `${viewport.width}x${viewport.height}`);
        await host.screenshot({
            path: path.join('tests/e2e/screenshots', String(colorScheme), `variant-board-${viewport.width}x${viewport.height}.png`),
        });
    }

    // Lifting a card on the shortest common screen still keeps everything in view.
    await host.setViewportSize(VIEWPORTS[0]);
    await host.getByRole('button', { name: /^Pile 1\b/ }).click();
    await host.locator('[data-zone="hand"] button[data-card-id]').first().click();
    await host.waitForTimeout(200);
    await expectBoardFits(host, 'lifted card');
});

/** Phones, by the area the browser leaves for the page once its own bars are drawn. */
const PHONES = [
    { width: 375, height: 553 },
    { width: 360, height: 640 },
    { width: 390, height: 664 },
    { width: 412, height: 780 },
];

test('a variant board fits on one phone screen', async ({ browser }, testInfo) => {
    const colorScheme = testInfo.project.use.colorScheme ?? 'light';
    const host = await openPlayer(browser, colorScheme);
    const guest = await openPhone(browser, colorScheme);

    await playAsGuest(host, 'Ada');
    await host.getByLabel('Variant mode (extra set)').check();
    await host.getByRole('button', { name: 'Create Game' }).click();
    await expect(host.getByRole('heading', { name: 'Game Lobby' })).toBeVisible();
    const code = (await host.locator('[data-room-code]').innerText()).match(/[A-Z0-9]{6}/)![0];

    await playAsGuest(guest, 'Ben');
    await guest.getByPlaceholder('6-letter code').fill(code);
    await guest.getByRole('button', { name: 'Join Game' }).click();
    await guest.getByRole('button', { name: 'Ready Up' }).click();
    await host.getByRole('button', { name: 'Ready Up' }).click();
    await host.getByRole('button', { name: 'Start Game' }).click();
    await expect(guest.getByText(/Your piles · \d\/6/)).toBeVisible({ timeout: 30_000 });
    await expect(guest.locator('[data-zone="centre"] button[data-card-id]')).toHaveCount(8);

    for (const viewport of PHONES) {
        await guest.setViewportSize(viewport);
        await guest.waitForTimeout(200);
        await guest.screenshot({
            path: path.join('tests/e2e/screenshots', String(colorScheme), `variant-board-phone-${viewport.width}x${viewport.height}.png`),
        });
        await expectBoardFits(guest, `phone ${viewport.width}x${viewport.height}`);
    }

    await guest.setViewportSize(PHONES[0]);
    await guest.getByRole('button', { name: /^Pile 1\b/ }).click();
    await guest.locator('[data-zone="hand"] button[data-card-id]').first().click();
    await guest.waitForTimeout(200);
    await expectBoardFits(guest, 'phone, lifted card');
});

async function expectBoardFits(page: Page, label: string) {
    const layout = await page.evaluate(() => {
        const main = document.querySelector('[data-zone="centre"]')!.closest('main')!;
        const piles = [...document.querySelectorAll('[data-pile-id]')].map((tile) => tile.getBoundingClientRect().bottom);

        return {
            mainOverflow: main.scrollHeight - main.clientHeight,
            pageOverflow: document.documentElement.scrollHeight - window.innerHeight,
            pilesBottom: Math.max(...piles),
            viewportHeight: window.innerHeight,
        };
    });

    expect(layout.mainOverflow, `${label}: the board scrolls`).toBeLessThanOrEqual(0);
    expect(layout.pageOverflow, `${label}: the page scrolls`).toBeLessThanOrEqual(0);
    expect(layout.pilesBottom, `${label}: your piles are below the fold`).toBeLessThanOrEqual(layout.viewportHeight);
}

async function openPlayer(browser: Browser, colorScheme: 'light' | 'dark' | 'no-preference' | null): Promise<Page> {
    const context = await browser.newContext({ viewport: { width: 1440, height: 900 }, colorScheme });

    return context.newPage();
}

async function openPhone(browser: Browser, colorScheme: 'light' | 'dark' | 'no-preference' | null): Promise<Page> {
    const context = await browser.newContext({ viewport: PHONES[0], deviceScaleFactor: 2, isMobile: true, hasTouch: true, colorScheme });

    return context.newPage();
}

async function playAsGuest(page: Page, name: string) {
    await page.goto('/');
    await page.getByPlaceholder('Your name').fill(name);
    await page.getByRole('button', { name: 'Play' }).click();
    await page.waitForURL('**/lobby');
}
