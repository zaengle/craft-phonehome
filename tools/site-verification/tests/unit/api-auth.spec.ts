import { expect, test } from '@playwright/test';
import { createServer, type IncomingMessage } from 'node:http';
import { execFile } from 'node:child_process';
import { existsSync, mkdirSync, rmSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';
import { promisify } from 'node:util';
import { fetchReport, isAbort } from '../../src/manifest';

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
        const report = await fetchReport(`http://127.0.0.1:${address.port}`, 'site-token', false, { username: 'staging', password: 'password' });
        expect(isAbort(report)).toBe(false);
        expect(received['x-auth-token']).toBe('site-token');
    } finally {
        await new Promise<void>((resolve, reject) => server.close((error) => error ? reject(error) : resolve()));
    }
});

for (const separateApi of [false, true]) {
    test(`capture config ${separateApi ? 'withholds basic auth from a separate API origin' : 'authenticates the API and clears a stale upload failure'}`, async () => {
        const runner = new URL('../../', import.meta.url).pathname;
        let received: IncomingMessage['headers'] | null = null;
        const server = createServer((request, response) => {
            received = request.headers;
            response.setHeader('Content-Type', 'application/json');
            response.end(JSON.stringify({ verification: {
                schema_version: 1, supported: true, enabled: true, valid: true, errors: [],
                pages: [{ id: 'home', path: '/', assert: { visible: 'h1' } }],
            } }));
        });
        await new Promise<void>((resolve) => server.listen(0, '127.0.0.1', resolve));
        const address = server.address();
        if (address === null || typeof address === 'string') throw new Error('No server address');
        const apiOrigin = `http://127.0.0.1:${address.port}`;
        const origin = separateApi ? `http://localhost:${address.port}` : apiOrigin;
        const runId = `auth-test-${process.pid}-${separateApi}`;
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
                    PHV_TOKEN: 'site-token', PHV_DASHBOARD_ORIGIN: '', PHV_REPLACE: '0',
                    PHV_BASIC_AUTH_USER: 'staging', PHV_BASIC_AUTH_PASS: 'password',
                },
                timeout: 15_000,
            });
            expect(received).not.toBeNull();
            expect(received!['x-auth-token']).toBe('site-token');
            expect(received!.authorization).toBe(separateApi ? undefined : `Basic ${Buffer.from('staging:password').toString('base64')}`);
            expect(existsSync(unstored)).toBe(false);
            expect(existsSync(join(bundle, 'capture.pending.json'))).toBe(true);
        } finally {
            rmSync(bundle, { recursive: true, force: true });
            await new Promise<void>((resolve, reject) => server.close((error) => error ? reject(error) : resolve()));
        }
    });
}
