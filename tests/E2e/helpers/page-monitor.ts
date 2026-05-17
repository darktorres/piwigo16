import type { Page, ConsoleMessage, Request as PwRequest } from '@playwright/test';

/**
 * Captures everything that can go wrong on a page asynchronously:
 * - Uncaught JS exceptions (`pageerror`)
 * - `console.error` calls (often the only signal of a failed XHR)
 * - HTTP requests that returned 4xx/5xx (any resource type) or never
 *   finished at all (`requestfailed`)
 *
 * Call `assertClean()` at any point to fail the test if any signal fired.
 * The thrown message lists every captured event so the failure tells you
 * what broke, not just that something did.
 *
 * Strict-assertions philosophy: don't suppress signals. If something
 * proves to be browser-only noise we can't quiet at the source, add a
 * narrowly-scoped filter here with a comment explaining why; otherwise
 * fix the root cause.
 */
export interface PageMonitor {
    pageErrors(): Error[];
    consoleErrors(): string[];
    failedRequests(): Array<{ url: string; status: number; method: string }>;
    /** Throws an Error if any of the three buckets is non-empty. */
    assertClean(context?: string): void;
    /** Empties all buckets — useful between phases of a long test. */
    reset(): void;
}

export function attachMonitor(page: Page): PageMonitor {
    const pageErrors: Error[] = [];
    const consoleErrors: string[] = [];
    const failedRequests: Array<{ url: string; status: number; method: string }> = [];

    // Track requests in flight so reset() can mark them as "ignore on
    // failure". Without this, a navigation that cancels prior-page XHRs
    // (e.g. gallery thumbnails fired by themes/_base/js/thumbnails.loader.ts
    // after loginAsAdmin lands on /) produces requestfailed events AFTER
    // reset(), which then look like fresh failures of the next page.
    const inFlight = new Set<PwRequest>();
    let ignored = new Set<PwRequest>();

    page.on('request', (req: PwRequest) => {
        inFlight.add(req);
    });
    page.on('requestfinished', (req: PwRequest) => {
        inFlight.delete(req);
    });

    page.on('pageerror', (err: Error) => {
        pageErrors.push(err);
    });

    page.on('console', (msg: ConsoleMessage) => {
        if (msg.type() === 'error') {
            consoleErrors.push(msg.text());
        }
    });

    page.on('requestfailed', (req: PwRequest) => {
        inFlight.delete(req);
        if (ignored.has(req)) {
            ignored.delete(req);
            return;
        }
        failedRequests.push({
            url: req.url(),
            status: 0,
            method: req.method(),
        });
    });

    page.on('response', (resp) => {
        const status = resp.status();
        if (status < 400) return;
        failedRequests.push({
            url: resp.url(),
            status,
            method: resp.request().method(),
        });
    });

    return {
        pageErrors: () => [...pageErrors],
        consoleErrors: () => [...consoleErrors],
        failedRequests: () => [...failedRequests],
        assertClean(context?: string): void {
            const parts: string[] = [];
            if (pageErrors.length > 0) {
                parts.push(
                    `pageerror×${pageErrors.length}: ` +
                        pageErrors.map((e) => e.message).join(' | ')
                );
            }
            if (consoleErrors.length > 0) {
                parts.push(`console.error×${consoleErrors.length}: ` + consoleErrors.join(' | '));
            }
            if (failedRequests.length > 0) {
                parts.push(
                    `http-fail×${failedRequests.length}: ` +
                        failedRequests.map((r) => `${r.method} ${r.status} ${r.url}`).join(' | ')
                );
            }
            if (parts.length > 0) {
                throw new Error(
                    (context !== undefined && context !== '' ? `[${context}] ` : '') +
                        'Page reported errors:\n  ' +
                        parts.join('\n  ')
                );
            }
        },
        reset(): void {
            pageErrors.length = 0;
            consoleErrors.length = 0;
            failedRequests.length = 0;
            // Anything in flight at reset time should not count if it fails
            // later — typically a prior-page XHR cancelled by the next nav.
            ignored = new Set(inFlight);
        },
    };
}
