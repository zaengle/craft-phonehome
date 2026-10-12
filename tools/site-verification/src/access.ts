import type { Route } from '@playwright/test';

/** A Cloudflare Access service token as the two headers Access reads it from. */
export function cfAccessHeaders(token: { clientId: string; clientSecret: string }): Record<string, string> {
    return { 'CF-Access-Client-Id': token.clientId, 'CF-Access-Client-Secret': token.clientSecret };
}

/**
 * Sends a request for the site's own origin with the Access token, one hop at a time.
 *
 * `route.continue()` cannot carry the token, because the headers it is given follow any redirect
 * the request starts. Measured: a same-origin image that redirected to a CDN handed the CDN the
 * token. Fetched here with redirects turned off, the token reaches exactly this hop, and the browser
 * follows a redirect itself, without it. Under Access's default settings the cookie Access set on
 * this hop gets a same-origin redirect through. In strict service-token mode it does not, and that
 * page is reported unreachable rather than the token being sent further.
 */
export async function continueWithAccess(route: Route, headers: Record<string, string>): Promise<void> {
    try {
        const response = await route.fetch({ headers: { ...route.request().headers(), ...headers }, maxRedirects: 0 });

        await route.fulfill({ response });
    } catch {
        await route.abort('failed').catch(() => undefined);
    }
}
