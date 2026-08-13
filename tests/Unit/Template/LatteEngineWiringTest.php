<?php

declare(strict_types=1);

use Piwigo\Core\Kernel;
use Piwigo\Core\Paths;
use Piwigo\Template\CurrentTemplate;
use Piwigo\Tests\Support\AdHocPageContext;
use Piwigo\Tests\Support\CurrentConfigTestFactory;
use Piwigo\Tests\Support\CurrentUserTestFactory;
use Piwigo\Tests\Support\TemplateTestFactory;

/**
 * Smarty -> Latte engine adapter wiring proof -- a throwaway
 * fixture template exercising the direct-filename `parse()` dispatch,
 * `resolveLatteTemplatePath()`, the lazily-constructed `LatteEngine`,
 * `PiwigoExtension`'s filter/function registration, and the `{capture}`+
 * `{do}` composition a stateful function (`htmlHead()`) needs -- end to
 * end.
 *
 * Not testing individual filter/function correctness (each is a thin,
 * separately-reviewable wrapper) -- testing that the wiring connecting
 * them all together actually works. Also gives `Template::
 * assignVarFromTemplate()`/`templateExists()` real callers.
 */
function latte_engine_wiring_test_rrmdir(string $dir): void
{
    if (! is_dir($dir)) {
        return;
    }
    $nodes = scandir($dir);
    foreach ($nodes !== false ? $nodes : [] as $node) {
        if ($node === '.' || $node === '..') {
            continue;
        }
        $path = $dir . '/' . $node;
        is_dir($path) ? latte_engine_wiring_test_rrmdir($path) : unlink($path);
    }
    rmdir($dir);
}

beforeEach(function (): void {
    $root = sys_get_temp_dir() . '/piwigo-latte-wiring-test-' . bin2hex(random_bytes(8));
    $this->root = $root;
    mkdir($root, 0o777, true);
    Kernel::reset();
    Kernel::boot(Paths::fromRoot($root));
    CurrentConfigTestFactory::get()->dataLocation = 'data/';
    CurrentConfigTestFactory::get()->dataDirChecked = '1';
    CurrentUserTestFactory::get()->attachGlobals();
});

afterEach(function (): void {
    latte_engine_wiring_test_rrmdir($this->root);
    CurrentUserTestFactory::get()->reset();
    CurrentConfigTestFactory::get()->reset();
    Kernel::reset();
});

test('parse() renders a real .latte file through Latte, exercising a filter, the translate filter, and {capture}+{do}', function (): void {
    $t = TemplateTestFactory::build();
    $tplDir = sys_get_temp_dir() . '/piwigo-latte-wiring-test-' . bin2hex(random_bytes(8));
    mkdir($tplDir, 0o777, true);
    file_put_contents(
        $tplDir . '/fixture.latte',
        <<<'LATTE'
        <p>{$name|ucfirst}</p>
        <p>{='hello world'|l10n}</p>
        {capture $headContent}<meta name="test" content="1">{/capture}
        {do htmlHead($headContent)}
        LATTE
        ,
    );
    $t->setTemplateDir($tplDir);
    $t->assignContext(new AdHocPageContext([
        'name' => 'world',
    ]));

    $output = $t->parse('fixture.latte', true);

    expect($output)
        ->toContain('<p>World</p>')
        ->toContain('<p>hello world</p>')
        ->and($t->htmlHeadElements)
        ->toBe(['<meta name="test" content="1">']);

    latte_engine_wiring_test_rrmdir($tplDir);
});

test('templateExists() finds a real .latte file on the template-dir chain and correctly rejects a missing one', function (): void {
    $t = TemplateTestFactory::build();
    $tplDir = sys_get_temp_dir() . '/piwigo-latte-wiring-test-' . bin2hex(random_bytes(8));
    mkdir($tplDir, 0o777, true);
    file_put_contents($tplDir . '/exists.latte', 'hi');
    $t->setTemplateDir($tplDir);

    expect($t->templateExists('exists.latte'))
        ->toBeTrue()
        ->and($t->templateExists('does-not-exist.latte'))
        ->toBeFalse();

    latte_engine_wiring_test_rrmdir($tplDir);
});

