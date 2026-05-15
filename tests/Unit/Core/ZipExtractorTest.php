<?php

declare(strict_types=1);

namespace Piwigo\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use Piwigo\Core\ZipExtractor;
use ZipArchive;

final class ZipExtractorTest extends TestCase
{
    private string $tmpDir = '';
    private string $archivePath = '';
    private string $extractPath = '';

    #[\Override]
    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'piwigo-ziptest-' . bin2hex(random_bytes(4));
        mkdir($this->tmpDir, 0o755, true);
        $this->archivePath = $this->tmpDir . '/archive.zip';
        $this->extractPath = $this->tmpDir . '/out';
        mkdir($this->extractPath, 0o755, true);
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->rmrf($this->tmpDir);
    }

    private function rmrf(string $path): void
    {
        if (is_file($path) || is_link($path)) {
            unlink($path);
            return;
        }
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $this->rmrf($path . '/' . $entry);
        }
        rmdir($path);
    }

    /** @param array<string, string> $entries  stored name => contents */
    private function buildArchive(array $entries): void
    {
        $zip = new ZipArchive();
        self::assertTrue($zip->open($this->archivePath, ZipArchive::CREATE) === true);
        foreach ($entries as $name => $contents) {
            if (str_ends_with($name, '/')) {
                $zip->addEmptyDir(rtrim($name, '/'));
            } else {
                $zip->addFromString($name, $contents);
            }
        }
        $zip->close();
    }

    public function test_listNames_returns_stored_entries_in_order(): void
    {
        $this->buildArchive([
            'pkg/main.inc.php' => '<?php',
            'pkg/lib/util.php' => '<?php',
            'pkg/readme.txt'   => 'hi',
        ]);
        self::assertSame(['pkg/main.inc.php', 'pkg/lib/util.php', 'pkg/readme.txt'], ZipExtractor::listNames($this->archivePath));
    }

    public function test_listNames_returns_empty_on_missing_archive(): void
    {
        self::assertSame([], ZipExtractor::listNames($this->tmpDir . '/nonexistent.zip'));
    }

    public function test_extract_strips_prefix_and_writes_files(): void
    {
        $this->buildArchive([
            'pkg/main.inc.php'   => '<?php /*main*/',
            'pkg/lib/util.php'   => '<?php /*util*/',
        ]);
        $result = ZipExtractor::extract($this->archivePath, $this->extractPath, 'pkg');
        self::assertNotEmpty($result);
        self::assertFileExists($this->extractPath . '/main.inc.php');
        self::assertFileExists($this->extractPath . '/lib/util.php');
        self::assertSame('<?php /*main*/', file_get_contents($this->extractPath . '/main.inc.php'));
        $mainRow = $this->findRow($result, 'pkg/main.inc.php');
        self::assertSame('main.inc.php', $mainRow['filename']);
        self::assertSame('ok', $mainRow['status']);
    }

    public function test_extract_without_prefix_keeps_stored_paths(): void
    {
        $this->buildArchive([
            'foo.txt'         => 'foo',
            'sub/bar.txt'     => 'bar',
        ]);
        ZipExtractor::extract($this->archivePath, $this->extractPath);
        self::assertFileExists($this->extractPath . '/foo.txt');
        self::assertFileExists($this->extractPath . '/sub/bar.txt');
    }

    public function test_extract_only_names_filters_others(): void
    {
        $this->buildArchive([
            'a.txt' => 'A',
            'b.txt' => 'B',
            'c.txt' => 'C',
        ]);
        $result = ZipExtractor::extract($this->archivePath, $this->extractPath, '', ['b.txt']);
        self::assertFileDoesNotExist($this->extractPath . '/a.txt');
        self::assertFileExists($this->extractPath . '/b.txt');
        self::assertFileDoesNotExist($this->extractPath . '/c.txt');
        self::assertSame('filtered', $this->findRow($result, 'a.txt')['status']);
        self::assertSame('ok', $this->findRow($result, 'b.txt')['status']);
    }

    public function test_extract_blocks_path_traversal(): void
    {
        $this->buildArchive([
            'pkg/../escape.txt'    => 'pwned',
            'pkg/legit.txt'        => 'ok',
        ]);
        $result = ZipExtractor::extract($this->archivePath, $this->extractPath, 'pkg');
        self::assertFileDoesNotExist(dirname($this->extractPath) . '/escape.txt');
        self::assertFileExists($this->extractPath . '/legit.txt');
        $row = $this->findRow($result, 'pkg/../escape.txt');
        self::assertSame('path_error', $row['status']);
    }

    public function test_extract_applies_chmod_when_provided(): void
    {
        $this->buildArchive([
            'file.txt' => 'data',
        ]);
        ZipExtractor::extract($this->archivePath, $this->extractPath, '', null, 0o644);
        $mode = fileperms($this->extractPath . '/file.txt') & 0o777;
        self::assertSame(0o644, $mode);
    }

    public function test_extract_returns_empty_on_open_failure(): void
    {
        self::assertSame([], ZipExtractor::extract($this->tmpDir . '/missing.zip', $this->extractPath));
    }

    public function test_extract_creates_missing_parent_directories(): void
    {
        $this->buildArchive([
            'deep/nested/sub/file.txt' => 'x',
        ]);
        ZipExtractor::extract($this->archivePath, $this->extractPath);
        self::assertFileExists($this->extractPath . '/deep/nested/sub/file.txt');
    }

    /**
     * @param list<array{filename: string, stored_filename: string, status: string}> $result
     * @return array{filename: string, stored_filename: string, status: string}
     */
    private function findRow(array $result, string $storedName): array
    {
        foreach ($result as $row) {
            if ($row['stored_filename'] === $storedName) {
                return $row;
            }
        }
        self::fail('No row for ' . $storedName);
    }
}
