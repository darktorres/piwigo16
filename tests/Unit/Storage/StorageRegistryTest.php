<?php

declare(strict_types=1);

namespace Piwigo\Tests\Unit\Storage;

use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\Local\LocalFilesystemAdapter;
use PHPUnit\Framework\TestCase;
use Piwigo\Storage\StorageRegistry;

final class StorageRegistryTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/piwigo_storage_test_' . getmypid() . '_' . mt_rand();
        mkdir($this->tmpDir, 0755, true);
        StorageRegistry::reset();
    }

    protected function tearDown(): void
    {
        StorageRegistry::reset();
        $this->deleteDir($this->tmpDir);
    }

    private function deleteDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            /** @var \SplFileInfo $item */
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
    }

    private function makeRegistry(): StorageRegistry
    {
        $tmpDir = $this->tmpDir;
        return new StorageRegistry([
            'temp' => static fn (): FilesystemOperator => new Filesystem(
                new LocalFilesystemAdapter($tmpDir)
            ),
        ]);
    }

    public function test_get_returns_filesystem_operator(): void
    {
        $registry = $this->makeRegistry();
        self::assertInstanceOf(FilesystemOperator::class, $registry->get('temp'));
    }

    public function test_get_is_lazy_and_returns_same_instance(): void
    {
        $registry = $this->makeRegistry();
        $first  = $registry->get('temp');
        $second = $registry->get('temp');
        self::assertSame($first, $second);
    }

    public function test_get_throws_for_unknown_disk(): void
    {
        $registry = $this->makeRegistry();
        $this->expectException(\InvalidArgumentException::class);
        $registry->get('nonexistent');
    }

    public function test_set_and_current_and_disk_shortcut(): void
    {
        $registry = $this->makeRegistry();
        StorageRegistry::setInstance($registry);

        self::assertTrue(StorageRegistry::isInitialized());
        self::assertSame($registry, StorageRegistry::current());
        self::assertInstanceOf(FilesystemOperator::class, StorageRegistry::disk('temp'));
    }

    public function test_current_throws_when_not_initialised(): void
    {
        $this->expectException(\LogicException::class);
        StorageRegistry::current();
    }

    public function test_round_trip_write_and_read(): void
    {
        $registry = $this->makeRegistry();
        StorageRegistry::setInstance($registry);

        $disk = StorageRegistry::disk('temp');
        $disk->write('hello.txt', 'world');
        self::assertSame('world', $disk->read('hello.txt'));
    }

    public function test_strip_root_removes_prefix(): void
    {
        self::assertSame(
            '2025/05/05/photo.jpg',
            StorageRegistry::stripRoot('/var/www/upload', '/var/www/upload/2025/05/05/photo.jpg')
        );
    }

    public function test_strip_root_normalises_dot_slash(): void
    {
        // PHPWG_ROOT_PATH . './upload' vs absolute path without ./
        self::assertSame(
            '2025/05/05/photo.jpg',
            StorageRegistry::stripRoot('/var/www/./upload', '/var/www/upload/2025/05/05/photo.jpg')
        );
    }

    public function test_strip_root_normalises_backslashes(): void
    {
        self::assertSame(
            '2025/05/05/photo.jpg',
            StorageRegistry::stripRoot('C:\\www\\upload', 'C:\\www\\upload\\2025\\05\\05\\photo.jpg')
        );
    }

    public function test_from_config_loads_factories(): void
    {
        $configPath = $this->tmpDir . '/storage_test_cfg.php';
        $tmpDir     = $this->tmpDir;
        file_put_contents($configPath, '<?php
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use League\Flysystem\FilesystemOperator;
return [
    "scratch" => static fn (): FilesystemOperator => new Filesystem(new LocalFilesystemAdapter(' . var_export($tmpDir, true) . ')),
];
');
        $registry = StorageRegistry::fromConfig($configPath);
        self::assertInstanceOf(FilesystemOperator::class, $registry->get('scratch'));
    }
}
