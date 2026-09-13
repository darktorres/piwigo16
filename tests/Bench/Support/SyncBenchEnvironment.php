<?php

declare(strict_types=1);

namespace Piwigo\Tests\Bench\Support;

use mysqli;
use Piwigo\Core\Kernel;
use Piwigo\Core\Paths;
use Piwigo\Db\DbConnection;
use RuntimeException;

/**
 * Builds and tears down an isolated environment for one SiteSyncBench
 * scale: a scratch database (never the shared fixture DB other
 * sessions/tests may be using concurrently -- see
 * IntegrationTestCase::$sharedFixtureKnownPristine's own docblock for why
 * that DB can never be treated as disposable) and a scratch photo
 * directory outside the repo.
 *
 * Kernel boots against the real repo root, same as every
 * IntegrationTestCase subclass -- there's no need for (and real risk in)
 * a synthetic Paths root, since app assets (plugins/themes/local config)
 * are DB-independent and identical to every other Integration test's own
 * setup. Only the DB name (env override, read fresh by DbCredentials on
 * first resolve after boot) and the `sites.galleries_url` row (updated
 * after the fixture import) differ from a real install.
 */
final class SyncBenchEnvironment
{
    public readonly string $scratchRoot;

    public readonly string $galleriesDir;

    private readonly string $dbHost;

    private readonly string $dbUser;

    private readonly string $dbPass;

    private readonly string $dbName;

    private readonly string $repoRoot;

    private readonly string $originalDbBase;

    private bool $tornDown = false;

    public function __construct(int $scale)
    {
        $this->dbHost = self::env('PIWIGO_DB_HOST', '127.0.0.1');
        $this->dbUser = self::env('PIWIGO_DB_USER', '');
        $this->dbPass = self::env('PIWIGO_DB_PASSWORD', '');
        $this->originalDbBase = self::env('PIWIGO_DB_BASE', 'piwigo');
        $this->dbName = $this->originalDbBase . '_bench';
        // tests/Bench/Support/ -> tests/Bench -> tests -> repo root.
        $this->repoRoot = dirname(__DIR__, 3) . '/';

        $this->scratchRoot = sys_get_temp_dir() . '/piwigo-sync-bench-' . $scale . '-' . bin2hex(random_bytes(4)) . '/';
        $this->galleriesDir = $this->scratchRoot . 'galleries/';
        if (! mkdir($this->galleriesDir, 0o777, true) && ! is_dir($this->galleriesDir)) {
            throw new RuntimeException("Failed to create scratch galleries dir: {$this->galleriesDir}");
        }

        // PHPBench's own subprocess template never reaches afterMethods
        // (SiteSyncBench::afterSync(), which calls tearDown()) once the
        // timed subject throws an uncaught error -- the whole subprocess
        // just exits, confirmed live by 30 leaked scratch directories
        // from failed benchmark runs during this class's own development.
        // register_shutdown_function() still fires in that case, so it's
        // the real safety net -- tearDown() itself is idempotent
        // ($tornDown) since this then runs in addition to, not instead
        // of, afterSync()'s own explicit call on a normal run.
        register_shutdown_function(function (): void {
            $this->tearDown();
        });
    }

    /**
     * Creates the scratch database from the committed fixture, then boots
     * a fresh Kernel pointed at it. Must run before any container-resolved
     * class touches the DB (e.g. SyncScenarioBuilder only touches the
     * filesystem, so ordering against it doesn't matter).
     */
    public function setUp(): void
    {
        $this->dropAndCreateDatabase();
        $this->importFixture();

        // Read fresh by DbCredentials::fromEnv() the first time anything
        // in the about-to-be-booted container resolves it -- must happen
        // before Kernel::boot(), not after, since the container's own
        // DbCredentials instance is a singleton for this Kernel lifetime.
        putenv('PIWIGO_DB_BASE=' . $this->dbName);

        if (Kernel::isBooted()) {
            Kernel::reset();
        }
        // isAdmin: true -- matches public/admin.php's own
        // RequestBootstrap::bootEntryPoint($paths, isAdmin: true) call;
        // without it, CurrentTemplate/Renderer resolve template paths
        // against the front-end theme instead of themes/admin/, and
        // SiteUpdateSubController::handle()'s own Tabsheet::assign() call
        // fails to find tabsheet.latte.
        Kernel::boot(Paths::fromRoot($this->repoRoot), isAdmin: true);

        DbConnection::build()->executeStatement(
            'UPDATE sites SET galleries_url = ? WHERE id = 1',
            [$this->galleriesDir],
        );
    }

    public function tearDown(): void
    {
        if ($this->tornDown) {
            return;
        }
        $this->tornDown = true;

        if (Kernel::isBooted()) {
            Kernel::reset();
        }
        putenv('PIWIGO_DB_BASE=' . $this->originalDbBase);

        $this->dropDatabase();
        self::rrmdir($this->scratchRoot);
    }

    private function dropAndCreateDatabase(): void
    {
        $db = $this->rootConnection();
        $db->query(sprintf('DROP DATABASE IF EXISTS `%s`', $this->dbName));
        $db->query(sprintf('CREATE DATABASE `%s` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci', $this->dbName));
        $db->close();
    }

    private function dropDatabase(): void
    {
        $db = $this->rootConnection();
        $db->query(sprintf('DROP DATABASE IF EXISTS `%s`', $this->dbName));
        $db->close();
    }

    private function rootConnection(): mysqli
    {
        return new mysqli($this->dbHost, $this->dbUser, $this->dbPass);
    }

    /**
     * Same `proc_open(['mysql', ...])` mechanics as
     * IntegrationTestCase::loadFixtureViaMysql(), pointed at the scratch
     * database instead of the shared fixture one.
     */
    private function importFixture(): void
    {
        $fixturePath = $this->repoRoot . 'tests/Fixtures/piwigo-17.0.sql';
        if (! is_file($fixturePath)) {
            throw new RuntimeException("Fixture file not found: {$fixturePath}");
        }

        $cmd = ['mysql', '-u' . $this->dbUser];
        if ($this->dbPass !== '') {
            $cmd[] = '-p' . $this->dbPass;
        }
        $cmd[] = str_starts_with($this->dbHost, '/') ? '--socket=' . $this->dbHost : '-h' . $this->dbHost;
        $cmd[] = $this->dbName;

        $descriptors = [
            0 => ['file', $fixturePath, 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = proc_open($cmd, $descriptors, $pipes);
        if (! is_resource($proc)) {
            throw new RuntimeException('proc_open failed for mysql fixture load');
        }
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $exit = proc_close($proc);
        if ($exit !== 0) {
            throw new RuntimeException('mysql fixture load failed: ' . ($stderr === false ? '' : $stderr));
        }
    }

    private static function env(string $name, string $default): string
    {
        $value = getenv($name);

        return $value !== false && $value !== '' ? $value : $default;
    }

    private static function rrmdir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        $nodes = scandir($dir);
        foreach ($nodes !== false ? $nodes : [] as $node) {
            if ($node === '.' || $node === '..') {
                continue;
            }
            $path = $dir . '/' . $node;
            is_dir($path) ? self::rrmdir($path) : unlink($path);
        }
        rmdir($dir);
    }
}
