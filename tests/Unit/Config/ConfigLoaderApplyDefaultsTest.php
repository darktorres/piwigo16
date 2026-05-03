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

    public function test_applyDefaults_populates_every_schema_key(): void
    {
        $conf = [];
        ConfigLoader::applyDefaults($conf);

        foreach (array_keys(Config::SCHEMA) as $key) {
            self::assertArrayHasKey($key, $conf, "applyDefaults() did not populate '$key'");
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
