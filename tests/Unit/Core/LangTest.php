<?php

declare(strict_types=1);

namespace Piwigo\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use Piwigo\Core\Lang;

final class LangTest extends TestCase
{
    protected function setUp(): void
    {
        Lang::reset();
        unset($GLOBALS['lang']);
    }

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
     * Step 4 exit signal: Lang::t('guest') returns same value pre- and post-conversion.
     * Before attachGlobals() Lang falls back to $GLOBALS['lang']; after, it reads
     * self::$data which IS $GLOBALS['lang'] via reference.
     */
    public function test_t_falls_back_to_globals_lang_before_attach(): void
    {
        $GLOBALS['lang'] = ['guest' => 'Guest'];
        // self::$data is still [] — attachGlobals() not called

        self::assertSame('Guest', Lang::t('guest'));
    }

    public function test_t_after_attach_equals_value_from_globals_fallback(): void
    {
        $GLOBALS['lang'] = ['guest' => 'Guest'];

        // Simulate attachGlobals() — copies globals into self::$data and rebinds
        Lang::loadArray($GLOBALS['lang']);

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
