import { expect, test, type Page, type Response } from '@playwright/test';
import { copyFileSync, existsSync, mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';
import { runConfig } from '../src/config';
import { bundlePaths, readFrozenManifest, type Abort } from '../src/manifest';

const config = runConfig();
const paths = bundlePaths(config.bundleDir);

/**
 * Prefix that marks an outcome as "could not verify" rather than "verified and wrong". The reporter
 * reads it back; the distinction is the whole point of the run, so it is carried explicitly rather
 * than inferred from how a failure happens to be worded.
 */
const INCONCLUSIVE = 'INCONCLUSIVE:';

/** Marks a page that cannot be baselined because it does not render the same way twice. */
const UNSTABLE = 'UNSTABLE:';

interface MaskedRead {
    text: string;
    /** Fraction of the page's visible text the masks removed, 0 to 1. */
    share: number;
}

/**
 * Masks that contain the element the page asserts on.
 *
 * Such a mask blinds every content check on the page while the assertion keeps passing, so the page
 * reports clean no matter what changes inside it -- and one broad selector applied site-wide does
 * that to every page at once while the dashboard still reports full template coverage. The check
 * has to happen in the browser: the plugin can compare two selector strings, but only a rendered
 * document knows that `main` contains `[data-testid="page-heading"]`.
 *
 * Read before anything mutates the page, so it is its own pass rather than folded into the masked
 * read below.
 */
async function findSwallowingMasks(browserPage: Page, selectors: string[], assertSelector: string): Promise<string[]> {
    if (selectors.length === 0) {
        return [];
    }

    return browserPage.evaluate(
        ({ masks, assertion }: { masks: string[]; assertion: string }) => {
            const asserted = document.querySelector(assertion);

            if (asserted === null) {
                return [];
            }

            // `contains` is true for the element itself, which also catches a mask that is simply
            // the assertion by another spelling.
            return masks.filter((selector) => Array.from(document.querySelectorAll(selector)).some((element) => element.contains(asserted)));
        },
        { masks: selectors, assertion: assertSelector },
    );
}

/**
 * Raised as inconclusive rather than failed: the site is not wrong, the page's definition is, and a
 * capture must not record a baseline that cannot detect anything.
 */
function refuseSwallowedPage(path: string, swallowing: string[], assertSelector: string): never {
    throw new Error(
        `${INCONCLUSIVE} mask_swallows_assertion — on ${path}, ${swallowing.join(', ')} contains ${assertSelector}, ` +
            'so every content check on this page would pass whatever changed inside it. Narrow the mask.',
    );
}

/**
 * Masked elements are replaced with a marker rather than removed, so the page's shape is still
 * compared. Deleting their text instead would mean a masked block disappearing entirely looked
 * identical to it simply having nothing to say.
 *
 * The same pass measures how much of the page's text the masks removed, because a mask is coverage
 * deliberately given up and the run is the only thing in a position to say how much.
 */
async function readMaskedText(browserPage: Page, selectors: string[]): Promise<MaskedRead> {
    return browserPage.evaluate((masks: string[]) => {
        const visible = () => (document.body.innerText ?? '').replace(/\s+/g, ' ').trim();
        const before = visible().length;

        for (const selector of masks) {
            for (const element of Array.from(document.querySelectorAll(selector))) {
                element.textContent = '[masked]';
            }
        }

        const text = visible();

        return { text, share: before === 0 ? 0 : Math.max(0, before - text.length) / before };
    }, selectors);
}

/**
 * Written per page rather than once per run because Playwright's workers are separate processes,
 * and a single shared file would record whichever worker finished last.
 */
function recordMasking(pageId: string, read: MaskedRead): void {
    mkdirSync(paths.masking, { recursive: true });
    writeFileSync(join(paths.masking, `${pageId}.json`), `${JSON.stringify({ share: read.share })}\n`);
}

/** Shows where two renderings first diverge, so the varying block is identifiable at a glance. */
function firstDifference(texts: string[]): string {
    const [a, b] = texts;
    let index = 0;

    while (index < a.length && index < b.length && a[index] === b[index]) {
        index++;
    }

    return `…${a.slice(Math.max(0, index - 40), index + 50)}… vs …${b.slice(Math.max(0, index - 40), index + 50)}…`;
}


const EXPECTED_ORIGIN = new URL(config.origin).origin;

/**
 * Navigates to a manifest path and refuses to verify anything that is not on the configured origin.
 *
 * The plugin already rejects a path that points off-site, but that check happens before any request
 * is made and so cannot see a redirect. Without this the runner will happily photograph whatever a
 * redirected page resolves to -- a login screen, a parked domain, another application -- and report
 * it as the page it was asked about.
 *
 * The route guard makes it impossible and the URL check makes it legible. Only top-level document
 * navigation is restricted; sub-resources are left alone, because assets legitimately come from
 * another origin such as a CDN.
 */
async function visit(browserPage: Page, path: string): Promise<Response> {
    let blocked: string | null = null;

    await browserPage.route('**/*', (route) => {
        const request = route.request();
        const isTopLevel = request.isNavigationRequest() && request.frame().parentFrame() === null;

        if (isTopLevel && new URL(request.url()).origin !== EXPECTED_ORIGIN) {
            blocked = request.url();

            return route.abort('blockedbyclient');
        }

        return route.continue();
    });

    const response = await browserPage.goto(path, { waitUntil: 'load' }).catch((error: Error) => {
        if (blocked !== null) {
            throw new Error(`${INCONCLUSIVE} off_origin — ${path} navigated to ${blocked}, outside ${EXPECTED_ORIGIN}.`);
        }

        throw new Error(`${INCONCLUSIVE} unreachable — ${path}: ${error.message}`);
    });

    if (!response) {
        throw new Error(`${INCONCLUSIVE} unreachable — ${path} returned no response.`);
    }

    const landed = new URL(browserPage.url());

    if (landed.origin !== EXPECTED_ORIGIN) {
        throw new Error(`${INCONCLUSIVE} off_origin — ${path} landed on ${landed.origin}, outside ${EXPECTED_ORIGIN}.`);
    }

    // A same-origin redirect is still a different page. Left unchecked, a path that now redirects
    // to the home page compares the home page against that path's baseline -- and if the required
    // selector is a layout element, it passes its assertion too. Trailing slashes are normalised
    // because adding or removing one is a routing detail, not a different page.
    // Decoded before comparing, because the two sides are spelled differently: the browser reports
    // what it sent on the wire and the manifest carries what Craft stores. A path with a non-ASCII
    // character therefore compared `/%C3%BCber-uns` against `/über-uns` and was reported as a
    // redirect to itself -- a page permanently unverifiable for having an accent in its slug.
    const normalise = (value: string) => {
        let decoded = value;

        try {
            decoded = decodeURIComponent(value);
        } catch {
            // A malformed escape sequence is compared as written rather than throwing here.
        }

        return decoded.length > 1 ? decoded.replace(/\/+$/, '') : decoded;
    };

    if (normalise(landed.pathname) !== normalise(new URL(path, config.origin).pathname)) {
        throw new Error(`${INCONCLUSIVE} redirected — ${path} landed on ${landed.pathname}, which is a different page.`);
    }

    return response;
}

if (existsSync(paths.abort)) {
    const abort = JSON.parse(readFileSync(paths.abort, 'utf8')) as Abort;

    test('manifest:gate', () => {
        throw new Error(`${INCONCLUSIVE} ${abort.reason} — ${abort.detail.join(' ')}`);
    });
} else if (!existsSync(paths.manifest)) {
    test('manifest:gate', () => {
        throw new Error(`${INCONCLUSIVE} no_baseline — no frozen manifest at ${paths.manifest}. Capture a baseline before comparing.`);
    });
} else {
    // The frozen manifest drives both sides of the pair. A compare run never adopts the site's
    // current definitions, so a page removed between capture and compare fails rather than
    // disappearing from the suite.
    const manifest = readFrozenManifest(config.bundleDir);

    for (const page of manifest.pages) {
        test(`assert:${page.id}`, async ({ page: browserPage }) => {
            const response = await visit(browserPage, page.path);

            // A non-2xx page is a real failure, not an inability to check. Without this an error
            // page that stays identical between runs would compare clean and read as a pass.
            expect(response.status(), `${page.path} returned ${response.status()}`).toBeLessThan(400);
            await expect(browserPage.locator(page.assert.visible).first()).toBeVisible();
        });

        /**
         * A frozen copy of the page's visible text.
         *
         * Pixel comparison is a poor instrument for content. A deleted sentence is a few hundred
         * pixels and a colour change can be zero at the default per-pixel threshold, so both can
         * sit under any budget loose enough to tolerate antialiasing. Text is compared exactly,
         * which is the right instrument for the thing most likely to go missing in a deploy.
         */
        test(`text:${page.id}`, async ({ page: browserPage }) => {
            // Capture renders the page several times and refuses to record a baseline unless every
            // rendering agrees. A page that varies per request -- a randomised block, a relative
            // timestamp -- produces a baseline that is simply one of its possible outputs, and then
            // reports a change on most runs afterwards. Establishing that here is the difference
            // between finding it now and finding it as an unexplained failure in a month.
            const samples = config.mode === 'capture' ? config.stabilitySamples : 1;
            const seen = new Map<string, number>();
            let read: MaskedRead = { text: '', share: 0 };

            for (let attempt = 0; attempt < samples; attempt++) {
                const response = await visit(browserPage, page.path);

                if (response.status() >= 400) {
                    throw new Error(`${INCONCLUSIVE} unreachable — ${page.path} returned ${response.status()}.`);
                }

                const swallowing = await findSwallowingMasks(browserPage, page.mask ?? [], page.assert.visible);

                if (swallowing.length > 0) {
                    refuseSwallowedPage(page.path, swallowing, page.assert.visible);
                }

                read = await readMaskedText(browserPage, page.mask ?? []);
                seen.set(read.text, (seen.get(read.text) ?? 0) + 1);
            }

            recordMasking(page.id, read);

            if (seen.size > 1) {
                throw new Error(
                    `${UNSTABLE} ${page.path} rendered ${seen.size} different ways across ${samples} loads, so no baseline was recorded. ` +
                        `Mask whatever varies, or drop the page from the manifest. First difference: ${firstDifference([...seen.keys()])}`,
                );
            }

            expect(read.text).toMatchSnapshot(`${page.id}.txt`);
        });

        test(`screenshot:${page.id}`, async ({ page: browserPage }) => {
            const response = await visit(browserPage, page.path);

            if (response.status() >= 400) {
                throw new Error(`${INCONCLUSIVE} unreachable — ${page.path} returned ${response.status()}.`);
            }

            const swallowing = await findSwallowingMasks(browserPage, page.mask ?? [], page.assert.visible);

            if (swallowing.length > 0) {
                refuseSwallowedPage(page.path, swallowing, page.assert.visible);
            }

            // Fonts settle after load and shift text by a pixel or two; waiting for them here is
            // the difference between a stable baseline and a permanent low-level diff.
            await browserPage.evaluate(() => document.fonts.ready);

            // Scroll-driven animation inflates scrollHeight far beyond the visible content -- pages
            // on this site measure tens of thousands of pixels tall. A full-page capture of one is
            // hundreds of megapixels and kills the browser mid-screenshot, so the height is
            // measured first and an oversized page is refused with its number rather than crashed.
            if (config.fullPage) {
                const height = await browserPage.evaluate(() => document.documentElement.scrollHeight);

                if (height > config.maxFullPageHeight) {
                    throw new Error(
                        `${INCONCLUSIVE} page_too_tall — ${page.path} is ${height}px tall, over the ${config.maxFullPageHeight}px full-page limit. Capture it at viewport size instead.`,
                    );
                }
            }

            await expect(browserPage).toHaveScreenshot(`${page.id}.png`, {
                fullPage: config.fullPage,
                mask: (page.mask ?? []).map((selector) => browserPage.locator(selector)),
            });

            // A screenshot that matched leaves nothing on disk, and the dashboard shows every page's
            // pair whether or not it changed, so the pair is written here on a pass: the baseline as
            // held, and the page as it renders now. A failed match already left all three files.
            if (config.mode === 'compare') {
                const passed = join(test.info().outputDir, 'passed');
                const expected = join(paths.snapshots, `${page.id}.png`);
                mkdirSync(passed, { recursive: true });
                if (existsSync(expected)) {
                    copyFileSync(expected, join(passed, `${page.id}-expected.png`));
                }
                await browserPage.screenshot({
                    path: join(passed, `${page.id}-actual.png`),
                    fullPage: config.fullPage,
                    mask: (page.mask ?? []).map((selector) => browserPage.locator(selector)),
                });
            }
        });
    }
}
