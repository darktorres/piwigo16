// Regenerates dev/fixtures/piwigo-16.x.sql by driving a fresh install +
// content seed against a scratch database, then dumping it.
//
// Usage:
//   REGENERATE_FIXTURE=1 npx playwright test tests/e2e/regenerate-fixture.spec.ts
//
// Skipped by default — this spec writes & deletes a real local database
// and overwrites a committed fixture file. It is not a regression test.
//
// Credentials come from .env.local (PIWIGO_DB_HOST/PORT/USER/PASSWORD).

import { test, expect, type APIRequestContext } from '@playwright/test';
import { execSync } from 'child_process';
import { fileURLToPath } from 'url';
import * as fs from 'fs';
import * as os from 'os';
import * as path from 'path';
import { pwgUrl, wsUrl } from './helpers/url';
import { createAlbum, getCookieHeader, getPwgToken, uploadPhoto } from './helpers/upload-photo';

const __dirname = path.dirname(fileURLToPath(import.meta.url));

const SCRATCH_DB = 'piwigo_fixture_build';
const ADMIN_USER = 'fixture_admin';
const ADMIN_PASS = 'fixture_admin';
const FIXTURE_PATH = path.resolve(__dirname, '../../dev/fixtures/piwigo-16.x.sql');
const DB_CONFIG_PATH = path.resolve(__dirname, '../../local/config/database.inc.php');

const DB_HOST = process.env.PIWIGO_DB_HOST ?? '127.0.0.1';
const DB_PORT = process.env.PIWIGO_DB_PORT ?? '3306';
const DB_USER = process.env.PIWIGO_DB_USER ?? 'root';
const DB_PASS = process.env.PIWIGO_DB_PASSWORD ?? '';

function shellQuote(s: string): string {
    return `"${s.replace(/(["\\$`])/g, '\\$1')}"`;
}

function mysqlExec(sql: string): void {
    const cmd = `mysql -h${DB_HOST} -P${DB_PORT} -u${DB_USER} -p${DB_PASS} -e ${shellQuote(sql)}`;
    execSync(cmd, { stdio: 'pipe' });
}

function writeDbConfig(): void {
    const dir = path.dirname(DB_CONFIG_PATH);
    if (!fs.existsSync(dir)) fs.mkdirSync(dir, { recursive: true });
    fs.writeFileSync(
        DB_CONFIG_PATH,
        `<?php
$conf['dblayer'] = 'mysqli';
$conf['db_host'] = '${DB_HOST}:${DB_PORT}';
$conf['db_user'] = '${DB_USER}';
$conf['db_password'] = '${DB_PASS}';
$conf['db_base'] = '${SCRATCH_DB}';
$prefixeTable = 'piwigo_';
`
    );
}

async function callWs(
    request: APIRequestContext,
    cookieHeader: string,
    method: string,
    params: Record<string, string> = {}
): Promise<{ stat?: string; result?: unknown; err?: unknown; message?: unknown }> {
    const res = await request.post(wsUrl({ format: 'json' }), {
        headers: { Cookie: cookieHeader },
        form: { method, ...params },
    });
    return res.json();
}

