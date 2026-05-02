import { exec } from 'child_process';
import { promisify } from 'util';
import { fileURLToPath } from 'url';
import * as fs from 'fs';
import * as path from 'path';

const __dirname = path.dirname(fileURLToPath(import.meta.url));

const execAsync = promisify(exec);

// Load .env.local if present, mirroring tests/bootstrap.php so e2e and
// integration suites share one credentials file. Shell-set vars win.
function loadEnvLocal(): void {
    const envFile = path.resolve(__dirname, '../../.env.local');
    if (!fs.existsSync(envFile)) return;
    for (const raw of fs.readFileSync(envFile, 'utf8').split(/\r?\n/)) {
        const line = raw.trimStart();
        if (line === '' || line.startsWith('#') || !line.includes('=')) continue;
        const idx = line.indexOf('=');
        const k = line.slice(0, idx).trim();
        const v = line
            .slice(idx + 1)
            .trim()
            .replace(/^["']|["']$/g, '');
        if (k && process.env[k] === undefined) process.env[k] = v;
    }
}

async function globalSetup(): Promise<void> {
    loadEnvLocal();
    // Iterating on individual specs against an existing install is much
    // faster when we skip the destructive DB reset. Set SKIP_GLOBAL_SETUP=1.
    if (process.env.SKIP_GLOBAL_SETUP === '1' || process.env.SKIP_GLOBAL_SETUP === 'true') {
        return;
    }

    const host = process.env.PIWIGO_DB_HOST ?? '127.0.0.1';
    const port = process.env.PIWIGO_DB_PORT ?? '3306';
    const user = process.env.PIWIGO_DB_USER ?? '';
    const pass = process.env.PIWIGO_DB_PASSWORD ?? '';
    const db = process.env.PIWIGO_DB_BASE ?? '';

    const dbConfig = path.resolve(__dirname, '../../local/config/database.inc.php');
    if (fs.existsSync(dbConfig)) {
        fs.unlinkSync(dbConfig);
    }

    await execAsync(
        `mysql -h${host} -P${port} -u${user} -p${pass} -e "DROP DATABASE IF EXISTS ${db}; CREATE DATABASE ${db} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"`
    );
}

export default globalSetup;
