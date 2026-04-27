// Reset for E2E: drop+recreate the DB and remove local/config/database.inc.php
// so install.php sees a clean slate (database.inc.php defines PHPWG_INSTALLED).
const { exec } = require('child_process');
const { promisify } = require('util');
const fs = require('fs');
const path = require('path');
const execAsync = promisify(exec);

async function globalSetup() {
    const host = process.env.PIWIGO_DB_HOST || '127.0.0.1';
    const port = process.env.PIWIGO_DB_PORT || '3306';
    const user = process.env.PIWIGO_DB_USER || 'piwigo';
    const pass = process.env.PIWIGO_DB_PASSWORD || 'piwigo';
    const db = process.env.PIWIGO_DB_BASE || 'piwigo';

    // Remove database.inc.php so install.php doesn't see PHPWG_INSTALLED
    const dbConfig = path.resolve(__dirname, '../../local/config/database.inc.php');
    if (fs.existsSync(dbConfig)) {
        fs.unlinkSync(dbConfig);
    }

    await execAsync(
        `mysql -h${host} -P${port} -u${user} -p${pass} -e "DROP DATABASE IF EXISTS ${db}; CREATE DATABASE ${db} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"`
    );
}

module.exports = globalSetup;
