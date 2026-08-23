<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Admin\Install;

use Piwigo\Core\Env;
use Piwigo\Core\Paths;
use Piwigo\Db\DbCredentials;

/**
 * Writes .env (or .env.test in test mode) with the submitted DB
 * credentials -- extracted out of InstallWizard::performInstall()'s own
 * former step-2 block.
 */
final class InstallEnvWriter
{
    public function __construct(
        private readonly Paths $paths,
        private readonly DbCredentials $dbCredentials,
    ) {}

    /**
     * @return string|null an error message on failure, or null on success
     */
    public function write(string $dbhost, string $dbuser, string $dbpasswd, string $dbname, string $dblayer, ?int $dbport): ?string
    {
        // Write .env (or .env.test in test mode) with DB credentials — atomic
        // rename, preserving any line this block doesn't manage (e.g. a
        // re-install's PIWIGO_TEST_NOW — see Piwigo\Core\Env::now()).
        $env_file = $this->paths->root . Env::testModeEnvFile();
        $env_values = [
            'PIWIGO_DB_HOST' => $dbhost,
            'PIWIGO_DB_USER' => $dbuser,
            'PIWIGO_DB_PASSWORD' => $dbpasswd,
            'PIWIGO_DB_BASE' => $dbname,
            'PIWIGO_DB_DRIVER' => $dblayer,
        ];
        // Only written when the operator actually chose a non-default port
        // (the driver's own default applies otherwise, same as before this
        // field existed) -- mergeIntoEnvFile()'s own $values shape is
        // array<string, string>, so a null port is omitted rather than passed.
        if ($dbport !== null) {
            $env_values['PIWIGO_DB_PORT'] = (string) $dbport;
        }
        // In test mode, also record the base URL so e2e runners know where to connect.
        if (Env::testModeIsActive()) {
            $scheme = (! in_array($_SERVER['HTTPS'] ?? null, [null, false, 0, '0', ''], true) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
            $host = is_string($host) ? $host : 'localhost';
            $script = $_SERVER['SCRIPT_NAME'] ?? '';
            $script = is_string($script) ? $script : '';
            $base_url = rtrim($scheme . '://' . $host . dirname($script), '/');
            if ($base_url !== '') {
                $env_values['PIWIGO_BASE_URL'] = $base_url;
            }
        }

        $error = null;
        if (! Env::mergeIntoEnvFile($env_file, $env_values)) {
            $error = 'Could not write ' . $env_file . ' — check filesystem permissions.';
        }
        // Runs unconditionally, on both the success and failure path above --
        // DbCredentials::reload() re-reads from process env vars (already
        // seeded by InstallWizard::boot()'s own DbCredentials::seed() call),
        // independent of whether the file write itself succeeded.
        $this->dbCredentials->reload();

        return $error;
    }
}
