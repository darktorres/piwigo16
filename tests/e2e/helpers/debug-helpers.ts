import type { Page, BrowserContext } from '@playwright/test';
export async function setupHttpLogging(_page: Page): Promise<void> {}
export async function setupConsoleLogging(_page: Page): Promise<void> {}
export async function captureAndSaveDebugContext(
    _ctx: Page | BrowserContext,
    _name: string
): Promise<string | undefined> {
    return undefined;
}
export function clearDebugData(_page: Page): void {}
