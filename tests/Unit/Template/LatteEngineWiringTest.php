<?php

declare(strict_types=1);

use Piwigo\Core\Kernel;
use Piwigo\Core\Paths;
use Piwigo\Template\CurrentTemplate;
use Piwigo\Tests\Support\CurrentConfigTestFactory;
use Piwigo\Tests\Support\CurrentUserTestFactory;
use Piwigo\Tests\Support\TemplateTestFactory;

/**
 * P31.1 (Smarty -> Latte engine adapter) wiring proof -- a throwaway
 * fixture template exercising the direct-filename `parse()` dispatch,
 * `resolveLatteTemplatePath()`, the lazily-constructed `LatteEngine`,
 * `PiwigoExtension`'s filter/function registration, and the `{capture}`+
 * `{do}` composition a stateful function (`htmlHead()`) needs -- end to
 * end, before any real `.tpl` is converted. See docs/PLAN.md's P31
 * section, "Transition strategy" and "Blocks/functions".
 *
 * Not testing individual filter/function correctness (each is a thin,
 * separately-reviewable wrapper) -- testing that the wiring connecting
 * them all together actually works. Also gives `Template::
 * assignVarFromTemplate()`/`templateExists()` their first real callers
 * (both otherwise unused until a P31.2+ conversion sub-item lands).
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
    $t->smarty->assign('name', 'world');

    $output = $t->parse('fixture.latte', true);

    expect($output)
        ->toContain('<p>World</p>')
        ->toContain('<p>hello world</p>')
        ->and($t->html_head_elements)
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
    $t->smarty->assign('name', 'World');

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

test('a handle registered via setFilename() still renders through Smarty -- the old and new calling conventions coexist', function (): void {
    $t = TemplateTestFactory::build();
    $tplDir = sys_get_temp_dir() . '/piwigo-latte-wiring-test-' . bin2hex(random_bytes(8));
    mkdir($tplDir, 0o777, true);
    file_put_contents($tplDir . '/legacy.tpl', 'Hi {$name} (smarty)');
    $t->setTemplateDir($tplDir);
    $t->setFilename('legacy', 'legacy.tpl');
    $t->smarty->assign('name', 'Smarty');

    $output = $t->parse('legacy', true);

    expect($output)
        ->toBe('Hi Smarty (smarty)');

    latte_engine_wiring_test_rrmdir($tplDir);
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
        ->and($t->html_head_elements)
        ->toBe(['x']);

    latte_engine_wiring_test_rrmdir($tplDir);
});
