import { exec } from 'child_process';
import { promisify } from 'util';

const execAsync = promisify(exec);

async function globalSetup(): Promise<void> {
    const host = process.env.PIWIGO_DB_HOST ?? '127.0.0.1';
    const user = process.env.PIWIGO_DB_USER ?? 'piwigo';
    const pass = process.env.PIWIGO_DB_PASSWORD ?? 'piwigo';
    const db = process.env.PIWIGO_DB_BASE ?? 'piwigo';

    await execAsync(
        `mysql -h${host} -u${user} -p${pass} -e "DROP DATABASE IF EXISTS \`${db}\`; CREATE DATABASE \`${db}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"`
    );
}

export default globalSetup;
