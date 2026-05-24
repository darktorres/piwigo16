import { chromium } from 'playwright';
import * as fs from 'fs';
import * as path from 'path';
import { PNG } from 'pngjs';
import pixelmatch from 'pixelmatch';

const BASE_URL = (process.env['BASE_URL'] ?? 'http://localhost/piwigo16').replace(/\/+$/, '');
const OUTPUT_DIR = process.env['OUTPUT_DIR'] ?? 'screenshots/head';
const MODE = process.env['MODE'] ?? 'capture'; // 'capture' or 'diff'

const USERNAME = 'darktorres';
const PASSWORD = '1234';

const IS_BASELINE = BASE_URL.includes('baseline');

const ROUTES: ReadonlyArray<{ name: string; path: string; baselinePath?: string; baselineSkip?: boolean; needsAuth: boolean }> = [
    // ── Frontend (public) ────────────────────────────────────────────────
    { name: 'gallery-home', path: '/index.php', needsAuth: false },
    { name: 'identification', path: '/index.php?/identification', needsAuth: false },
    { name: 'register', path: '/index.php?/register', needsAuth: false },
    { name: 'password', path: '/index.php?/password', needsAuth: false },
    { name: 'about', path: '/index.php?/about', needsAuth: false },
    { name: 'tags', path: '/index.php?/tags', needsAuth: false },
    { name: 'search', path: '/index.php?/search', needsAuth: false },
    { name: 'comments', path: '/index.php?/comments', needsAuth: false },
    { name: 'notification', path: '/index.php?/notification', needsAuth: false },
    { name: 'category-1', path: '/index.php?/category/1', needsAuth: false },
    { name: 'category-2', path: '/index.php?/category/2', needsAuth: false },
    { name: 'picture-1', path: '/index.php?/picture/1/category/1', needsAuth: false },
    { name: 'nbm', path: '/index.php?/nbm', needsAuth: false },
    { name: 'popuphelp', path: '/index.php?/popuphelp&page=search', needsAuth: false },
    { name: 'random', path: '/index.php?/random', needsAuth: false },
    { name: 'tags-filtered', path: '/index.php?/tags/1-landscape', baselinePath: '/index.php?/tags/1-nature', needsAuth: false },

    // ── Frontend (auth required) ─────────────────────────────────────────
    { name: 'favorites', path: '/index.php?/favorites', needsAuth: true },
    { name: 'most-visited', path: '/index.php?/most_visited', baselinePath: '/index.php?/most-visited', needsAuth: true },
    { name: 'recent-pics', path: '/index.php?/recent_pics', baselinePath: '/index.php?/recent', needsAuth: true },
    { name: 'recent-cats', path: '/index.php?/recent_cats', baselinePath: '/index.php?/recent-albums', needsAuth: true },
    { name: 'best-rated', path: '/index.php?/best_rated', baselinePath: '/index.php?/best-rated', needsAuth: true },
    { name: 'profile', path: '/index.php?/profile', needsAuth: true },
    { name: 'calendar', path: '/index.php?/created-monthly-calendar', baselineSkip: true, needsAuth: false },

    // ── Admin — Albums ───────────────────────────────────────────────────
    { name: 'admin-intro', path: '/index.php?/admin&page=intro', needsAuth: true },
    { name: 'admin-albums', path: '/index.php?/admin&page=albums', needsAuth: true },
    { name: 'admin-album', path: '/index.php?/admin&page=album&cat_id=1', needsAuth: true },
    { name: 'admin-album-perms', path: '/index.php?/admin&page=album&cat_id=1&tab=permissions', needsAuth: true },
    { name: 'admin-cat-list', path: '/index.php?/admin&page=cat_list', needsAuth: true },
    { name: 'admin-cat-options', path: '/index.php?/admin&page=cat_options', needsAuth: true },
    { name: 'admin-album-notif', path: '/index.php?/admin&page=album_notification&cat_id=1', needsAuth: true },

    // ── Admin — Photos ───────────────────────────────────────────────────
    { name: 'admin-photos-add', path: '/index.php?/admin&page=photos_add', needsAuth: true },
    { name: 'admin-photos-add-ftp', path: '/index.php?/admin&page=photos_add&section=ftp', needsAuth: true },
    { name: 'admin-photos-add-apps', path: '/index.php?/admin&page=photos_add&section=applications', needsAuth: true },
    { name: 'admin-photo-editor', path: '/index.php?/admin&page=photo&image_id=1', needsAuth: true },
    { name: 'admin-picture-coi', path: '/index.php?/admin&page=picture_coi&image_id=1', needsAuth: true },
    { name: 'admin-batch', path: '/index.php?/admin&page=batch_manager', needsAuth: true },
    { name: 'admin-batch-global', path: '/index.php?/admin&page=batch_manager_global', needsAuth: true },
    { name: 'admin-element-ranks', path: '/index.php?/admin&page=element_set_ranks&cat_id=1', needsAuth: true },
    { name: 'admin-queue', path: '/index.php?/admin&page=queue', needsAuth: true },

    // ── Admin — Users ────────────────────────────────────────────────────
    { name: 'admin-users', path: '/index.php?/admin&page=user_list', needsAuth: true },
    { name: 'admin-user-activity', path: '/index.php?/admin&page=user_activity', needsAuth: true },
    { name: 'admin-groups', path: '/index.php?/admin&page=group_list', needsAuth: true },
    { name: 'admin-group-perm', path: '/index.php?/admin&page=group_perm&group_id=1', needsAuth: true },
    { name: 'admin-user-perm', path: '/index.php?/admin&page=user_perm&user_id=1', needsAuth: true },

    // ── Admin — Extensions ───────────────────────────────────────────────
    { name: 'admin-plugins', path: '/index.php?/admin&page=plugins_installed', needsAuth: true },
    { name: 'admin-plugins-new', path: '/index.php?/admin&page=plugins_new', needsAuth: true },
    { name: 'admin-themes', path: '/index.php?/admin&page=themes_installed', needsAuth: true },
    { name: 'admin-themes-new', path: '/index.php?/admin&page=themes_new', needsAuth: true },
    { name: 'admin-themes-std', path: '/index.php?/admin&page=themes_standard_pages', needsAuth: true },
    { name: 'admin-languages', path: '/index.php?/admin&page=languages_installed', needsAuth: true },
    { name: 'admin-languages-new', path: '/index.php?/admin&page=languages_new', needsAuth: true },
    { name: 'admin-updates', path: '/index.php?/admin&page=updates', needsAuth: true },
    { name: 'admin-updates-ext', path: '/index.php?/admin&page=updates_ext', needsAuth: true },
    { name: 'admin-updates-pwg', path: '/index.php?/admin&page=updates_pwg', needsAuth: true },
    { name: 'admin-extend-tpl', path: '/index.php?/admin&page=extend_for_templates', needsAuth: true },

    // ── Admin — Configuration ────────────────────────────────────────────
    { name: 'admin-config', path: '/index.php?/admin&page=configuration', needsAuth: true },

    // ── Admin — Tools / Maintenance ──────────────────────────────────────
    { name: 'admin-maintenance', path: '/index.php?/admin&page=maintenance', needsAuth: true },
    { name: 'admin-maintenance-env', path: '/index.php?/admin&page=maintenance_env', needsAuth: true },
    { name: 'admin-maintenance-sys', path: '/index.php?/admin&page=maintenance_sys', needsAuth: true },
    { name: 'admin-history', path: '/index.php?/admin&page=history', needsAuth: true },
    { name: 'admin-stats', path: '/index.php?/admin&page=stats', needsAuth: true },
    { name: 'admin-site-manager', path: '/index.php?/admin&page=site_manager', needsAuth: true },
    { name: 'admin-site-update', path: '/index.php?/admin&page=site_update&site=1', needsAuth: true },

    // ── Admin — Misc ─────────────────────────────────────────────────────
    { name: 'admin-dashboard', path: '/index.php?/admin', needsAuth: true },
    { name: 'admin-notif-mail', path: '/index.php?/admin&page=notification_by_mail', needsAuth: true },
    { name: 'admin-permalinks', path: '/index.php?/admin&page=permalinks', needsAuth: true },
    { name: 'admin-tags', path: '/index.php?/admin&page=tags', needsAuth: true },
    { name: 'admin-comments', path: '/index.php?/admin&page=comments', needsAuth: true },
    { name: 'admin-rating', path: '/index.php?/admin&page=rating', needsAuth: true },
    { name: 'admin-rating-user', path: '/index.php?/admin&page=rating_user', needsAuth: true },
    { name: 'admin-menubar', path: '/index.php?/admin&page=menubar', needsAuth: true },
    { name: 'admin-help', path: '/index.php?/admin&page=help', needsAuth: true },
];

