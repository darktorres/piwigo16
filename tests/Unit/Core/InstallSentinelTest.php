<?php

declare(strict_types=1);

namespace Piwigo\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use Piwigo\Core\InstallSentinel;
use Piwigo\Core\Paths;

final class InstallSentinelTest extends TestCase
{
    private string $stampPath = '';
    private Paths $paths;

    #[\Override]
    protected function setUp(): void
    {
        // tests/bootstrap.php sets test mode → InstallSentinel uses
        // local/.installed.test instead of local/.installed. Construct
        // a Paths rooted at a per-process tmp sandbox so the stamp file
        // is isolated from the repo's own local/ dir.
        $tmpRoot = sys_get_temp_dir() . '/piwigo-install-sentinel-test_' . uniqid('', true) . '/';
        $this->paths = Paths::fromRoot($tmpRoot);

        if (!is_dir($this->paths->local)) {
            mkdir($this->paths->local, 0o755, true);
        }
        $this->stampPath = $this->paths->local . '.installed.test';

        InstallSentinel::markUninstalled($this->paths);
    }

    #[\Override]
    protected function tearDown(): void
    {
        InstallSentinel::markUninstalled($this->paths);
    }

    public function test_isInstalled_false_when_stamp_missing(): void
    {
        self::assertFalse(InstallSentinel::isInstalled($this->paths));
    }

    public function test_markInstalled_creates_stamp_and_isInstalled_true(): void
    {
        InstallSentinel::markInstalled($this->paths);
        self::assertFileExists($this->stampPath);
        self::assertTrue(InstallSentinel::isInstalled($this->paths));
    }

    public function test_markUninstalled_removes_stamp(): void
    {
        InstallSentinel::markInstalled($this->paths);
        InstallSentinel::markUninstalled($this->paths);
        self::assertFileDoesNotExist($this->stampPath);
    }
}
