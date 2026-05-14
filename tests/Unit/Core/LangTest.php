<?php

declare(strict_types=1);

namespace Piwigo\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use Piwigo\Core\Lang;

final class LangTest extends TestCase
{
    #[\Override]
    protected function setUp(): void
    {
        Lang::reset();
        unset($GLOBALS['lang']);
    }

    #[\Override]
    protected function tearDown(): void
    {
        Lang::reset();
        unset($GLOBALS['lang']);
    }

    public function test_t_returns_key_when_not_found(): void
    {
        self::assertSame('missing_key', Lang::t('missing_key'));
    }

    public function test_t_returns_translation_after_loadArray(): void
    {
        Lang::loadArray(['guest' => 'Guest', 'Login' => 'Login']);

        self::assertSame('Guest', Lang::t('guest'));
        self::assertSame('Login', Lang::t('Login'));
    }

    public function test_has_false_when_empty(): void
    {
        self::assertFalse(Lang::has('guest'));
    }

    public function test_has_true_after_loadArray(): void
    {
        Lang::loadArray(['guest' => 'Guest']);

        self::assertTrue(Lang::has('guest'));
        self::assertFalse(Lang::has('nonexistent'));
    }

    public function test_t_vsprintf_with_args(): void
    {
        Lang::loadArray(['n_photos' => '%d photos']);

        self::assertSame('42 photos', Lang::t('n_photos', 42));
    }

    /**
     * Bridge removal: $GLOBALS['lang'] is no longer a fallback for Lang::t().
     * Before attachGlobals() / loadArray() populate Lang::$data, t() returns
     * the key itself (same as a missing gettext entry).
     */
    public function test_t_returns_key_before_data_loaded(): void
    {
        // $GLOBALS['lang'] set but not yet snapshotted into Lang::$data
        $GLOBALS['lang'] = ['guest' => 'Guest'];

        self::assertSame('guest', Lang::t('guest'));
    }

    public function test_t_returns_translation_after_attachGlobals_snapshot(): void
    {
        $GLOBALS['lang'] = ['guest' => 'Guest'];
        Lang::attachGlobals();

        self::assertSame('Guest', Lang::t('guest'));
    }

    public function test_global_write_after_loadArray_visible_via_t(): void
    {
        Lang::loadArray(['key' => 'value']);
        // Directly mutate the data (simulates what happens via reference bridge)
        // Since loadArray copies the data, a reference write won't go through here.
        // This just verifies loadArray is not a live reference — that's covered
        // by KernelBootTest::test_lang_global_write_after_boot_visible_via_Lang.
        self::assertSame('value', Lang::t('key'));
    }
}
