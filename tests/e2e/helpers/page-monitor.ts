import type { Page, ConsoleMessage, Request as PwRequest } from '@playwright/test';

/**
 * Captures everything that can go wrong on a page asynchronously:
 * - Uncaught JS exceptions (`pageerror`)
 * - `console.error` calls (often the only signal of a failed XHR)
 * - HTTP requests that returned 4xx/5xx (any resource type) or never
 *   finished at all (`requestfailed`)
 *
 * Every captured event records `page.url()` at the time it fired, so the
 * failure message tells you which page was active when the error occurred —
 * not just that something broke.
 *
 * Strict-assertions philosophy: don't suppress signals. If something
 * proves to be browser-only noise we can't quiet at the source, add a
 * narrowly-scoped filter here with a comment explaining why; otherwise
 * fix the root cause.
 */
export interface PageMonitor {
    pageErrors(): Array<{ message: string; pageUrl: string }>;
    consoleErrors(): Array<{ text: string; location: string; pageUrl: string }>;
    failedRequests(): Array<{
        url: string;
        status: number;
        method: string;
        pageUrl: string;
    }>;
    /** Throws an Error if any of the three buckets is non-empty. */
    assertClean(context?: string): void;
    /** Empties all buckets — useful between phases of a long test. */
    reset(): void;
}

export function attachMonitor(page: Page): PageMonitor {
    const pageErrors: Array<{ message: string; pageUrl: string }> = [];
    const consoleErrors: Array<{ text: string; location: string; pageUrl: string }> = [];
    const failedRequests: Array<{
        url: string;
        status: number;
        method: string;
        pageUrl: string;
    }> = [];

    // page.url() is sync and safe to call at any time; it returns the
    // current document URL (or about:blank if none has loaded yet).
    const here = (): string => {
        try {
            return page.url();
        } catch {
            return '(page closed)';
        }
    };

    page.on('pageerror', (err: Error) => {
        pageErrors.push({ message: err.message, pageUrl: here() });
    });

    page.on('console', (msg: ConsoleMessage) => {
        if (msg.type() === 'error') {
            // Chrome's "Failed to load resource" messages don't include the
            // URL in text() — it sits on the ConsoleMessageLocation. Surface
            // both so the failure tells us *what* failed and *where*.
            const loc = msg.location();
            consoleErrors.push({
                text: msg.text(),
                location: loc.url || '',
                pageUrl: here(),
            });
        }
    });

    page.on('requestfailed', (req: PwRequest) => {
        failedRequests.push({
            url: req.url(),
            status: 0,
            method: req.method(),
            pageUrl: here(),
        });
    });

    page.on('response', (resp) => {
        const status = resp.status();
        if (status < 400) return;
        failedRequests.push({
            url: resp.url(),
            status,
            method: resp.request().method(),
            pageUrl: here(),
        });
    });

    return {
        pageErrors: () => [...pageErrors],
        consoleErrors: () => [...consoleErrors],
        failedRequests: () => [...failedRequests],
        assertClean(context?: string): void {
            const lines: string[] = [];
            for (const e of pageErrors) {
                lines.push(`  pageerror @ ${e.pageUrl}: ${e.message}`);
            }
            for (const e of consoleErrors) {
                const where = e.location ? ` resource=${e.location}` : '';
                lines.push(`  console.error @ ${e.pageUrl}:${where} ${e.text}`);
            }
            for (const e of failedRequests) {
                lines.push(
                    `  http-fail @ ${e.pageUrl}: ${e.method} ${e.status} ${e.url}`
                );
            }
            if (lines.length > 0) {
                throw new Error(
                    (context ? `[${context}] ` : '') +
                        `Page reported ${lines.length} error(s):\n` +
                        lines.join('\n')
                );
            }
        },
        reset(): void {
            pageErrors.length = 0;
            consoleErrors.length = 0;
            failedRequests.length = 0;
        },
    };
}
