<?php

declare(strict_types=1);

namespace Piwigo\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use Piwigo\Core\Config;

final class ConfigTest extends TestCase
{
    protected function setUp(): void
    {
        Config::reset();
    }

    protected function tearDown(): void
    {
        Config::reset();
    }

    public function test_get_returns_default_when_key_missing(): void
    {
        self::assertNull(Config::get('nonexistent'));
        self::assertSame('fallback', Config::get('nonexistent', 'fallback'));
    }

    public function test_get_returns_value_after_loadArray(): void
    {
        Config::loadArray(['upload_dir' => './upload', 'enable_formats' => false]);

        self::assertSame('./upload', Config::get('upload_dir'));
        self::assertFalse(Config::get('enable_formats'));
    }

    public function test_getString_coerces_to_string(): void
    {
        Config::loadArray(['some_int' => 42]);

        self::assertSame('42', Config::getString('some_int'));
        self::assertSame('default', Config::getString('missing', 'default'));
    }

    public function test_getInt_coerces_to_int(): void
    {
        Config::loadArray(['some_str' => '7']);

        self::assertSame(7, Config::getInt('some_str'));
        self::assertSame(0, Config::getInt('missing'));
    }

    public function test_getBool_coerces_to_bool(): void
    {
        Config::loadArray(['flag_true' => 1, 'flag_false' => 0]);

        self::assertTrue(Config::getBool('flag_true'));
        self::assertFalse(Config::getBool('flag_false'));
        self::assertFalse(Config::getBool('missing'));
    }

    public function test_typed_cluster_accessors_return_defaults(): void
    {
        Config::loadArray([]);

        self::assertSame('_data/', Config::dataLocation());
        self::assertSame('./upload', Config::uploadDir());
        self::assertSame('', Config::alternativePemUrl());
        self::assertSame('mysqli', Config::dbLayer());
        self::assertSame('DEBUG', Config::logLevel());
        self::assertSame(2, Config::guestId());
        self::assertSame(1, Config::webmasterId());
        self::assertFalse(Config::isFormatsEnabled());
        self::assertTrue(Config::allowHtmlDescriptions());
        self::assertTrue(Config::activateComments());
        self::assertSame(['jpg', 'jpeg', 'png', 'gif', 'webp'], Config::pictureExtensions());
    }

    public function test_override_updates_get_without_persisting(): void
    {
        Config::loadArray(['order_by' => 'ORDER BY id ASC']);
        Config::override('order_by', 'ORDER BY date_creation DESC');

        self::assertSame('ORDER BY date_creation DESC', Config::get('order_by'));
    }

    public function test_galleryUrl_returns_null_when_unset(): void
    {
        Config::loadArray([]);
        self::assertNull(Config::galleryUrl());
    }

    public function test_galleryUrl_returns_string_when_set(): void
    {
        Config::loadArray(['gallery_url' => 'https://example.com/gallery']);
        self::assertSame('https://example.com/gallery', Config::galleryUrl());
    }

    public function test_pictureExtensions_returns_list_from_data(): void
    {
        Config::loadArray(['picture_ext' => ['jpg', 'png']]);
        self::assertSame(['jpg', 'png'], Config::pictureExtensions());
    }
}
