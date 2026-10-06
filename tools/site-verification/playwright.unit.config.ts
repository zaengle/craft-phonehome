import { defineConfig } from '@playwright/test';

/**
 * The runner's own unit tests: pure functions in src/, run without a site, a browser or a bundle.
 *
 * Separate from playwright.config.ts on purpose. That file freezes a manifest at load and reports
 * every test it runs as a check against the site; these tests are about the runner, not the site,
 * and must never appear in a verification result.
 *
 *   npx playwright test -c playwright.unit.config.ts
 */
export default defineConfig({
    testDir: './tests/unit',
    retries: 0,
    workers: 1,
    reporter: [['list']],
});