function url(routePath: string): string {
    return `${BASE_URL}${routePath}`;
}

async function capture(): Promise<void> {
    fs.mkdirSync(OUTPUT_DIR, { recursive: true });

    const browser = await chromium.launch();
    const context = await browser.newContext({
        viewport: { width: 1280, height: 720 },
    });
    const page = await context.newPage();

    // Login
    console.log('Logging in...');
    await page.goto(url('/index.php?/identification'), { waitUntil: 'networkidle', timeout: 15_000 });
    await page.locator('input[name="username"]').first().fill(USERNAME);
    await page.locator('input[name="password"]').first().fill(PASSWORD);
    await page.locator('input[name="login"]').first().click();
    await page.waitForURL((u) => !u.href.includes('/identification'), { timeout: 10_000 });
    console.log('Logged in.');

    for (const route of ROUTES) {
        if (IS_BASELINE && route.baselineSkip) {
            console.log(`  ${route.name} → SKIP (not available at baseline)`);
            continue;
        }
        const file = path.join(OUTPUT_DIR, `${route.name}.png`);
        console.log(`  ${route.name} → ${file}`);
        try {
            const routePath = (IS_BASELINE && route.baselinePath) ? route.baselinePath : route.path;
            const resp = await page.goto(url(routePath), {
                waitUntil: 'networkidle',
                timeout: 5_000,
            });
            if (!resp) {
                await browser.close();
                throw new Error(`${route.name}: no response`);
            }
            if (resp.status() >= 400) {
                await browser.close();
                throw new Error(`${route.name}: HTTP ${resp.status()} — ${resp.url()}`);
            }
            await page.waitForTimeout(500);
            await page.screenshot({ path: file, fullPage: true });
        } catch (err) {
            if (err instanceof Error && err.message.startsWith(route.name)) throw err;
            await browser.close();
            throw new Error(`${route.name}: ${err instanceof Error ? err.message : err}`);
        }
    }

    await browser.close();
    console.log(`Done. Screenshots saved to ${OUTPUT_DIR}/`);
}