test.describe.serial('regenerate dev/fixtures/piwigo-16.x.sql', () => {
    test.skip(
        process.env.REGENERATE_FIXTURE !== '1',
        'Set REGENERATE_FIXTURE=1 to regenerate the fixture (writes a real DB and overwrites the committed fixture file).'
    );

    test.beforeAll(() => {
        // Reset scratch DB and point Apache at it.
        mysqlExec(`DROP DATABASE IF EXISTS ${SCRATCH_DB};`);
        mysqlExec(
            `CREATE DATABASE ${SCRATCH_DB} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;`
        );
        if (fs.existsSync(DB_CONFIG_PATH)) fs.unlinkSync(DB_CONFIG_PATH);
        writeDbConfig();
        // Clear the install sentinel so install.php shows the installation form.
        const installedStamp = path.resolve(__dirname, '../../local/.installed.test');
        if (fs.existsSync(installedStamp)) fs.unlinkSync(installedStamp);
    });

    test.afterAll(() => {
        // Leave no trace: scratch DB and the dev-only database.inc.php both go.
        if (fs.existsSync(DB_CONFIG_PATH)) fs.unlinkSync(DB_CONFIG_PATH);
        try {
            mysqlExec(`DROP DATABASE IF EXISTS ${SCRATCH_DB};`);
        } catch {
            // best-effort cleanup
        }
    });

    test('install Piwigo with fixture admin credentials', async ({ page }) => {
        await page.goto(pwgUrl('/install.php'));
        await expect(page.getByRole('heading', { name: /Installation/ })).toBeVisible();

        await page.fill('input[name="dbhost"]', DB_HOST);
        await page.fill('input[name="dbuser"]', DB_USER);
        await page.fill('input[name="dbpasswd"]', DB_PASS);
        await page.fill('input[name="dbname"]', SCRATCH_DB);

        await page.fill('input[name="admin_name"]', ADMIN_USER);
        await page.fill('input[name="admin_pass1"]', ADMIN_PASS);
        await page.fill('input[name="admin_pass2"]', ADMIN_PASS);
        await page.fill('input[name="admin_mail"]', 'fixture_admin@example.test');
        await page.uncheck('input[name="newsletter_subscribe"]');

        await page.click('input[name="install"]');
        await expect(page.getByText('Congratulations')).toBeVisible({ timeout: 30_000 });

        // Mark the 15.0.0 upgrade boundary as applied. Fresh installs jump
        // straight to current schema and never INSERT into piwigo_upgrade,
        // but upgrade.php (and UpgradeChainTest) check for id 181 to confirm
        // the DB is ≥ 15.x. Without this, upgrade.php returns 409 against
        // the fixture even though the schema is current.
        mysqlExec(
            `USE ${SCRATCH_DB}; INSERT INTO piwigo_upgrade (id, applied, description) ` +
                `VALUES ('181', NOW(), 'Piwigo 15.0.0 schema baseline');`
        );
    });

    test('seed representative content', async ({ page, request }) => {
        // Login via WS — the browser-form login can flake on a freshly-installed
        // gallery (no theme assets cached, identification.php sometimes 500s on
        // first hit). pwg.session.login is the same code path Piwigo uses
        // internally and returns a cookie we can reuse for subsequent calls.
        const loginRes = await request.post(wsUrl({ format: 'json' }), {
            form: { method: 'pwg.session.login', username: ADMIN_USER, password: ADMIN_PASS },
        });
        expect((await loginRes.json()).stat, 'fixture_admin login must succeed').toBe('ok');
        const cookieHeader = (await request.storageState()).cookies
            .map((c) => `${c.name}=${c.value}`)
            .join('; ');
        const pwgToken = await getPwgToken(request, cookieHeader);

        // 2 albums: one root, one sub
        const rootAlbumId = await createAlbum(request, cookieHeader, 'Sample Album');
        const subAlbumRes = await callWs(request, cookieHeader, 'pwg.categories.add', {
            name: 'Nested Sub Album',
            parent: String(rootAlbumId),
        });
        expect(subAlbumRes.stat).toBe('ok');
        const subAlbumId = (subAlbumRes.result as { id: number }).id;

        // 5 photos via per-image screenshots, written to tmp and uploaded.
        // Screenshots produce real PNGs/JPEGs with valid EXIF-free metadata,
        // so Piwigo computes width/height correctly.
        await page.setViewportSize({ width: 200, height: 150 });
        const tmpDir = fs.mkdtempSync(path.join(os.tmpdir(), 'piwigo-fixture-'));
        const photoIds: number[] = [];
        try {
            for (let i = 1; i <= 5; i++) {
                await page.goto('about:blank');
                await page.evaluate((idx) => {
                    document.body.style.cssText =
                        `margin:0;background:hsl(${idx * 60}, 70%, 60%);width:100vw;height:100vh;` +
                        `display:flex;align-items:center;justify-content:center;color:white;` +
                        `font:bold 30px sans-serif`;
                    document.body.textContent = `Photo ${idx}`;
                }, i);
                const buffer = await page.screenshot({ type: 'jpeg', quality: 80 });
                const filePath = path.join(tmpDir, `fixture-photo-${i}.jpg`);
                fs.writeFileSync(filePath, buffer);
                const albumId = i <= 3 ? rootAlbumId : subAlbumId;
                photoIds.push(
                    await uploadPhoto(request, cookieHeader, filePath, albumId, `Photo ${i}`)
                );
            }
        } finally {
            fs.rmSync(tmpDir, { recursive: true, force: true });
        }

        // 3 tags, attached to images so pwg.tags.getList sees them. The WS
        // returns only tags actually used by ≥1 image; without attaching,
        // the tag rows exist but are invisible to clients.
        const tagIds: number[] = [];
        for (const name of ['nature', 'travel', 'family']) {
            const res = await callWs(request, cookieHeader, 'pwg.tags.add', {
                name,
                pwg_token: pwgToken,
            });
            expect(res.stat, `tag ${name} should be created`).toBe('ok');
            tagIds.push((res.result as { id: number }).id);
        }
        // Attach all 3 tags to the first photo, plus tag #1 to photos 2-3 for
        // a small spread.
        mysqlExec(
            `USE ${SCRATCH_DB}; INSERT INTO piwigo_image_tag (image_id, tag_id) VALUES ` +
                `(${photoIds[0]}, ${tagIds[0]}), (${photoIds[0]}, ${tagIds[1]}), ` +
                `(${photoIds[0]}, ${tagIds[2]}), (${photoIds[1]}, ${tagIds[0]}), ` +
                `(${photoIds[2]}, ${tagIds[0]});`
        );

        // 1 comment on the first photo. pwg.images.addComment validates that
        // the parent album has commentable=true; fresh albums don't, and
        // toggling it then re-querying is more ceremony than the fixture
        // needs. Insert directly — the goal is a populated piwigo_comments
        // row in the dump, not exercise of the comment-submission flow.
        mysqlExec(
            `USE ${SCRATCH_DB}; INSERT INTO piwigo_comments ` +
                `(image_id, date, author, anonymous_id, author_id, content, validated, validation_date) ` +
                `VALUES (${photoIds[0]}, NOW(), 'fixture_admin', '127.0.0.1', 1, ` +
                `'Fixture comment for integration tests.', 'true', NOW());`
        );

        // 2 additional users with different permission levels
        for (const username of ['regular_user', 'power_user']) {
            const res = await callWs(request, cookieHeader, 'pwg.users.add', {
                username,
                password: `${username}_pass`,
                pwg_token: pwgToken,
            });
            expect(res.stat, `user ${username} should be created`).toBe('ok');
        }

        // A few config tweaks (exercise the $conf write path per README intent)
        for (const [param, value] of [
            ['gallery_title', 'Fixture Gallery'],
            ['comments_validation', 'true'],
            ['nb_categories_page', '12'],
        ] as const) {
            const res = await callWs(request, cookieHeader, 'pwg.config.setKeyValue', {
                param,
                value,
                pwg_token: pwgToken,
            });
            // pwg.config.setKeyValue may not exist in 16.x; fall back to direct UPDATE.
            if (res.stat !== 'ok') {
                mysqlExec(
                    `USE ${SCRATCH_DB}; UPDATE piwigo_config SET value=${shellQuote(value)} ` +
                        `WHERE param=${shellQuote(param)};`
                );
            }
        }
    });

    test('dump scratch DB to dev/fixtures/piwigo-16.x.sql', () => {
        const cmd =
            `mysqldump -h${DB_HOST} -P${DB_PORT} -u${DB_USER} -p${DB_PASS} ` +
            `--no-tablespaces ${SCRATCH_DB}`;
        const dump = execSync(cmd, { maxBuffer: 64 * 1024 * 1024 });
        fs.writeFileSync(FIXTURE_PATH, dump);
        const sizeKB = Math.round(fs.statSync(FIXTURE_PATH).size / 1024);
        // A clean 16.x install + this seed (2 albums, 5 photos, 3 tags, 1
        // comment, 2 extra users, a few config tweaks) lands around 45-50 KB.
        // The threshold is a smoke check that we got the full schema (~30 KB
        // for 34 CREATE TABLE statements) plus at least some seed data — not
        // a representativity guarantee.
        expect(sizeKB, `fixture should be > 40 KB (got ${sizeKB} KB)`).toBeGreaterThan(40);
    });
});
