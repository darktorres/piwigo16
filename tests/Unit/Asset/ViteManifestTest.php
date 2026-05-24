<?php

declare(strict_types=1);

namespace Piwigo\Tests\Unit\Asset;

use PHPUnit\Framework\TestCase;
use Piwigo\Asset\ViteManifest;
use Piwigo\Core\Kernel;
use Piwigo\Core\Paths;

final class ViteManifestTest extends TestCase
{
    private string $manifestPath = '';
    private string $backupPath = '';
    private bool $manifestExisted = false;

    #[\Override]
    protected function setUp(): void
    {
        $repoRoot = dirname(__DIR__, 3);
        Kernel::reset();
        Kernel::boot(Paths::fromRoot($repoRoot));

        $this->manifestPath = $repoRoot . '/dist/manifest.json';
        $this->backupPath = $repoRoot . '/dist/manifest.json.bak_test';
        if (is_file($this->manifestPath)) {
            $this->manifestExisted = true;
            rename($this->manifestPath, $this->backupPath);
        }
        ViteManifest::resetCache();
    }

    #[\Override]
    protected function tearDown(): void
    {
        ViteManifest::resetCache();
        if (is_file($this->manifestPath)) {
            unlink($this->manifestPath);
        }
        if ($this->manifestExisted && is_file($this->backupPath)) {
            rename($this->backupPath, $this->manifestPath);
        }
        Kernel::reset();
    }

    public function test_read_returns_null_when_file_absent(): void
    {
        self::assertNull(ViteManifest::read());
    }

    public function test_read_returns_array_when_file_present(): void
    {
        $this->writeManifest([
            'albums' => ['file' => 'assets/albums-abc.js', 'css' => ['assets/albums-abc.css']],
        ]);

        $result = ViteManifest::read();
        self::assertIsArray($result);
        self::assertArrayHasKey('albums', $result);
    }

    public function test_read_caches_result(): void
    {
        $this->writeManifest(['a' => ['file' => 'assets/a-1.js', 'css' => []]]);
        $first = ViteManifest::read();

        $this->writeManifest(['a' => ['file' => 'assets/a-2.js', 'css' => []]], resetCache: false);
        $second = ViteManifest::read();

        self::assertSame($first, $second);
    }

    public function test_entry_returns_file_and_css(): void
    {
        $this->writeManifest([
            'albums' => [
                'file' => 'assets/albums-abc.js',
                'css' => ['assets/albums-abc.css', 'assets/tippy-xyz.css'],
            ],
        ]);

        $entry = ViteManifest::entry('albums');
        self::assertNotNull($entry);
        self::assertSame('assets/albums-abc.js', $entry['file']);
        self::assertSame(['assets/albums-abc.css', 'assets/tippy-xyz.css'], $entry['css']);
    }

    public function test_entry_returns_empty_css_when_none(): void
    {
        $this->writeManifest([
            'common' => ['file' => 'assets/common-abc.js'],
        ]);

        $entry = ViteManifest::entry('common');
        self::assertNotNull($entry);
        self::assertSame([], $entry['css']);
    }

    public function test_entry_returns_null_for_unknown_id(): void
    {
        $this->writeManifest([
            'albums' => ['file' => 'assets/albums-abc.js', 'css' => []],
        ]);

        self::assertNull(ViteManifest::entry('nonexistent'));
    }

    public function test_entry_returns_null_when_no_manifest(): void
    {
        self::assertNull(ViteManifest::entry('albums'));
    }

    /** @param array<string, mixed> $data */
    private function writeManifest(array $data, bool $resetCache = true): void
    {
        $dir = dirname($this->manifestPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $encoded = json_encode($data);
        file_put_contents($this->manifestPath, $encoded !== false ? $encoded : '{}');
        if ($resetCache) {
            ViteManifest::resetCache();
        }
    }
}