test('assignVarFromTemplate() renders a real .latte file and assigns the result wrapped in Latte\Runtime\Html', function (): void {
    $t = TemplateTestFactory::build();
    $tplDir = sys_get_temp_dir() . '/piwigo-latte-wiring-test-' . bin2hex(random_bytes(8));
    mkdir($tplDir, 0o777, true);
    file_put_contents($tplDir . '/partial.latte', 'Hello {$name}');
    $t->setTemplateDir($tplDir);
    $t->assignContext(new AdHocPageContext([
        'name' => 'World',
    ]));

    $t->assignVarFromTemplate('greeting', 'partial.latte');

    $result = $t->getTemplateVars('greeting');
    expect($result)
        ->toBeInstanceOf(Latte\Runtime\Html::class);
    // Real instanceof narrowing (not just the Pest assertion above, which
    // PHPStan can't use to narrow $result's static type) before the cast --
    // same idiom as CurrentTemplateTest.php's own container-resolve checks.
    if (! $result instanceof Latte\Runtime\Html) {
        throw new LogicException('unreachable -- asserted above');
    }
    expect((string) $result)
        ->toBe('Hello World');

    latte_engine_wiring_test_rrmdir($tplDir);
});

test('resolveLatteTemplatePath() honors a basename-keyed extents override, matching how a direct parse(\'x.latte\') call resolves', function (): void {
    // A template only ever reached via a
    // direct parse('x.latte') call gets overridden by keying
    // Template::$extents under that same real basename (see
    // ExtendForTemplatesPageRenderer's own $eligible_templates docblock).
    $t = TemplateTestFactory::build();
    $tplDir = sys_get_temp_dir() . '/piwigo-latte-wiring-test-' . bin2hex(random_bytes(8));
    mkdir($tplDir, 0o777, true);
    file_put_contents($tplDir . '/original.latte', 'original');
    $t->setTemplateDir($tplDir);

    $extDir = sys_get_temp_dir() . '/piwigo-latte-wiring-test-ext-' . bin2hex(random_bytes(8)) . '/';
    mkdir($extDir, 0o777, true);
    file_put_contents($extDir . 'replacer.latte', 'replaced');
    $t->setExtent('replacer.latte', 'original.latte', $extDir);

    $output = $t->parse('original.latte', true);

    expect($output)
        ->toBe('replaced');

    latte_engine_wiring_test_rrmdir($tplDir);
    latte_engine_wiring_test_rrmdir($extDir);
});

test('getExtent() honors a handle-keyed extents override, matching how a real {include getExtent(...)} template call resolves', function (): void {
    // Every live getExtent() call site under
    // themes/ and template-extension/ (navigation_bar.latte/
    // picture_nav_buttons.latte's {include getExtent('x.latte', 'handle')}
    // sites, menubar.latte's {include getExtent($block->template, $id)})
    // hardcodes a short opaque handle string as getExtent()'s own
    // second argument, so Template::$extents has to stay keyed by that
    // exact string for these specific partials, not by basename.
    $t = TemplateTestFactory::build();
    $extDir = sys_get_temp_dir() . '/piwigo-latte-wiring-test-ext-' . bin2hex(random_bytes(8)) . '/';
    mkdir($extDir, 0o777, true);
    file_put_contents($extDir . 'replacer_navbar.latte', 'replaced');
    $t->setExtent('replacer_navbar.latte', 'navbar', $extDir);

    expect($t->getExtent('navigation_bar.latte', 'navbar'))
        ->toBe(realpath($extDir . 'replacer_navbar.latte'));

    latte_engine_wiring_test_rrmdir($extDir);
});

test('CurrentTemplate resolves independently of PiwigoExtension holding its owning Template directly, not via the registry', function (): void {
    // PiwigoExtension takes $template directly in its constructor rather
    // than reaching through CurrentTemplate::get() -- see LatteEngine's own
    // docblock for why. This asserts that decision doesn't accidentally
    // depend on CurrentTemplate being initialised: a throwaway Template
    // instance (never registered as "the" current one, same shape as
    // MailService's own rendering instances) must still render correctly.
    $t = TemplateTestFactory::build();
    $currentTemplate = Kernel::container()->get(CurrentTemplate::class);
    expect($currentTemplate)
        ->toBeInstanceOf(CurrentTemplate::class);
    if ($currentTemplate instanceof CurrentTemplate) {
        expect($currentTemplate->isInitialized())
            ->toBeFalse();
    }

    $tplDir = sys_get_temp_dir() . '/piwigo-latte-wiring-test-' . bin2hex(random_bytes(8));
    mkdir($tplDir, 0o777, true);
    file_put_contents($tplDir . '/throwaway.latte', '{do htmlHead("x")}rendered');
    $t->setTemplateDir($tplDir);

    $output = $t->parse('throwaway.latte', true);

    expect($output)
        ->toBe('rendered')
        ->and($t->htmlHeadElements)
        ->toBe(['x']);

    latte_engine_wiring_test_rrmdir($tplDir);
});
