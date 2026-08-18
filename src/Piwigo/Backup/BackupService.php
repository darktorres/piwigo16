<?php

declare(strict_types=1);

namespace Piwigo\Backup;

use Doctrine\DBAL\DriverManager;
use InvalidArgumentException;
use Piwigo\Backup\Projection\BackupManifest;
use Piwigo\Core\ShutdownHandler;
use Piwigo\Db\DbCredentials;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Real DB + `galleries/` backup/restore, shelling out to
 * mysqldump/mysql/pg_dump/psql/tar via env-var credentials
 * (Piwigo\Db\DbCredentials) -- the same CLI-safe mechanism
 * tools/restore-drill.sh and tools/reimport-fixture.sh already use,
 * deliberately not the legacy include/common.inc.php bootstrap chain
 * (untested under CLI SAPI -- that chain no longer exists at all today,
 * see docs/REFERENCE.md's Architecture section for the real
 * CliBootstrap-based path this class uses instead). SQLite's own dump/
 * restore ({@see dumpDatabase()}/{@see restoreDatabase()}) needs no
 * subprocess at all -- `VACUUM INTO` and a plain file copy, both real
 * DBAL/PHP calls.
 *
 * `local/config/config.inc.php` is included when present (production
 * deployments) but this dev checkout has none. No PHPWG_VERSION in the manifest: include/constants.php
 * itself needs `$conf` populated first to read it, which would drag in
 * the same legacy-bootstrap risk this class exists to avoid.
 *
 * Every long-running step here is wrapped so a SIGTERM mid-operation still
 * cleans up its temp directory: `finally` covers normal completion/thrown
 * errors, ShutdownHandler::register() covers the signal-interrupted case
 * (pcntl's handler calls exit() directly, bypassing the awaiting `finally`
 * entirely) -- the two paths are disjoint, not redundant.
 */
