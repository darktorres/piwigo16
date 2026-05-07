<?php

declare(strict_types=1);

namespace Piwigo\Tests\Unit\Template;

use PHPUnit\Framework\TestCase;
use Piwigo\Template\ScriptLoader;
use ReflectionClass;

final class ScriptLoaderTest extends TestCase
{
    private string $realManifest;
    private string $backupManifest;
    private bool $manifestExisted = false;

    protected function setUp(): void
    {
        $this->realManifest = PHPWG_ROOT_PATH . 'dist/manifest.json';
        $this->backupManifest = PHPWG_ROOT_PATH . 'dist/manifest.json.bak_test';
        if (is_file($this->realManifest)) {
            $this->manifestExisted = true;
            rename($this->realManifest, $this->backupManifest);
        }
        $this->resetManifestCache();
    }

    protected function tearDown(): void
    {
        $this->resetManifestCache();
        if (is_file($this->realManifest)) {
            unlink($this->realManifest);
        }
        if ($this->manifestExisted && is_file($this->backupManifest)) {
            rename($this->backupManifest, $this->realManifest);
        }
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
        self::assertSame('assets/scripts-abc.js', $result['core.scripts']['file']);
    }

    public function test_manifest_is_cached_after_first_call(): void
    {
        $this->writeManifest(['common' => ['file' => 'assets/common-abc.js', 'imports' => [], 'css' => []]]);

        $first = $this->callManifest();
        // Overwrite the file WITHOUT resetting the cache — should return cached value.
        $this->writeManifest(['common' => ['file' => 'assets/common-NEW.js', 'imports' => [], 'css' => []]], false);
        $second = $this->callManifest();

        self::assertSame($first, $second);
        self::assertSame('assets/common-abc.js', $second['common']['file']);
    }

    // ── add() + manifest path resolution ────────────────────────────────────

    public function test_add_without_manifest_registers_original_path(): void
    {
        $loader = new ScriptLoader();
        $loader->add('jquery', 0, [], 'themes/_base/js/jquery.min.js');
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
        $loader->add('common', 1, [], 'themes/admin/_base/js/common.js');
        $scripts = $this->getRegisteredScripts($loader);
        self::assertArrayHasKey('common', $scripts);
        self::assertSame('dist/assets/common-XYZ789.js', $scripts['common']->path);
    }

    public function test_add_with_manifest_preserves_require_list(): void
    {
        $this->writeManifest([
            'common' => ['file' => 'assets/common-abc.js', 'imports' => [], 'css' => []],
        ]);

        $loader = new ScriptLoader();
        $loader->add('common', 1, ['jquery', 'jquery.ui'], 'themes/admin/_base/js/common.js');
        $scripts = $this->getRegisteredScripts($loader);
        // Manifest resolves the dist path, but the caller-supplied require list is
        // preserved — legacy plugins and other Vite entries are not encoded as
        // manifest chunk imports and still need to load separately.
        self::assertSame('dist/assets/common-abc.js', $scripts['common']->path);
        self::assertSame(['jquery', 'jquery.ui'], $scripts['common']->precedents);
    }

    public function test_add_unknown_manifest_key_falls_back_to_original_path(): void
    {
        $this->writeManifest([
            'other_entry' => ['file' => 'assets/other-abc.js', 'imports' => [], 'css' => []],
        ]);

        $loader = new ScriptLoader();
        $loader->add('jquery', 0, [], 'themes/_base/js/jquery.min.js');
        $scripts = $this->getRegisteredScripts($loader);
        self::assertSame('themes/_base/js/jquery.min.js', $scripts['jquery']->path);
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    private function callManifest(): ?array
    {
        $ref = new ReflectionClass(ScriptLoader::class);
        $method = $ref->getMethod('manifest');
        return $method->invoke(null);
    }

    private function getRegisteredScripts(ScriptLoader $loader): array
    {
        $ref = new ReflectionClass($loader);
        $prop = $ref->getProperty('registered_scripts');
        return $prop->getValue($loader);
    }

    private function resetManifestCache(): void
    {
        $ref = new ReflectionClass(ScriptLoader::class);
        $prop = $ref->getProperty('manifest');
        $prop->setValue(null, null);
    }

    private function writeManifest(array $data, bool $resetCache = true): void
    {
        $distDir = PHPWG_ROOT_PATH . 'dist/';
        if (!is_dir($distDir)) {
            mkdir($distDir, 0755, true);
        }
        file_put_contents($this->realManifest, json_encode($data));
        if ($resetCache) {
            $this->resetManifestCache();
        }
    }
}
