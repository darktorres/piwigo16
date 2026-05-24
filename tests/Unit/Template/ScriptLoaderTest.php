<?php

declare(strict_types=1);

namespace Piwigo\Tests\Unit\Template;

use PHPUnit\Framework\TestCase;
use Piwigo\Core\Kernel;
use Piwigo\Core\Paths;
use Piwigo\Template\Script;
use Piwigo\Template\ScriptLoader;
use ReflectionClass;

final class ScriptLoaderTest extends TestCase
{
    private string $realManifest = '';
    private string $backupManifest = '';
    private bool $manifestExisted = false;

    #[\Override]
    protected function setUp(): void
    {
        // ScriptLoader::manifest() reads the dist path via the DI container.
        // Boot a Kernel rooted at the repo so manifest() finds dist/ where
        // the test stages its fixtures.
        $repoRoot = dirname(__DIR__, 3);
        Kernel::reset();
        Kernel::boot(Paths::fromRoot($repoRoot));

        $this->realManifest = $repoRoot . '/dist/manifest.json';
        $this->backupManifest = $repoRoot . '/dist/manifest.json.bak_test';
        if (is_file($this->realManifest)) {
            $this->manifestExisted = true;
            rename($this->realManifest, $this->backupManifest);
        }
        $this->resetManifestCache();
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->resetManifestCache();
        if (is_file($this->realManifest)) {
            unlink($this->realManifest);
        }
        if ($this->manifestExisted && is_file($this->backupManifest)) {
            rename($this->backupManifest, $this->realManifest);
        }
        Kernel::reset();
    }

    // ── manifest() ──────────────────────────────────────────────────────────

    public function test_manifest_returns_null_when_file_absent(): void
    {
        // setUp already removed any existing manifest.
        $result = $this->callManifest();
        self::assertNull($result);
    }

    public function test_manifest_returns_array_when_file_present(): void
    {
        $this->writeManifest(['core.scripts' => ['file' => 'assets/scripts-abc.js', 'imports' => [], 'css' => []]]);

        $result = $this->callManifest();
        self::assertIsArray($result);
        self::assertArrayHasKey('core.scripts', $result);
        $entry = $result['core.scripts'];
        self::assertIsArray($entry);
        self::assertSame('assets/scripts-abc.js', $entry['file']);
    }

    public function test_manifest_is_cached_after_first_call(): void
    {
        $this->writeManifest(['common' => ['file' => 'assets/common-abc.js', 'imports' => [], 'css' => []]]);

        $first = $this->callManifest();
        // Overwrite the file WITHOUT resetting the cache — should return cached value.
        $this->writeManifest(['common' => ['file' => 'assets/common-NEW.js', 'imports' => [], 'css' => []]], false);
        $second = $this->callManifest();

        self::assertSame($first, $second);
        self::assertIsArray($second);
        $commonEntry = $second['common'];
        self::assertIsArray($commonEntry);
        self::assertSame('assets/common-abc.js', $commonEntry['file']);
    }

    // ── add() + manifest path resolution ────────────────────────────────────

    public function test_add_without_manifest_registers_original_path(): void
    {
        $loader = new ScriptLoader();
        $loader->add('jquery', 'themes/_base/js/jquery.min.js');
        $scripts = $this->getRegisteredScripts($loader);
        self::assertArrayHasKey('jquery', $scripts);
        self::assertSame('themes/_base/js/jquery.min.js', $scripts['jquery']->path);
    }

    public function test_add_with_manifest_uses_hashed_path(): void
    {
        $this->writeManifest([
            'common' => ['file' => 'assets/common-XYZ789.js', 'imports' => [], 'css' => []],
        ]);

        $loader = new ScriptLoader();
        $loader->add('common', 'themes/admin/_base/js/common.js');
        $scripts = $this->getRegisteredScripts($loader);
        self::assertArrayHasKey('common', $scripts);
        self::assertSame('dist/assets/common-XYZ789.js', $scripts['common']->path);
    }

    public function test_add_unknown_manifest_key_falls_back_to_original_path(): void
    {
        $this->writeManifest([
            'other_entry' => ['file' => 'assets/other-abc.js', 'imports' => [], 'css' => []],
        ]);

        $loader = new ScriptLoader();
        $loader->add('jquery', 'themes/_base/js/jquery.min.js');
        $scripts = $this->getRegisteredScripts($loader);
        self::assertSame('themes/_base/js/jquery.min.js', $scripts['jquery']->path);
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    /** @return array<string, mixed>|null */
    private function callManifest(): ?array
    {
        $ref = new ReflectionClass(ScriptLoader::class);
        $method = $ref->getMethod('manifest');
        $raw = $method->invoke(null);
        if (!is_array($raw)) {
            return null;
        }
        return array_combine(array_map(strval(...), array_keys($raw)), array_values($raw)) ?: [];
    }

    /** @return array<string, Script> */
    private function getRegisteredScripts(ScriptLoader $loader): array
    {
        $ref = new ReflectionClass(ScriptLoader::class);
        $prop = $ref->getProperty('registered_scripts');
        /** @var array<string, Script> */
        return $prop->getValue($loader);
    }

    private function resetManifestCache(): void
    {
        $ref = new ReflectionClass(ScriptLoader::class);
        $prop = $ref->getProperty('manifest');
        $prop->setValue(null, null);
    }

    /** @param array<mixed> $data */
    private function writeManifest(array $data, bool $resetCache = true): void
    {
        $distDir = dirname(__DIR__, 3) . '/dist/';
        if (!is_dir($distDir)) {
            mkdir($distDir, 0755, true);
        }
        $encoded = json_encode($data);
        file_put_contents($this->realManifest, $encoded !== false ? $encoded : '{}');
        if ($resetCache) {
            $this->resetManifestCache();
        }
    }
}
