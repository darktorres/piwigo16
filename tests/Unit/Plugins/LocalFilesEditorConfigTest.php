<?php

declare(strict_types=1);

namespace Piwigo\Tests\Unit\Plugins;

use PHPUnit\Framework\TestCase;
use Piwigo\Config\Config as PiwigoConfig;
use Piwigo\Plugins\LocalFilesEditor\Config;

final class LocalFilesEditorConfigTest extends TestCase
{
    protected function setUp(): void
    {
        PiwigoConfig::reset();
    }

    protected function tearDown(): void
    {
        PiwigoConfig::reset();
    }

    public function test_tabs_returns_default_when_unset(): void
    {
        self::assertSame(['localconf', 'css', 'tpl', 'lang', 'plug'], Config::tabs());
    }

    public function test_tabs_returns_stored_value(): void
    {
        PiwigoConfig::override('LocalFilesEditor_tabs', ['localconf', 'css']);
        self::assertSame(['localconf', 'css'], Config::tabs());
    }

    public function test_tabs_returns_default_when_stored_value_is_not_array(): void
    {
        PiwigoConfig::override('LocalFilesEditor_tabs', 'not-an-array');
        self::assertSame(['localconf', 'css', 'tpl', 'lang', 'plug'], Config::tabs());
    }

    public function test_schema_method_matches_accessor(): void
    {
        self::assertArrayHasKey('LocalFilesEditor_tabs', Config::SCHEMA);
        self::assertSame('tabs', Config::SCHEMA['LocalFilesEditor_tabs']['method']);
        self::assertTrue(method_exists(Config::class, 'tabs'));
    }
}
