<?php

declare(strict_types=1);

namespace Piwigo\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use Piwigo\Core\Config;
use Piwigo\Core\ConfProxy;
use Piwigo\Core\GlobalsBridge;

final class GlobalsBridgeTest extends TestCase
{
    /** @var list<string> */
    private array $collectedDeprecations = [];

    protected function setUp(): void
    {
        Config::reset();
        Config::loadArray(['upload_dir' => './upload', 'gallery_title' => 'Test Gallery']);
        $this->collectedDeprecations = [];
        set_error_handler(function (int $errno, string $errstr): bool {
            if ($errno === E_USER_DEPRECATED) {
                $this->collectedDeprecations[] = $errstr;
            }
            return true;
        });
        GlobalsBridge::installAsConfProxy();
    }

    protected function tearDown(): void
    {
        restore_error_handler();
        // Restore $GLOBALS['conf'] to the plain Config::$data so other tests
        // that expect a plain array (not a proxy) are not affected.
        $GLOBALS['conf'] = Config::all();
    }

    public function test_install_replaces_globals_conf_with_proxy(): void
    {
        self::assertInstanceOf(ConfProxy::class, $GLOBALS['conf']);
    }

    public function test_read_emits_deprecation(): void
    {
        $_ = $GLOBALS['conf']['upload_dir'];
        self::assertCount(1, $this->collectedDeprecations);
        self::assertStringContainsString('upload_dir', $this->collectedDeprecations[0]);
        self::assertStringContainsString('read', $this->collectedDeprecations[0]);
    }

    public function test_read_returns_correct_value(): void
    {
        $val = @$GLOBALS['conf']['gallery_title'];
        self::assertSame('Test Gallery', $val);
    }

    public function test_write_emits_deprecation_and_syncs_typed_config(): void
    {
        $GLOBALS['conf']['gallery_title'] = 'New Title';
        self::assertCount(1, $this->collectedDeprecations);
        self::assertStringContainsString('write', $this->collectedDeprecations[0]);
        self::assertSame('New Title', Config::galleryTitle());
    }

    public function test_isset_is_silent(): void
    {
        $_ = isset($GLOBALS['conf']['upload_dir']);
        self::assertCount(0, $this->collectedDeprecations);
    }

    public function test_unset_emits_deprecation_and_deletes_from_typed_config(): void
    {
        unset($GLOBALS['conf']['upload_dir']);
        self::assertCount(1, $this->collectedDeprecations);
        self::assertStringContainsString('unset', $this->collectedDeprecations[0]);
        self::assertFalse(Config::has('upload_dir'));
    }

    public function test_missing_key_read_emits_deprecation(): void
    {
        $_ = @$GLOBALS['conf']['nonexistent_key'];
        self::assertCount(1, $this->collectedDeprecations);
    }

    public function test_config_get_does_not_go_through_proxy(): void
    {
        // Config::get() reads from Config::$data, not the proxy — zero deprecations.
        $val = Config::get('upload_dir');
        self::assertCount(0, $this->collectedDeprecations);
        self::assertSame('./upload', $val);
    }

    public function test_proxy_value_and_typed_getter_stay_in_sync_after_write(): void
    {
        @$GLOBALS['conf']['gallery_title'] = 'Synced Title';
        // Typed getter must see the new value.
        self::assertSame('Synced Title', Config::galleryTitle());
        // Proxy must also return the new value.
        self::assertSame('Synced Title', @$GLOBALS['conf']['gallery_title']);
    }
}
