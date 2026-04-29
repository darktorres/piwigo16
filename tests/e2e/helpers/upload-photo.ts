import * as fs from 'fs';
import * as path from 'path';
import type { APIRequestContext, Page } from '@playwright/test';

type MultipartValue = string | { name: string; mimeType: string; buffer: Buffer };

export async function getCookieHeader(page: Page): Promise<string> {
    const cookies = await page.context().cookies();
    return cookies.map((c) => `${c.name}=${c.value}`).join('; ');
}

export async function createAlbum(
    request: APIRequestContext,
    cookieHeader: string,
    name: string,
): Promise<number> {
    const res = await request.post('/ws.php?format=json', {
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
    photoName?: string,
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

    const response = await request.post('/ws.php?format=json', {
        headers: { Cookie: cookieHeader },
        multipart: form,
    });

    const body = await response.json();
    if (body.stat !== 'ok') {
        throw new Error(`Photo upload failed: ${JSON.stringify(body)}`);
    }
    return body.result.image_id as number;
}
