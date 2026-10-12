import { chromium, expect, test } from '@playwright/test';
import { createServer, type IncomingMessage, type RequestListener, type Server } from 'node:http';
import { execFile } from 'node:child_process';
import { existsSync, mkdirSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';
import { promisify } from 'node:util';
import { cfAccessHeaders, continueWithAccess } from '../../src/access';
import { siteAccessHeaders } from '../../src/config';
import { bundlePaths, fetchReport, isAbort } from '../../src/manifest';

test('the plugin API receives both staging basic auth and the Phone Home token', async () => {
    let received: IncomingMessage['headers'] = {};
    const server = createServer((request, response) => {
        received = request.headers;
        if (request.headers.authorization !== `Basic ${Buffer.from('staging:password').toString('base64')}`) {
            response.writeHead(401).end();
            return;
        }
        response.setHeader('Content-Type', 'application/json');
        response.end(JSON.stringify({ verification: {
            schema_version: 1, supported: true, enabled: true, valid: true, errors: [],
            pages: [{ id: 'home', path: '/', assert: { visible: 'h1' } }],
        } }));
    });
    await new Promise<void>((resolve) => server.listen(0, '127.0.0.1', resolve));
    try {
        const address = server.address();
        if (address === null || typeof address === 'string') throw new Error('No server address');
        const report = await fetchReport(`http://127.0.0.1:${address.port}`, 'site-token', false, siteAccessHeaders({ basicAuth: { username: 'staging', password: 'password' }, cfAccess: null }));
        expect(isAbort(report)).toBe(false);
        expect(received['x-auth-token']).toBe('site-token');
    } finally {
        await new Promise<void>((resolve, reject) => server.close((error) => error ? reject(error) : resolve()));
    }
});

const runner = new URL('../../', import.meta.url).pathname;
const BASIC = `Basic ${Buffer.from('staging:password').toString('base64')}`;
const MANIFEST = JSON.stringify({ verification: {
    schema_version: 1, supported: true, enabled: true, valid: true, errors: [],
    pages: [{ id: 'home', path: '/', assert: { visible: 'h1' } }],
} });

async function listen(handler: RequestListener, host = '127.0.0.1'): Promise<{ server: Server; port: number }> {
    const server = createServer(handler);
    await new Promise<void>((resolve) => server.listen(0, host, resolve));
    const address = server.address();
    if (address === null || typeof address === 'string') throw new Error('No server address');

    return { server, port: address.port };
}

async function close(server: Server): Promise<void> {
    await new Promise<void>((resolve, reject) => server.close((error) => error ? reject(error) : resolve()));
}

/**
 * Loads the capture config, which reads the manifest and asks the dashboard whether a baseline is
 * already held, and returns the headers the plugin API and the dashboard each received.
 */
async function loadCaptureConfig(options: { separateApi: boolean; env: Record<string, string> }) {
    const api: IncomingMessage['headers'][] = [];
    const dashboard: IncomingMessage['headers'][] = [];
    const site = await listen((request, response) => {
        api.push(request.headers);
        response.setHeader('Content-Type', 'application/json');
        response.end(MANIFEST);
    });
    const phoneHome = await listen((request, response) => {
        dashboard.push(request.headers);
        response.writeHead(404).end();
    });
    const apiOrigin = `http://127.0.0.1:${site.port}`;
    // The same server under another name is another origin, as a separate API host would be.
    const origin = options.separateApi ? `http://localhost:${site.port}` : apiOrigin;
    const runId = `auth-test-${process.pid}-${options.separateApi}-${Object.keys(options.env).join('-')}`;
    const bundle = join(runner, 'runs', new URL(origin).host.replace(/[^a-z0-9.-]/gi, '_'), runId);
    mkdirSync(bundle, { recursive: true });
    const unstored = join(bundle, 'baseline-unstored.txt');
    writeFileSync(unstored, 'Previous upload failed');
    try {
        await promisify(execFile)(process.execPath, ['node_modules/playwright/cli.js', 'test', '--list'], {
            cwd: runner,
            env: {
                ...process.env,
                PHV_BUNDLE_OWNER: undefined,
                PHV_MODE: 'capture', PHV_RUN_ID: runId, PHV_ORIGIN: origin, PHV_API_ORIGIN: apiOrigin,
                PHV_TOKEN: 'site-token', PHV_DASHBOARD_ORIGIN: `http://127.0.0.1:${phoneHome.port}`, PHV_REPLACE: '0',
                ...options.env,
            },
            timeout: 15_000,
        });

        const abortFile = bundlePaths(bundle).abort;

        return {
            api,
            dashboard,
            unstoredCleared: !existsSync(unstored),
            pending: existsSync(join(bundle, 'capture.pending.json')),
            abort: existsSync(abortFile) ? (JSON.parse(readFileSync(abortFile, 'utf8')) as { reason: string; detail: string[] }) : null,
        };
    } finally {
        rmSync(bundle, { recursive: true, force: true });
        await close(site.server);
        await close(phoneHome.server);
    }
}

const BASIC_ENV = { PHV_BASIC_AUTH_USER: 'staging', PHV_BASIC_AUTH_PASS: 'password' };
const CF_ENV = { PHV_CF_ACCESS_CLIENT_ID: 'access-id.access', PHV_CF_ACCESS_CLIENT_SECRET: 'access-secret' };

for (const separateApi of [false, true]) {
    test(`capture config ${separateApi ? 'withholds basic auth from a separate API origin' : 'authenticates the API and clears a stale upload failure'}`, async () => {
        const result = await loadCaptureConfig({ separateApi, env: BASIC_ENV });

        expect(result.api).toHaveLength(1);
        expect(result.api[0]['x-auth-token']).toBe('site-token');
        expect(result.api[0].authorization).toBe(separateApi ? undefined : BASIC);
        expect(result.unstoredCleared).toBe(true);
        expect(result.pending).toBe(true);
    });
}

test.describe('a Cloudflare Access service token', () => {
    test('is sent to the plugin API on the site origin', async () => {
        const result = await loadCaptureConfig({ separateApi: false, env: CF_ENV });

        expect(result.api[0]['cf-access-client-id']).toBe('access-id.access');
        expect(result.api[0]['cf-access-client-secret']).toBe('access-secret');
        expect(result.api[0]['x-auth-token']).toBe('site-token');
    });

    test('is not sent when it is not configured', async () => {
        const result = await loadCaptureConfig({ separateApi: false, env: {} });

        expect(result.api[0]['cf-access-client-id']).toBeUndefined();
        expect(result.api[0]['cf-access-client-secret']).toBeUndefined();
    });

    test('is not sent to a separate API origin', async () => {
        const result = await loadCaptureConfig({ separateApi: true, env: CF_ENV });

        expect(result.api[0]['x-auth-token']).toBe('site-token');
        expect(result.api[0]['cf-access-client-id']).toBeUndefined();
        expect(result.api[0]['cf-access-client-secret']).toBeUndefined();
    });

    test('is never sent to the Phone Home dashboard', async () => {
        const result = await loadCaptureConfig({ separateApi: false, env: { ...CF_ENV, ...BASIC_ENV } });

        expect(result.dashboard).not.toHaveLength(0);
        for (const headers of result.dashboard) {
            expect(headers['cf-access-client-id']).toBeUndefined();
            expect(headers['cf-access-client-secret']).toBeUndefined();
            expect(headers.authorization).not.toBe(BASIC);
        }
    });

    test('and basic auth are both sent when both are configured', async () => {
        const result = await loadCaptureConfig({ separateApi: false, env: { ...CF_ENV, ...BASIC_ENV } });

        expect(result.api[0].authorization).toBe(BASIC);
        expect(result.api[0]['cf-access-client-id']).toBe('access-id.access');
        expect(result.api[0]['cf-access-client-secret']).toBe('access-secret');
    });

    test('is refused as the run\'s outcome when only half of it is set', async () => {
        // Refused as an abort the result and the dashboard carry, not thrown at config load, where
        // a CI run dies before there is anything to report.
        const result = await loadCaptureConfig({ separateApi: false, env: { PHV_CF_ACCESS_CLIENT_ID: 'access-id.access' } });

        expect(result.abort).toEqual({
            reason: 'misconfigured',
            detail: ['Only PHV_CF_ACCESS_CLIENT_ID was set. PHV_CF_ACCESS_CLIENT_ID and PHV_CF_ACCESS_CLIENT_SECRET must be set together.'],
        });
        expect(result.api).toHaveLength(0);
    });

    test('reaches the site\'s own pages and assets in a capture run, and nothing of the site\'s reaches a third party', async () => {
        const seen: { path: string; id: string | undefined }[] = [];
        const elsewhere: IncomingMessage['headers'][] = [];
        // A third-party host on loopback, which Chromium refuses to a fulfilled page unless the
        // runner lifts that check. It challenges for basic auth, which must not be answered with
        // staging's credentials. The iframe is what shows it: Chromium never answers a challenge
        // for a cross-origin image, but it does for a frame.
        const third = await listen((request, response) => {
            elsewhere.push(request.headers);
            if (request.headers.authorization === undefined) {
                response.writeHead(401, { 'WWW-Authenticate': 'Basic realm="cdn"' }).end();
                return;
            }
            response.writeHead(200, { 'Content-Type': 'image/svg+xml' }).end('<svg xmlns="http://www.w3.org/2000/svg" width="4" height="4"/>');
        });
        const site = await listen((request, response) => {
            const id = request.headers['cf-access-client-id'] as string | undefined;
            seen.push({ path: request.url ?? '', id });
            if (request.url === '/actions/phonehome/api') {
                response.setHeader('Content-Type', 'application/json');
                response.end(MANIFEST);
                return;
            }
            // Access answers a request without the token with its login page.
            if (id !== 'access-id.access') {
                response.writeHead(403, { 'Content-Type': 'text/html' }).end('<!doctype html><p>Sign in with Cloudflare Access</p>');
                return;
            }
            if (request.url === '/logo.svg') {
                response.writeHead(200, { 'Content-Type': 'image/svg+xml' }).end('<svg xmlns="http://www.w3.org/2000/svg" width="4" height="4"/>');
                return;
            }
            response.writeHead(200, { 'Content-Type': 'text/html' }).end(`<!doctype html><h1>Home</h1><img src="/logo.svg"><img src="http://127.0.0.1:${third.port}/badge.svg"><iframe src="http://127.0.0.1:${third.port}/embed"></iframe>`);
        }, 'localhost');
        const origin = `http://localhost:${site.port}`;
        const runId = `auth-browser-${process.pid}`;
        const bundle = join(runner, 'runs', new URL(origin).host.replace(/[^a-z0-9.-]/gi, '_'), runId);
        try {
            const run = await promisify(execFile)(process.execPath, ['node_modules/playwright/cli.js', 'test'], {
                cwd: runner,
                env: {
                    ...process.env,
                    PHV_BUNDLE_OWNER: undefined,
                    PHV_MODE: 'capture', PHV_RUN_ID: runId, PHV_ORIGIN: origin, PHV_API_ORIGIN: '',
                    PHV_TOKEN: 'site-token', PHV_DASHBOARD_ORIGIN: '', PHV_REPLACE: '1', PHV_STABILITY_SAMPLES: '1',
                    ...CF_ENV,
                    ...BASIC_ENV,
                },
                timeout: 60_000,
            }).catch((error: { stdout: string; stderr: string }) => error);

            expect(run.stdout, run.stderr).toContain('capture: PASSED');
            expect(seen.some((request) => request.path === '/')).toBe(true);
            expect(seen.some((request) => request.path === '/logo.svg')).toBe(true);
            expect(seen.every((request) => request.id === 'access-id.access')).toBe(true);
            expect(elsewhere).not.toHaveLength(0);
            for (const headers of elsewhere) {
                expect(headers['cf-access-client-id']).toBeUndefined();
                expect(headers['cf-access-client-secret']).toBeUndefined();
                expect(headers.authorization).toBeUndefined();
            }
        } finally {
            rmSync(bundle, { recursive: true, force: true });
            await close(site.server);
            await close(third.server);
        }
    });

    test('goes to one hop on the site at a time, so a redirect off the site never carries it', async () => {
        const seen: { host: string; path: string; id: string | undefined; cookie: string | undefined }[] = [];
        const record = (host: string, request: IncomingMessage) =>
            seen.push({ host, path: request.url ?? '', id: request.headers['cf-access-client-id'] as string | undefined, cookie: request.headers.cookie });
        const svg = '<svg xmlns="http://www.w3.org/2000/svg" width="4" height="4"/>';
        const third = await listen((request, response) => {
            record('third', request);
            response.writeHead(200, { 'Content-Type': 'image/svg+xml' }).end(svg);
        });
        const site = await listen((request, response) => {
            record('site', request);
            if (request.url === '/transform.svg') {
                // A Craft image transform handed off to a CDN.
                response.writeHead(302, { Location: `http://127.0.0.1:${third.port}/transform.svg` }).end();
                return;
            }
            if (request.url === '/old') {
                // Access sets its cookie on a request it lets through, which carries a same-origin redirect.
                response.writeHead(302, { Location: '/new', 'Set-Cookie': 'CF_Authorization=jwt; Path=/' }).end();
                return;
            }
            response.writeHead(200, { 'Content-Type': 'text/html' }).end(`<!doctype html><h1>Page</h1><img src="/transform.svg"><img src="http://127.0.0.1:${third.port}/font.svg">`);
        }, 'localhost');
        const origin = `http://localhost:${site.port}`;
        // A fulfilled page has no address space of its own, so Chromium treats it as public and
        // refuses its requests to loopback. A real CDN is not on loopback; this server is.
        const browser = await chromium.launch({ args: ['--disable-features=LocalNetworkAccessChecks'] });
        try {
            const page = await browser.newPage();
            const headers = cfAccessHeaders({ clientId: 'access-id.access', clientSecret: 'access-secret' });
            await page.route('**/*', (route) => new URL(route.request().url()).origin === origin ? continueWithAccess(route, headers) : route.continue());

            await page.goto(`${origin}/`, { waitUntil: 'load' });
            expect(await page.evaluate(() => [...document.images].map((image) => image.naturalWidth))).toEqual([4, 4]);
            await page.goto(`${origin}/old`, { waitUntil: 'load' });
            expect(page.url()).toBe(`${origin}/new`);

            const onSite = seen.filter((request) => request.host === 'site');
            expect(onSite.find((request) => request.path === '/')?.id).toBe('access-id.access');
            expect(onSite.find((request) => request.path === '/transform.svg')?.id).toBe('access-id.access');
            expect(onSite.find((request) => request.path === '/old')?.id).toBe('access-id.access');
            expect(onSite.find((request) => request.path === '/new')?.cookie).toBe('CF_Authorization=jwt');
            expect([...new Set(seen.filter((request) => request.host === 'third').map((request) => request.path))].sort()).toEqual(['/font.svg', '/transform.svg']);
            expect(seen.filter((request) => request.host === 'third').every((request) => request.id === undefined)).toBe(true);
        } finally {
            await browser.close();
            await close(site.server);
            await close(third.server);
        }
    });

    test('says why a request to the site could not be fetched', async () => {
        const site = await listen((request, response) => {
            if (request.url === '/broken.css') {
                request.socket.destroy();
                return;
            }
            response.writeHead(200, { 'Content-Type': 'text/html' }).end('<!doctype html><link rel="stylesheet" href="/broken.css"><h1>Page</h1>');
        }, 'localhost');
        const origin = `http://localhost:${site.port}`;
        const logged: string[] = [];
        const browser = await chromium.launch();
        try {
            const page = await browser.newPage();
            const headers = cfAccessHeaders({ clientId: 'access-id.access', clientSecret: 'access-secret' });
            await page.route('**/*', (route) => continueWithAccess(route, headers, (message) => logged.push(message)));

            await page.goto(`${origin}/`, { waitUntil: 'load' });

            expect(logged).toHaveLength(1);
            expect(logged[0]).toContain(`Could not fetch ${origin}/broken.css for the browser: `);
        } finally {
            await browser.close();
            await close(site.server);
        }
    });
});
