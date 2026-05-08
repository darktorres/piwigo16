import { exec } from 'child_process';
import { promisify } from 'util';
import { fileURLToPath } from 'url';
import * as fs from 'fs';
import * as path from 'path';
import { TEST_PHOTOS } from './helpers/test-data.js';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const REPO_ROOT = path.resolve(__dirname, '../..');
const FIXTURE = path.join(REPO_ROOT, 'dev', 'fixtures', 'piwigo-16.x.sql');

const execAsync = promisify(exec);

/**
 * Loads `.env.test` (test runtime config) into process.env so the
 * Playwright workers see PIWIGO_DB_* and PIWIGO_BASE_URL. There is no
 * fallback to `.env`: the test environment is fully self-contained.
 */
function loadEnvTest(): void {
    const envFile = path.join(REPO_ROOT, '.env.test');
    if (!fs.existsSync(envFile)) {
        throw new Error(
            `tests/e2e/global-setup.ts: missing ${envFile}\n` +
                `Create it from .env.example and fill in test DB credentials.`
        );
    }
    for (const raw of fs.readFileSync(envFile, 'utf8').split(/\r?\n/)) {
        const line = raw.trimStart();
        if (line === '' || line.startsWith('#') || !line.includes('=')) continue;
        const idx = line.indexOf('=');
        const k = line.slice(0, idx).trim();
        const v = line
            .slice(idx + 1)
            .trim()
            .replace(/^["']|["']$/g, '');
        // Shell-set vars win over .env.test values — same precedence as the
        // PHP loader's immutable-mode behaviour.
        if (k && process.env[k] === undefined) process.env[k] = v;
    }
}

/**
 * Bootstraps the shared test install:
 *   1. Drop & recreate the test DB (fail-safe against dropping `piwigo`).
 *   2. Load the SQL fixture — same one the PHPUnit Integration suite uses,
 *      so both suites observe the same install state and credentials
 *      (see tests/e2e/helpers/test-data.ts).
 *   3. Touch local/.installed.test so InstallSentinel reports installed
 *      and the runtime serves requests instead of redirecting to install.php.
 *
 * Idempotent: running this between suites or back-to-back rebuilds the
 * install to the same shape every time. Set SKIP_GLOBAL_SETUP=1 to skip
 * the rebuild when iterating on a single spec against the existing install.
 */
async function globalSetup(): Promise<void> {
    loadEnvTest();

    if (process.env.SKIP_GLOBAL_SETUP === '1' || process.env.SKIP_GLOBAL_SETUP === 'true') {
        return;
    }

    const host = process.env.PIWIGO_DB_HOST ?? '127.0.0.1';
    const port = process.env.PIWIGO_DB_PORT ?? '3306';
    const user = process.env.PIWIGO_DB_USER ?? '';
    const pass = process.env.PIWIGO_DB_PASSWORD ?? '';
    const db = process.env.PIWIGO_DB_BASE ?? '';

    if (!db || db === 'piwigo') {
        throw new Error(
            `globalSetup refuses to drop a DB named '${db}'. Set PIWIGO_DB_BASE in .env.test ` +
                `to a throw-away test database (typical: piwigo_test).`
        );
    }

    if (!fs.existsSync(FIXTURE)) {
        throw new Error(`Test fixture missing: ${FIXTURE}`);
    }

    // Drop & recreate the test DB.
    await execAsync(
        `mysql -h${host} -P${port} -u${user} -p${pass} -e "DROP DATABASE IF EXISTS ${db}; CREATE DATABASE ${db} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"`
    );

    // Load the fixture into the freshly-created DB.
    await execAsync(
        `mysql -h${host} -P${port} -u${user} -p${pass} ${db} < "${FIXTURE.replace(/\\/g, '/')}"`,
        { maxBuffer: 64 * 1024 * 1024 }
    );

    // Generate test images in galleries/Wallpapers/ using PHP GD.
    const wallpapersDir = path.join(REPO_ROOT, 'galleries', 'Wallpapers');
    fs.mkdirSync(wallpapersDir, { recursive: true });
    const colors = ['220,50,50', '50,180,80', '50,100,220', '230,200,50', '150,60,200', '200,120,50', '50,180,180', '180,50,180', '100,180,50', '50,50,180'];
    const phpScript = '<?php\n' + TEST_PHOTOS.map((p, i) => {
        const [r, g, b] = colors[i % colors.length].split(',');
        const label = path.basename(p, '.jpg');
        return `$img = imagecreatetruecolor(800, 600);
imagefill($img, 0, 0, imagecolorallocate($img, ${r}, ${g}, ${b}));
imagestring($img, 5, 350, 280, "${label}", imagecolorallocate($img, 255, 255, 255));
imagejpeg($img, "${p.replace(/\\/g, '/')}", 90);
imagedestroy($img);`;
    }).join('\n');
    const tmpScript = path.join(REPO_ROOT, '_data', 'gen-test-images.php');
    fs.writeFileSync(tmpScript, phpScript);
    await execAsync(`php ${tmpScript}`);
    fs.unlinkSync(tmpScript);

    // Mark the test install as installed. InstallSentinel checks
    // local/.installed.test in test mode (TestMode); without this the
    // first request would 302 to install.php.
    const localDir = path.join(REPO_ROOT, 'local');
    if (!fs.existsSync(localDir)) {
        fs.mkdirSync(localDir, { recursive: true });
    }
    const testStamp = path.join(localDir, '.installed.test');
    fs.closeSync(fs.openSync(testStamp, 'w'));
}

export default globalSetup;
