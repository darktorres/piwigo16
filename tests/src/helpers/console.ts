import { type Page, expect } from "@playwright/test";

export interface ConsoleIssue {
    level: string;
    text: string;
}

export function collectConsoleIssues(page: Page): () => ConsoleIssue[] {
    const issues: ConsoleIssue[] = [];

    page.on("console", (msg) => {
        const type = msg.type();
        if (type === "error" || type === "warning") {
            issues.push({ level: type, text: msg.text() });
        }
        if (type === "log") {
            const text = msg.text().toLowerCase();
            if (text.includes("error") || text.includes("uncaught")) {
                issues.push({ level: "silent-error", text: msg.text() });
            }
        }
    });

    page.on("pageerror", (err) => {
        issues.push({ level: "pageerror", text: err.message });
    });

    page.on("requestfailed", (req) => {
        issues.push({ level: "network-error", text: `${req.method()} ${req.url()}: ${req.failure()?.errorText ?? "unknown"}` });
    });

    return () => [...issues];
}

const PHP_ERROR_RE = /\b(Warning|Notice|Fatal error|Parse error|Deprecated|Strict Standards):/g;

export async function assertNoErrors(page: Page, issues: ConsoleIssue[]): Promise<void> {
    const content = await page.content();
    const phpMatches = [...content.matchAll(PHP_ERROR_RE)].map((m) => m[0]);

    expect(
        phpMatches,
        `PHP error/warning in HTML at ${page.url()}: ${phpMatches.join(", ")}`,
    ).toEqual([]);

    const criticalIssues = issues.filter((issue) => {
        if (issue.level === "network-error" && issue.text.includes("404")) return false;
        // Browsers abort navigation when a file download starts — not a real error
        if (issue.level === "network-error" && issue.text.includes("ERR_ABORTED")) return false;
        return true;
    });

    expect(criticalIssues, `JS console/network issues at ${page.url()}`).toEqual([]);
}