async function diff(): Promise<void> {
    const baselineDir = 'screenshots/baseline';
    const headDir = 'screenshots/head';
    const diffDir = 'screenshots/diff';
    fs.mkdirSync(diffDir, { recursive: true });

    const files = fs.readdirSync(baselineDir).filter((f) => f.endsWith('.png'));
    let total = 0;
    let changed = 0;

    for (const file of files) {
        const baselinePath = path.join(baselineDir, file);
        const headPath = path.join(headDir, file);
        const diffPath = path.join(diffDir, file);

        if (!fs.existsSync(headPath)) {
            console.warn(`  MISSING in head: ${file}`);
            continue;
        }

        const baselineImg = PNG.sync.read(fs.readFileSync(baselinePath));
        const headImg = PNG.sync.read(fs.readFileSync(headPath));

        const width = Math.max(baselineImg.width, headImg.width);
        const height = Math.max(baselineImg.height, headImg.height);

        // Pad smaller image to match dimensions
        const padded = (img: PNG, w: number, h: number): Buffer => {
            if (img.width === w && img.height === h) return img.data;
            const buf = Buffer.alloc(w * h * 4, 0);
            for (let y = 0; y < img.height; y++) {
                img.data.copy(buf, y * w * 4, y * img.width * 4, y * img.width * 4 + img.width * 4);
            }
            return buf;
        };

        const baselineData = padded(baselineImg, width, height);
        const headData = padded(headImg, width, height);
        const diffPng = new PNG({ width, height });

        const mismatch = pixelmatch(baselineData, headData, diffPng.data, width, height, {
            threshold: 0.1,
        });

        total++;
        if (mismatch > 0) {
            changed++;
            const pct = ((mismatch / (width * height)) * 100).toFixed(2);
            console.log(`  CHANGED: ${file} — ${mismatch} pixels (${pct}%)`);
            fs.writeFileSync(diffPath, PNG.sync.write(diffPng));
        } else {
            console.log(`  OK: ${file}`);
        }
    }

    console.log(`\n${changed}/${total} pages have visual differences.`);
    if (changed > 0) {
        console.log(`Diff images saved to ${diffDir}/`);
    }
}

if (MODE === 'diff') {
    diff();
} else {
    capture();
}
