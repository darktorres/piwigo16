<?php

declare(strict_types=1);

namespace Piwigo\Tests\Unit\Config;

use PHPUnit\Framework\TestCase;
use Piwigo\Config\Config;
use Piwigo\Config\ConfigLoader;

final class ConfigLoaderApplyDefaultsTest extends TestCase
{
    #[\Override]
    protected function setUp(): void
    {
        Config::reset();
    }

    #[\Override]
    protected function tearDown(): void
    {
        Config::reset();
    }

    public function test_applyDefaults_seeds_only_non_null_values(): void
    {
        ConfigLoader::applyDefaults();

        // Invariant: every value seeded into Config is non-null. Null-defaulted
        // keys (typically the nullable-string cluster: gallery_url,
        // cache_sizes, last_major_update, etc.) are intentionally absent —
        // their absence is the signal Config::has() consumers use to detect
        // first-run state.
        foreach (Config::all() as $key => $value) {
            self::assertNotNull($value, "applyDefaults() seeded null for '$key'");
        }

        // Specific keys that MUST be populated (non-null defaults from SCHEMA
        // or from a custom accessor that returns a non-null value).
        $mustExist = ['admin_theme', 'gallery_title', 'session_length', 'picture_ext', 'api_key_duration'];
        foreach ($mustExist as $key) {
            self::assertTrue(Config::has($key), "applyDefaults() must populate '$key'");
        }

        // Specific nullable keys that MUST be absent (else Config::has() breaks).
        $mustBeAbsent = ['gallery_url', 'cache_sizes', 'filters_views', 'last_major_update', 'piwigo_db_version'];
        foreach ($mustBeAbsent as $key) {
            self::assertFalse(Config::has($key), "applyDefaults() must NOT populate nullable key '$key'");
        }
    }

    public function test_applyDefaults_skips_keys_already_set(): void
    {
        Config::override('gallery_title', 'My Gallery');
        Config::override('session_length', 7200);
        ConfigLoader::applyDefaults();

        self::assertSame('My Gallery', Config::galleryTitle());
        self::assertSame(7200, Config::sessionLength());
    }

    public function test_applyDefaults_uses_schema_defaults_for_simple_types(): void
    {
        ConfigLoader::applyDefaults();

        self::assertSame('dark', Config::adminTheme());
        self::assertSame('./themes', Config::themesDir());
        self::assertTrue(Config::activateComments());
        self::assertSame(15, Config::topNumber());
    }

    public function test_applyDefaults_invokes_custom_accessors_for_rich_defaults(): void
    {
        ConfigLoader::applyDefaults();

        self::assertSame(['jpg', 'jpeg', 'png', 'gif', 'webp'], Config::pictureExtensions());
        self::assertSame(
            ['jpg', 'jpeg', 'png', 'gif', 'webp', 'tiff', 'tif', 'mpg', 'zip', 'avi', 'mp3', 'ogg', 'pdf', 'svg', 'heic'],
            Config::fileExtensions()
        );
        self::assertSame(
            ['RSS' => ['max_dates' => 5, 'max_elements' => 6, 'max_cats' => 6], 'NBM' => ['max_dates' => 7, 'max_elements' => 3, 'max_cats' => 9]],
            Config::recentPostDates()
        );
        self::assertCount(8, Config::apiKeyForbiddenMethods());
    }
}
