import * as fs from 'fs';
import * as path from 'path';
import type { APIRequestContext, Page } from '@playwright/test';
import { wsUrl } from './url';

type MultipartValue = string | { name: string; mimeType: string; buffer: Buffer };

export async function getCookieHeader(page: Page): Promise<string> {
    const cookies = await page.context().cookies();
    return cookies.map((c) => `${c.name}=${c.value}`).join('; ');
}

// Fetches the per-session CSRF token required by destructive API methods
// (pwg.images.delete, pwg.tags.delete, pwg.users.add/delete, …).
export async function getPwgToken(
    request: APIRequestContext,
    cookieHeader: string
): Promise<string> {
    const res = await request.post(wsUrl({ format: 'json' }), {
        headers: { Cookie: cookieHeader },
        form: { method: 'pwg.session.getStatus' },
    });
    const body = await res.json();
    return body.result.pwg_token as string;
}

export async function createAlbum(
    request: APIRequestContext,
    cookieHeader: string,
    name: string
): Promise<number> {
    const res = await request.post(wsUrl({ format: 'json' }), {
        headers: { Cookie: cookieHeader },
        form: { method: 'pwg.categories.add', name },
    });
    const body = await res.json();
    return body.result.id as number;
}

export async function uploadPhoto(
    request: APIRequestContext,
    cookieHeader: string,
    imagePath: string,
    albumId: number,
    photoName?: string
): Promise<number> {
    const buffer = fs.readFileSync(imagePath);
    const filename = path.basename(imagePath);

    const form: Record<string, MultipartValue> = {
        method: 'pwg.images.addSimple',
        category: String(albumId),
        image: { name: filename, mimeType: 'image/jpeg', buffer },
    };
    if (photoName) {
        form['name'] = photoName;
    }

    const response = await request.post(wsUrl({ format: 'json' }), {
        headers: { Cookie: cookieHeader },
        multipart: form,
    });

    const text = await response.text();
    let body: { stat?: string; result?: { image_id?: number }; err?: unknown; message?: unknown };
    try {
        body = JSON.parse(text);
    } catch {
        throw new Error(
            `Photo upload returned non-JSON (HTTP ${response.status()}): ${text.slice(0, 600)}`
        );
    }
    if (body.stat !== 'ok') {
        throw new Error(`Photo upload failed: ${JSON.stringify(body)}`);
    }
    // After enough photos accumulate in the gallery, Piwigo auto-activates the
    // "lounge" — uploads then sit pending and don't appear in getImages until
    // released. Flush it here so tests see what they just uploaded.
    await request.post(wsUrl({ format: 'json' }), {
        headers: { Cookie: cookieHeader },
        form: { method: 'pwg.images.emptyLounge' },
    });
    return body.result!.image_id as number;
}
