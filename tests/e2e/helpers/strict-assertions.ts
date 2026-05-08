import type { Page, Locator, Response } from '@playwright/test';
import { expect } from '@playwright/test';

/**
 * Patterns that indicate a server-side problem rendered into the response
 * body. Generic "Fatal error" matching misses most of the failure modes
 * Piwigo can produce — Notice/Deprecated/Stack trace/Throwable/Uncaught
 * are all things that mean the server is unhealthy.
 */
const SERVER_ERROR_PATTERNS: ReadonlyArray<{ name: string; pattern: RegExp }> = [
    { name: 'Fatal error', pattern: /Fatal error/i },
    { name: 'Parse error', pattern: /Parse error/i },
    { name: 'Warning:', pattern: /\bWarning:\s/ },
    { name: 'Notice:', pattern: /\bNotice:\s/ },
    { name: 'Deprecated:', pattern: /\bDeprecated:\s/ },
    { name: 'Strict Standards:', pattern: /\bStrict Standards:\s/ },
    { name: 'Stack trace:', pattern: /Stack trace:/ },
    { name: 'Uncaught', pattern: /\bUncaught\s/ },
    { name: 'Throwable', pattern: /\bThrowable\b/ },
    // PHP exception class names that show up in fatal output.
    { name: 'TypeError', pattern: /\bTypeError:/ },
    { name: 'ValueError', pattern: /\bValueError:/ },
    { name: 'PDOException', pattern: /PDOException/ },
];

/**
 * Reads the rendered HTML body and fails if any known server-side error
 * marker appears. Use this AFTER every navigation or form submission that
 * should produce a normal page.
 */
export async function assertNoServerErrors(page: Page, context?: string): Promise<void> {
    const html = await page.content();
    const hits = SERVER_ERROR_PATTERNS.filter(({ pattern }) => pattern.test(html));
    if (hits.length === 0) return;
    const labels = hits.map((h) => h.name).join(', ');
    // Include a short snippet around the first match so the failure is
    // diagnostic — chasing "Fatal error appeared" through 4MB of HTML is
    // not productive.
    const firstHit = hits[0];
    const m = firstHit.pattern.exec(html);
    const snippet = m
        ? html
              .slice(Math.max(0, m.index - 100), Math.min(html.length, m.index + 400))
              .replace(/\s+/g, ' ')
              .trim()
        : '';
    throw new Error(
        (context !== undefined && context !== '' ? `[${context}] ` : '') +
            `Server error markers in response body: ${labels}\n` +
            `Snippet: …${snippet}…`
    );
}

/**
 * Asserts the locator is visible within `timeoutMs`, with a labelled error
 * message. Use before any `.fill()` / `.click()` so a missing element
 * fails as "the username field is missing" instead of a generic timeout.
 */
export async function assertVisible(
    locator: Locator,
    name: string,
    timeoutMs = 5000
): Promise<void> {
    await expect(locator, `${name} should be visible`).toBeVisible({ timeout: timeoutMs });
}

/**
 * Asserts an HTTP response is in the 2xx range. Helpful for verifying
 * the *initial* navigation status; subsequent in-page navigations need
 * the page-monitor's failedRequests() to catch.
 */
export function assertHttpOk(
    response: Response | null | undefined,
    context: string
): asserts response is Response {
    if (!response) {
        throw new Error(`[${context}] No HTTP response received`);
    }
    const s = response.status();
    if (s < 200 || s >= 400) {
        throw new Error(`[${context}] Unexpected HTTP status ${s} for ${response.url()}`);
    }
}

/**
 * Goes to a URL and verifies (a) HTTP status is OK and (b) no server-error
 * markers appear in the rendered body. Wrapper for the most common
 * "navigate to a page that should just work" pattern.
 */
export async function gotoOk(page: Page, url: string, context?: string): Promise<Response> {
    const ctx = context ?? url;
    const resp = await page.goto(url);
    assertHttpOk(resp, ctx);
    await assertNoServerErrors(page, ctx);
    return resp;
}