final readonly class BackupService
{
    private string $repoRoot;

    private string $backupsDir;

    public function __construct()
    {
        $this->repoRoot = dirname(__DIR__, 3);
        $this->backupsDir = $this->repoRoot . '/_data/backups';
    }

    public function create(): string
    {
        $credentials = DbCredentials::fromEnv();
        $workDir = $this->makeTempDir('piwigo-backup-create-');
        $cleanup = function () use ($workDir): void {
            $this->removeDir($workDir);
        };
        ShutdownHandler::register($cleanup);

        try {
            $this->dumpDatabase($credentials, $workDir . '/db.sql');

            $included = ['db.sql'];

            if (is_dir($this->repoRoot . '/galleries')) {
                $this->runProcess(
                    ['tar', 'cf', $workDir . '/galleries.tar', '-C', $this->repoRoot, 'galleries'],
                    'tar galleries/'
                );
                $included[] = 'galleries.tar';
            }

            $localConfig = $this->repoRoot . '/local/config/config.inc.php';
            if (is_file($localConfig)) {
                copy($localConfig, $workDir . '/config.inc.php');
                $included[] = 'config.inc.php';
            }

            $manifest = [
                'created_at' => gmdate('c'),
                'included' => $included,
            ];
            file_put_contents(
                $workDir . '/manifest.json',
                json_encode($manifest, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)
            );

            if (! is_dir($this->backupsDir)) {
                mkdir($this->backupsDir, 0o775, true);
            }

            $archivePath = $this->backupsDir . '/piwigo-backup-' . gmdate('Ymd\THis\Z') . '.tar.gz';
            $this->runProcess(
                ['tar', 'czf', $archivePath, '-C', $workDir, 'manifest.json', ...$included],
                'create backup archive'
            );

            return $archivePath;
        } finally {
            $this->removeDir($workDir);
        }
    }

    public function restore(string $archivePath, string $targetDatabase): void
    {
        if (! is_file($archivePath)) {
            throw new InvalidArgumentException("Backup archive not found: {$archivePath}");
        }

        $workDir = $this->makeTempDir('piwigo-backup-restore-');
        $cleanup = function () use ($workDir): void {
            $this->removeDir($workDir);
        };
        ShutdownHandler::register($cleanup);

        try {
            $this->runProcess(['tar', 'xzf', $archivePath, '-C', $workDir], 'extract backup archive');

            $manifest = $this->readManifest($workDir, $archivePath);

            $dbDump = $workDir . '/db.sql';
            if (! is_file($dbDump)) {
                throw new RuntimeException("Invalid backup archive: missing db.sql in {$archivePath}");
            }
            $this->restoreDatabase(DbCredentials::fromEnv(), $targetDatabase, $dbDump);

            $galleriesTar = $workDir . '/galleries.tar';
            if (is_file($galleriesTar) && in_array('galleries.tar', $manifest->included, true)) {
                $this->runProcess(['tar', 'xf', $galleriesTar, '-C', $this->repoRoot], 'restore galleries/');
            }
        } finally {
            $this->removeDir($workDir);
        }
    }

    private function readManifest(string $workDir, string $archivePath): BackupManifest
    {
        $manifestPath = $workDir . '/manifest.json';
        if (! is_file($manifestPath)) {
            throw new RuntimeException("Invalid backup archive: missing manifest.json in {$archivePath}");
        }

        $decoded = json_decode((string) file_get_contents($manifestPath), true, flags: JSON_THROW_ON_ERROR);
        if (
            ! is_array($decoded)
            || ! is_string($decoded['created_at'] ?? null)
            || ! is_array($decoded['included'] ?? null)
        ) {
            throw new RuntimeException("Invalid backup archive: malformed manifest.json in {$archivePath}");
        }

        /** @var list<string> $included */
        $included = array_values(array_filter($decoded['included'], is_string(...)));

        return new BackupManifest($decoded['created_at'], $included);
    }

    /**
     * SQLite's real backup story is fundamentally different from
     * mysqldump/pg_dump -- not a third dump-client invocation, since
     * there's no server process to shell out to at all. `VACUUM INTO`
     * (verified live before writing this: a safe hot-copy, doesn't risk
     * corruption from copying the file mid-write the way a plain `cp`
     * would) produces a clean, complete copy of the live database
     * directly, with no external process needed either -- a real DBAL
     * connection, built straight from $credentials (not
     * DbConnection::build(), which may resolve different credentials via
     * the container -- this method's own contract is "dump exactly what
     * $credentials names").
     */
    private function dumpDatabase(DbCredentials $credentials, string $outputPath): void
    {
        if ($credentials->driver === 'sqlite3') {
            $conn = DriverManager::getConnection([
                'driver' => 'sqlite3',
                'path' => $credentials->database,
            ]);
            $conn->executeStatement('VACUUM INTO ?', [$outputPath]);

            return;
        }

        if ($credentials->driver === 'pgsql') {
            $this->runProcess([
                'pg_dump',
                ...$credentials->toPsqlArgs(),
                '--no-owner',
                '--no-privileges',
                '--file=' . $outputPath,
                $credentials->database,
            ], 'pg_dump', $this->pgsqlEnv($credentials));

            return;
        }

        $this->runProcess([
            'mysqldump',
            ...$credentials->toMysqlArgs(),
            '--skip-comments',
            '--result-file=' . $outputPath,
            $credentials->database,
        ], 'mysqldump');
    }

    private function restoreDatabase(DbCredentials $credentials, string $targetDatabase, string $dumpPath): void
    {
        // Checked up front, before either real client ever runs, so both
        // platforms fail with the same clean, specific error instead of
        // whatever generic/misleading failure the client itself produces
        // for an unreadable file (`psql -f` given a permission-000 file
        // still tries to connect to the target database first, surfacing
        // a connection error that has nothing to do with the real cause).
        if (! is_readable($dumpPath)) {
            throw new RuntimeException("Unable to open dump file for reading: {$dumpPath}");
        }

        if ($credentials->driver === 'sqlite3') {
            // The dump produced by dumpDatabase()'s own VACUUM INTO
            // branch is already a complete, self-contained database file
            // -- restoring it is a plain file copy to $targetDatabase
            // (a real file path for this driver, DbCredentials's own
            // `database` field doubling as one, see Wave 0), no SQL
            // client/parser involved at all.
            if (! copy($dumpPath, $targetDatabase)) {
                throw new RuntimeException("Unable to restore SQLite database to: {$targetDatabase}");
            }

            return;
        }

        if ($credentials->driver === 'pgsql') {
            $this->runProcess([
                'psql',
                ...$credentials->toPsqlArgs(),
                '-v',
                'ON_ERROR_STOP=1',
                '-q',
                '-d',
                $targetDatabase,
                '-f',
                $dumpPath,
            ], 'psql restore', $this->pgsqlEnv($credentials));

            return;
        }

        $stream = fopen($dumpPath, 'r');
        if ($stream === false) {
            throw new RuntimeException("Unable to open dump file for reading: {$dumpPath}");
        }

        $process = new Process(['mysql', ...$credentials->toMysqlArgs(), $targetDatabase]);
        $process->setTimeout(300);
        $process->setInput($stream);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new RuntimeException('mysql restore failed: ' . $process->getErrorOutput());
        }
    }

    /**
     * psql/pg_dump have no password CLI flag (PGPASSWORD env var or
     * ~/.pgpass only) -- same convention every real psql-shelling call
     * site in this codebase already uses (IntegrationTestCase::
     * loadFixtureViaPsql(), RegenerateFixtureTest's own pg_dump call,
     * UserListCommand).
     *
     * @return array<string, string>|null
     */
    private function pgsqlEnv(DbCredentials $credentials): ?array
    {
        return $credentials->password !== '' ? array_merge(getenv(), [
            'PGPASSWORD' => $credentials->password,
        ]) : null;
    }

    /**
     * @param list<string> $command
     * @param array<string, string>|null $env
     */
    private function runProcess(array $command, string $description, ?array $env = null): void
    {
        $process = new Process($command, null, $env);
        $process->setTimeout(300);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new RuntimeException("{$description} failed: " . $process->getErrorOutput());
        }
    }

    private function makeTempDir(string $prefix): string
    {
        $dir = sys_get_temp_dir() . '/' . uniqid($prefix, true);
        mkdir($dir, 0o775, true);

        return $dir;
    }

    private function removeDir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        $items = scandir($dir);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $dir . '/' . $item;
            if (is_dir($path) && ! is_link($path)) {
                $this->removeDir($path);
            } else {
                unlink($path);
            }
        }

        rmdir($dir);
    }
}
