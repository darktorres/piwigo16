<?php

declare(strict_types=1);

namespace Piwigo\Tests\Unit\Template;

use PHPUnit\Framework\TestCase;
use Piwigo\Core\Lang;
use Piwigo\Lang\Translator;
use Piwigo\Template\LatteEngine;

/**
 * Phase A validation for §1.2 Wave 2: prove that LatteEngine + PiwigoExtension
 * boot, accept inline template source, run the `translate` and `translate_dec`
 * filters through the real Piwigo Lang/Translator pipeline, and emit the
 * substituted output. Subsequent batches port the remaining ~44 Smarty
 * plugins and convert production templates to .latte.
 */
final class LatteEngineTest extends TestCase
{
    private string $tempDir;

    #[\Override]
    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/piwigo-latte-' . uniqid('', true);
        mkdir($this->tempDir, 0o700, true);

        Lang::reset();
        Translator::reset();
        unset($GLOBALS['lang']);
    }

    #[\Override]
    protected function tearDown(): void
    {
        Lang::reset();
        Translator::reset();
        unset($GLOBALS['lang']);

        foreach (glob($this->tempDir . '/*') ?: [] as $f) {
            if (is_file($f)) {
                unlink($f);
            }
        }
        if (is_dir($this->tempDir)) {
            rmdir($this->tempDir);
        }
    }

    public function test_renders_plain_text_passthrough(): void
    {
        $engine = new LatteEngine($this->tempDir);

        self::assertSame('hello world', $engine->renderFromString('hello world'));
    }

    public function test_translate_filter_substitutes_loaded_string(): void
    {
        Lang::loadArray(['Comment' => 'Comentário']);
        $engine = new LatteEngine($this->tempDir);

        self::assertSame('Comentário', $engine->renderFromString('{$key|translate}', ['key' => 'Comment']));
    }

    public function test_translate_filter_falls_back_to_key_when_missing(): void
    {
        $engine = new LatteEngine($this->tempDir);

        self::assertSame('untranslated', $engine->renderFromString("{='untranslated'|translate}"));
    }

    public function test_translate_filter_with_sprintf_args(): void
    {
        Lang::loadArray(['n_photos' => '%d photos']);
        $engine = new LatteEngine($this->tempDir);

        self::assertSame(
            '42 photos',
            $engine->renderFromString('{$key|translate:$n}', ['key' => 'n_photos', 'n' => 42]),
        );
    }

    public function test_translate_dec_picks_singular_for_one(): void
    {
        $engine = new LatteEngine($this->tempDir);

        self::assertSame(
            '1 photo',
            $engine->renderFromString(
                '{$n|translate_dec:"%d photo","%d photos"}',
                ['n' => 1],
            ),
        );
    }

    public function test_translate_dec_picks_plural_for_many(): void
    {
        $engine = new LatteEngine($this->tempDir);

        self::assertSame(
            '5 photos',
            $engine->renderFromString(
                '{$n|translate_dec:"%d photo","%d photos"}',
                ['n' => 5],
            ),
        );
    }

    public function test_render_with_assigned_variable(): void
    {
        $engine = new LatteEngine($this->tempDir);
        $engine->assign('name', 'world');

        self::assertSame('hello world', $engine->renderFromString('hello {$name}'));
    }
}
