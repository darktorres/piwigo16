import { Page } from '@playwright/test';
import { pwgUrl } from './url';

export async function loginAsAdmin(page: Page): Promise<void> {
    await page.goto(pwgUrl('/identification.php'));
    await page.fill('input[name="username"]', 'admin');
    await page.fill('input[name="password"]', 'p4ssword!');
    await page.click('input[name="login"]');
    await page.waitForURL((url) => !url.pathname.includes('identification.php'));
}

export function attachErrorCollector(page: Page): () => Error[] {
    const errors: Error[] = [];
    page.on('pageerror', (err) => errors.push(err));
    return () => errors;
}
