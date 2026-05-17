<?php

declare(strict_types=1);

namespace Piwigo\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use Piwigo\Core\Paths;

final class PathsTest extends TestCase
{
    public function test_fromIndex_derives_root_from_dirname_of_file(): void
    {
        $paths = Paths::fromIndex('/var/www/piwigo/index.php');

        self::assertSame('/var/www/piwigo/', $paths->root);
    }

    public function test_fromIndex_resolves_subdirs_under_root(): void
    {
        $paths = Paths::fromIndex('/var/www/piwigo/index.php');

        self::assertSame('/var/www/piwigo/plugins/', $paths->plugins);
        self::assertSame('/var/www/piwigo/themes/', $paths->themes);
        self::assertSame('/var/www/piwigo/local/', $paths->local);
        self::assertSame('/var/www/piwigo/_data/', $paths->data);
        self::assertSame('/var/www/piwigo/_data/i/', $paths->derivatives);
        self::assertSame('/var/www/piwigo/_data/logs/', $paths->logs);
        self::assertSame('/var/www/piwigo/upload/', $paths->upload);
        self::assertSame('/var/www/piwigo/config/', $paths->config);
        self::assertSame('/var/www/piwigo/vendor/', $paths->vendor);
    }

    public function test_fromRoot_normalizes_trailing_slash(): void
    {
        $withSlash    = Paths::fromRoot('/tmp/sandbox/');
        $withoutSlash = Paths::fromRoot('/tmp/sandbox');

        self::assertSame($withSlash->root, $withoutSlash->root);
        self::assertSame($withSlash->plugins, $withoutSlash->plugins);
        self::assertSame('/tmp/sandbox/', $withSlash->root);
        self::assertSame('/tmp/sandbox/plugins/', $withSlash->plugins);
    }

    public function test_fromRoot_normalizes_trailing_backslash(): void
    {
        $paths = Paths::fromRoot('C:\\inetpub\\piwigo\\');

        self::assertSame('C:\\inetpub\\piwigo/', $paths->root);
    }

    public function test_root_is_always_trailing_slashed(): void
    {
        self::assertStringEndsWith('/', Paths::fromIndex('/a/b/index.php')->root);
        self::assertStringEndsWith('/', Paths::fromRoot('/a/b')->root);
        self::assertStringEndsWith('/', Paths::fromRoot('/a/b/')->root);
    }

    public function test_real_index_resolves_to_repo_root(): void
    {
        $repoRoot = dirname(__DIR__, 3);
        $paths    = Paths::fromIndex($repoRoot . '/index.php');

        self::assertSame($repoRoot . '/', $paths->root);
        self::assertDirectoryExists($paths->root);
        self::assertDirectoryExists($paths->vendor);
        self::assertDirectoryExists($paths->config);
    }
}
