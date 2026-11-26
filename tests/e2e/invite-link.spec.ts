import { expect, test } from '@playwright/test';

/**
 * Inviting a friend by link: the host copies it from the room, and a friend
 * who has never been here opens it, picks a name and lands in that room.
 */
test('a friend joins from an invite link', async ({ browser }, testInfo) => {
    const colorScheme = testInfo.project.use.colorScheme ?? 'light';
    const hostContext = await browser.newContext({ viewport: { width: 1440, height: 900 }, colorScheme });
    await hostContext.grantPermissions(['clipboard-read', 'clipboard-write']);
    const host = await hostContext.newPage();

    await host.goto('/');
    await host.getByPlaceholder('Your name').fill('Ada');
    await host.getByRole('button', { name: 'Play' }).click();
    await host.waitForURL('**/lobby');
    await host.getByRole('button', { name: 'Create Game' }).click();
    await expect(host.getByRole('heading', { name: 'Game Lobby' })).toBeVisible();
    const code = (await host.locator('[data-room-code]').innerText()).match(/[A-Z0-9]{6}/)![0];

    await host.getByRole('button', { name: 'Copy invite link' }).click();
    await expect(host.getByRole('button', { name: 'Link copied' })).toBeVisible();
    const inviteUrl = await host.evaluate(() => navigator.clipboard.readText());
    expect(new URL(inviteUrl).pathname).toBe(`/join/${code}`);

    // The friend is a stranger to the site: no account, no session.
    const friend = await (await browser.newContext({ viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true, colorScheme })).newPage();
    await friend.goto(new URL(inviteUrl).pathname);
    await expect(friend.getByText(`You've been invited to game ${code}`)).toBeVisible();
    await friend.getByPlaceholder('Your name').fill('Cheron');
    await friend.getByRole('button', { name: 'Join game' }).click();

    await expect(friend.getByRole('heading', { name: 'Game Lobby' })).toBeVisible();
    await expect(friend.locator('[data-room-code]')).toContainText(code);
    await expect(host.getByText('Cheron')).toBeVisible();

    // An invite to a game that's gone says so in the lobby.
    await friend.goto('/join/ZZZZZZ');
    await expect(friend.getByText("That invite link doesn't match a game.")).toBeVisible();
});
