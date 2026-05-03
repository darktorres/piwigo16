<?php

declare(strict_types=1);

namespace Piwigo\Tests\Unit\Config;

use PHPUnit\Framework\TestCase;
use Piwigo\Config\Config;
use Piwigo\Config\ConfigLoader;

final class ConfigLoaderApplyDefaultsTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['conf'] = [];
        Config::reset();
    }

    protected function tearDown(): void
    {
        Config::reset();
        unset($GLOBALS['conf']);
    }

    public function test_applyDefaults_seeds_only_non_null_values(): void
    {
        $conf = [];
        ConfigLoader::applyDefaults($conf);

        // Invariant: every value seeded into $conf is non-null. Null-defaulted
        // keys (typically the nullable-string cluster: gallery_url,
        // cache_sizes, last_major_update, etc.) are intentionally absent —
        // their absence is the signal Config::has() consumers use to detect
        // first-run state.
        foreach ($conf as $key => $value) {
            self::assertNotNull($value, "applyDefaults() seeded null for '$key'");
        }

        // Specific keys that MUST be populated (non-null defaults from SCHEMA
        // or from a custom accessor that returns a non-null value).
        $mustExist = ['admin_theme', 'gallery_title', 'session_length', 'picture_ext', 'api_key_duration'];
        foreach ($mustExist as $key) {
            self::assertArrayHasKey($key, $conf, "applyDefaults() must populate '$key'");
        }

        // Specific nullable keys that MUST be absent (else Config::has() breaks).
        $mustBeAbsent = ['gallery_url', 'cache_sizes', 'filters_views', 'last_major_update', 'piwigo_db_version'];
        foreach ($mustBeAbsent as $key) {
            self::assertArrayNotHasKey($key, $conf, "applyDefaults() must NOT populate nullable key '$key'");
        }
    }

    public function test_applyDefaults_skips_keys_already_set(): void
    {
        $conf = ['gallery_title' => 'My Gallery', 'session_length' => 7200];
        ConfigLoader::applyDefaults($conf);

        self::assertSame('My Gallery', $conf['gallery_title']);
        self::assertSame(7200, $conf['session_length']);
    }

    public function test_applyDefaults_uses_schema_defaults_for_simple_types(): void
    {
        $conf = [];
        ConfigLoader::applyDefaults($conf);

        self::assertSame('roma', $conf['admin_theme']);
        self::assertSame('./themes', $conf['themes_dir']);
        self::assertSame(true, $conf['activate_comments']);
        self::assertSame(15, $conf['top_number']);
    }

    public function test_applyDefaults_invokes_custom_accessors_for_rich_defaults(): void
    {
        $conf = [];
        ConfigLoader::applyDefaults($conf);

        self::assertSame(['jpg', 'jpeg', 'png', 'gif', 'webp'], $conf['picture_ext']);
        self::assertSame(
            ['jpg', 'jpeg', 'png', 'gif', 'webp', 'tiff', 'tif', 'mpg', 'zip', 'avi', 'mp3', 'ogg', 'pdf', 'svg', 'heic'],
            $conf['file_ext']
        );
        self::assertSame(
            ['RSS' => ['max_dates' => 5, 'max_elements' => 6, 'max_cats' => 6], 'NBM' => ['max_dates' => 7, 'max_elements' => 3, 'max_cats' => 9]],
            $conf['recent_post_dates']
        );
        self::assertCount(8, $conf['api_key_forbidden_methods']);
    }
}
