import { exec } from 'child_process';
import { promisify } from 'util';
import * as fs from 'fs';
import * as path from 'path';

const execAsync = promisify(exec);

async function globalSetup(): Promise<void> {
    const host = process.env.PIWIGO_DB_HOST || '127.0.0.1';
    const port = process.env.PIWIGO_DB_PORT || '3306';
    const user = process.env.PIWIGO_DB_USER || 'piwigo';
    const pass = process.env.PIWIGO_DB_PASSWORD || 'piwigo';
    const db = process.env.PIWIGO_DB_BASE || 'piwigo';

    const dbConfig = path.resolve(__dirname, '../../local/config/database.inc.php');
    if (fs.existsSync(dbConfig)) {
        fs.unlinkSync(dbConfig);
    }

    await execAsync(
        `mysql -h${host} -P${port} -u${user} -p${pass} -e "DROP DATABASE IF EXISTS ${db}; CREATE DATABASE ${db} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"`
    );
}

export default globalSetup;
