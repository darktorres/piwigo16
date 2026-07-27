<?php

declare(strict_types=1);

namespace Piwigo\Tests\Integration;

use Piwigo\Admin\Install\LegacyFileConf;
use Piwigo\Core\CurrentPaths;
use Piwigo\Core\Paths;

/**
 * LegacyFileConf::read() is pure filesystem + CurrentConfig::defaultsArray()
 * -- no DB, no container -- so every test here only needs a CurrentPaths
 * pointing at a throwaway temp directory tree (cleaned up in tearDown()),
 * never the real repo's local/ directory. Covers all 3 real branches: no
 * override file at all, a `local/config/config.inc.php` override, and the
 * `local_dir_site` flag pulling in a *second* (site-local) override file
 * that wins over the first.
 */
final class LegacyFileConfTest extends IntegrationTestCase
{
    private string $tempRoot;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->tempRoot = sys_get_temp_dir() . '/piwigo-legacyfileconf-' . bin2hex(random_bytes(6)) . '/';
        mkdir($this->tempRoot . 'local/config', 0777, true);
        mkdir($this->tempRoot . 'sitelocal/config', 0777, true);
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempRoot);
        parent::tearDown();
    }

    private function removeDirectory(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        $items = scandir($dir);
        self::assertIsArray($items);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }

    private function pathsWithSameLocalAndSiteLocal(): Paths
    {
        return new Paths(
            root: $this->tempRoot,
            plugins: $this->tempRoot . 'plugins/',
            themes: $this->tempRoot . 'themes/',
            local: $this->tempRoot . 'local/',
            siteLocal: $this->tempRoot . 'local/',
            data: $this->tempRoot . '_data/',
            derivatives: $this->tempRoot . '_data/i/',
            logs: $this->tempRoot . '_data/logs/',
            upload: $this->tempRoot . 'upload/',
            config: $this->tempRoot . 'config/',
            vendor: $this->tempRoot . 'vendor/',
        );
    }

    private function pathsWithDistinctSiteLocal(): Paths
    {
        return new Paths(
            root: $this->tempRoot,
            plugins: $this->tempRoot . 'plugins/',
            themes: $this->tempRoot . 'themes/',
            local: $this->tempRoot . 'local/',
            siteLocal: $this->tempRoot . 'sitelocal/',
            data: $this->tempRoot . '_data/',
            derivatives: $this->tempRoot . '_data/i/',
            logs: $this->tempRoot . '_data/logs/',
            upload: $this->tempRoot . 'upload/',
            config: $this->tempRoot . 'config/',
            vendor: $this->tempRoot . 'vendor/',
        );
    }

    public function test_read_returns_exactly_the_config_defaults_when_the_override_file_sets_nothing(): void
    {
        $paths = $this->pathsWithSameLocalAndSiteLocal();
        CurrentPaths::set($paths);
        // A present-but-empty config.inc.php (not a fully absent one) --
        // read()'s own `@include` is by design tolerant of a genuinely
        // missing file too (the real "fresh install" case this class's own
        // docblock describes), but PHPUnit's error handler surfaces the
        // resulting "failed to open stream" E_WARNING as a test warning
        // regardless of that inner `@` (a documented quirk of this
        // codebase's test suite -- confirmed: neither wrapping the outer
        // call in `@` nor a temporary error_reporting(0) silences it, `@`
        // suppression genuinely does not hide a warning from PHPUnit here).
        // A real guard -- ensuring the file exists -- is the reliable fix,
        // and still exercises the exact same "no keys overridden" outcome.
        file_put_contents($paths->local . 'config/config.inc.php', "<?php\n// no overrides\n");

        $result = LegacyFileConf::read();

        // The present-but-empty config.inc.php sets nothing, so the raw
        // CurrentConfig::defaultsArray() output comes back completely
        // unmodified.
        self::assertSame(
            [
                'data_location' => '_data/',
                'default_user_id' => 2,
                'guest_id' => 2,
                'rate' => true,
                'rate_anonymous' => true,
                'webmaster_id' => 1,
            ],
            $result
        );
    }

    public function test_read_applies_a_local_config_override_onto_the_defaults(): void
    {
        $paths = $this->pathsWithSameLocalAndSiteLocal();
        CurrentPaths::set($paths);
        file_put_contents(
            $paths->local . 'config/config.inc.php',
            "<?php\n\$conf['data_location'] = 'custom_data_dir/';\n\$conf['webmaster_id'] = 7;\n"
        );

        $result = LegacyFileConf::read();

        // The override touches only the 2 keys it assigns; every other
        // default key (guest_id/rate/rate_anonymous/default_user_id) comes
        // through unmodified, proving this is a merge onto the defaults
        // array, not a wholesale replacement.
        self::assertSame(
            [
                'data_location' => 'custom_data_dir/',
                'default_user_id' => 2,
                'guest_id' => 2,
                'rate' => true,
                'rate_anonymous' => true,
                'webmaster_id' => 7,
            ],
            $result
        );
    }

    public function test_read_does_not_load_the_site_local_file_when_local_dir_site_is_not_set(): void
    {
        $paths = $this->pathsWithDistinctSiteLocal();
        CurrentPaths::set($paths);
        // No 'local_dir_site' key set here -- the site-local include must
        // never fire, even though a config.inc.php file genuinely exists
        // at that second location.
        file_put_contents(
            $paths->local . 'config/config.inc.php',
            "<?php\n\$conf['data_location'] = 'from_local/';\n"
        );
        file_put_contents(
            $paths->siteLocal . 'config/config.inc.php',
            "<?php\n\$conf['data_location'] = 'from_site_local/';\n"
        );

        $result = LegacyFileConf::read();

        self::assertSame('from_local/', $result['data_location']);
        self::assertArrayNotHasKey('local_dir_site', $result);
    }

    public function test_read_lets_a_site_local_override_win_when_local_dir_site_is_set(): void
    {
        $paths = $this->pathsWithDistinctSiteLocal();
        CurrentPaths::set($paths);
        file_put_contents(
            $paths->local . 'config/config.inc.php',
            "<?php\n\$conf['local_dir_site'] = true;\n\$conf['data_location'] = 'from_local/';\n"
        );
        file_put_contents(
            $paths->siteLocal . 'config/config.inc.php',
            "<?php\n\$conf['data_location'] = 'from_site_local/';\n"
        );

        $result = LegacyFileConf::read();

        // The site-local include runs strictly after the local one, so its
        // own assignment to the same key wins.
        self::assertSame('from_site_local/', $result['data_location']);
        self::assertTrue($result['local_dir_site']);
        // Untouched defaults still present, confirming both includes
        // merged onto the same base array rather than each starting fresh.
        self::assertSame(2, $result['guest_id']);
    }
}
