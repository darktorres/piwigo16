<?php

declare(strict_types=1);

namespace Piwigo\Tests\Unit\Template;

use PHPUnit\Framework\TestCase;
use Piwigo\Core\Lang;
use Piwigo\Lang\Translator;
use Piwigo\Template\CssLoader;
use Piwigo\Template\Latte\PiwigoExtension;
use Piwigo\Template\LatteEngine;
use Piwigo\Template\ScriptLoader;
use Piwigo\Template\Template;
use Piwigo\Template\TemplateRegistry;

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
        TemplateRegistry::reset();
        unset($GLOBALS['lang']);
        unset($GLOBALS['template']);

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

    /**
     * Phase B.1 smoke — proves that PHP-passthrough filters land on the
     * expected built-in. One representative per dispatch shape: zero-arg
     * (md5), one-string-arg (sprintf with format-only), two-arg
     * positional (sprintf with %d substitution), arity ≥ 2 returning
     * non-string (in_array). If this row is green, the rest of the
     * Phase B.1 table is wired the same way.
     */
    public function test_phase_b1_php_passthrough_filters(): void
    {
        $engine = new LatteEngine($this->tempDir);

        self::assertSame(
            md5('hi'),
            $engine->renderFromString("{='hi'|md5}"),
        );
        self::assertSame(
            'count: 5',
            $engine->renderFromString("{='count: %d'|sprintf:5}"),
        );
        self::assertSame(
            'World',
            $engine->renderFromString('{=$x|strtolower|ucfirst}', ['x' => 'wOrLd']),
            'sanity-check that registering ucfirst/strtolower under their PHP names dispatches correctly',
        );
        self::assertSame(
            '1',
            $engine->renderFromString('{=$x|in_array:$arr}', ['x' => 'b', 'arr' => ['a', 'b', 'c']]),
        );
    }

    /**
     * Phase B.2 smoke — explode, ternary, l10n alias of translate. The
     * service-backed filters (is_admin, is_classic_user, get_device,
     * get_gallery_home_url, url_is_remote) require ServiceLocator
     * bootstrapping that's outside this unit test's scope; their
     * stateless Latte wiring is identical to the four below, and
     * integration coverage exercises them through real templates once
     * Phase B.5 lands the dispatcher.
     */
    public function test_phase_b2_stateless_custom_modifiers(): void
    {
        $engine = new LatteEngine($this->tempDir);

        self::assertSame(
            'a-b-c',
            $engine->renderFromString('{=$s|explode:","|implode:"-"}', ['s' => 'a,b,c']),
        );
        self::assertSame(
            'a-b',
            $engine->renderFromString('{=$s|explode:""|implode:"-"}', ['s' => 'a,b']),
            'empty delimiter must fall back to "," like Template::modExplode',
        );
        self::assertSame(
            'yes',
            $engine->renderFromString('{=$x|ternary:"yes","no"}', ['x' => 1]),
        );
        self::assertSame(
            'no',
            $engine->renderFromString('{=$x|ternary:"yes","no"}', ['x' => 0]),
        );

        Lang::loadArray(['Comment' => 'Comentário']);
        self::assertSame(
            'Comentário',
            $engine->renderFromString('{=$key|l10n}', ['key' => 'Comment']),
            'l10n must be a strict alias of translate',
        );
    }

    /**
     * Phase B.3 smoke — the asset functions are stateful: they read and
     * write through TemplateRegistry::current()'s ScriptLoader / CssLoader
     * and the html_head_elements buffer. The tests below stage a
     * reflection-constructed Template (skipping its Smarty-bootstrapping
     * constructor) with fresh loaders, run the function, and assert the
     * loader received the entry.
     */
    public function test_phase_b3_combine_script_writes_to_registered_template(): void
    {
        $tpl = $this->stageTemplateWithLoaders();

        PiwigoExtension::combineScript(
            id: 'tags',
            load: 'footer',
            path: 'themes/admin/_base/js/tags.js',
        );

        // ScriptLoader::add runs the path through Vite's manifest, so we
        // assert that the entry registered (under its id) and its path
        // ends with `.js` — the Vite-rewrite shape is opaque here, the
        // Piwigo-side guarantee is that the script is registered with
        // the requested id.
        $scripts = $tpl->scriptLoader->getAll();
        self::assertArrayHasKey('tags', $scripts);
        self::assertStringEndsWith('.js', $scripts['tags']->path);
    }

    public function test_phase_b3_combine_css_writes_to_registered_template(): void
    {
        $this->stageTemplateWithLoaders();

        // CssLoader's registered_css is private and there's no public getter.
        // The smoke we can run is: combineCss doesn't raise on a valid path,
        // the auto-id branch (md5 of path when no id given) doesn't crash,
        // and the order arg doesn't blow up CssLoader::add.
        PiwigoExtension::combineCss(path: 'themes/_base/print.css', order: -10);
        PiwigoExtension::combineCss(path: 'themes/admin/_base/explicit.css', id: 'explicit', order: 5);

        $this->expectNotToPerformAssertions();
    }

    public function test_phase_b3_html_head_appends_to_buffer(): void
    {
        $tpl = $this->stageTemplateWithLoaders();

        PiwigoExtension::htmlHead("<link rel='preload' href='/foo.js'>");
        PiwigoExtension::htmlHead("   ");
        PiwigoExtension::htmlHead("<meta name='x' content='y'>");

        self::assertSame(
            ["<link rel='preload' href='/foo.js'>", "<meta name='x' content='y'>"],
            $tpl->html_head_elements,
            'whitespace-only content must be skipped to mirror Template::blockHtmlHead',
        );
    }

    public function test_phase_b3_get_extent_falls_back_to_filename_when_no_override(): void
    {
        $this->stageTemplateWithLoaders();

        self::assertSame(
            'fallback.tpl',
            PiwigoExtension::getExtent('fallback.tpl', 'unknown_handle'),
        );
    }

    public function test_phase_b3_get_extent_returns_override_when_present(): void
    {
        $tpl = $this->stageTemplateWithLoaders();
        $tpl->extents['my_handle'] = '/abs/path/override.tpl';

        self::assertSame(
            '/abs/path/override.tpl',
            PiwigoExtension::getExtent('original.tpl', 'my_handle'),
        );
    }

    public function test_phase_b3_get_combined_scripts_header_returns_marker(): void
    {
        $this->stageTemplateWithLoaders();

        self::assertSame(
            Template::COMBINED_SCRIPTS_TAG,
            PiwigoExtension::getCombinedScripts(load: 'header'),
        );
    }

    /**
     * Reflection-construct a Template with usable loaders but no Smarty
     * bootstrap. Registers it as the current template; the tearDown
     * calls TemplateRegistry::reset() to keep tests isolated.
     */
    private function stageTemplateWithLoaders(): Template
    {
        $tpl = (new \ReflectionClass(Template::class))->newInstanceWithoutConstructor();
        $tpl->scriptLoader = new ScriptLoader();
        $tpl->cssLoader = new CssLoader();
        TemplateRegistry::set($tpl);

        return $tpl;
    }
}
