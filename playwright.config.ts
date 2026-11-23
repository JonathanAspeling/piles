import { defineConfig } from '@playwright/test';

/**
 * End-to-end walkthrough of a full game against the local compose stack.
 * Run it with `npm run e2e` (see scripts/e2e.sh), which starts the stack and a
 * containerised browser, then points this config at both.
 */
export default defineConfig({
    testDir: 'tests/e2e',
    timeout: 180_000,
    expect: { timeout: 15_000 },
    fullyParallel: false,
    workers: 1,
    reporter: [['list']],
    use: {
        baseURL: process.env.E2E_BASE_URL ?? 'http://localhost:8000',
        trace: 'retain-on-failure',
        ...(process.env.PW_WS_ENDPOINT ? { connectOptions: { wsEndpoint: process.env.PW_WS_ENDPOINT } } : {}),
    },
    projects: [
        { name: 'light', use: { colorScheme: 'light' } },
        { name: 'dark', use: { colorScheme: 'dark' } },
    ],
});
