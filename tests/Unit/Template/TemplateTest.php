<?php

declare(strict_types=1);

use Piwigo\Common\ValueObject\ThemeId;
use Piwigo\Core\HtmlRenderingInterface;
use Piwigo\Core\Kernel;
use Piwigo\Core\Lang;
use Piwigo\Core\Paths;
use Piwigo\Core\TemplatePageContext;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Template\CurrentTemplate;
use Piwigo\Template\Template;
use Piwigo\Tests\Support\CurrentConfigTestFactory;
use Piwigo\Tests\Support\CurrentUserTestFactory;
use Piwigo\Tests\Support\KernelContainerOverride;
use Piwigo\Tests\Support\LangTestFactory;
use Piwigo\Tests\Support\TemplateTestFactory;
use Piwigo\Tests\Support\TranslatorTestFactory;

// LangTestFactory::get() is a live container resolve with no pre-boot
// fallback (unlike TranslatorTestFactory::get()), so this file
// boots/resets a real Kernel around each test. A real Paths must be
// supplied to boot() too -- Lang's own constructor needs one, and PHP-DI
// can't autowire Paths on its own (every property is a required string
// with no default); TemplateTestFactory::build() resolves the exact same
// container-shared CurrentConfig/Lang instances these tests manipulate
// directly, so state set before construction is visible through
// $this->currentConfig/$this->lang at call time.
// setDataDirChecked('1') below skips Template::__construct()'s own
// dataDirChecked()===null branch entirely -- that branch's own
// $this->currentConfigService->get() call throws in this Unit test (never
// set() here, unlike a real request/RequestBootstrap::connect()), same
// "point CurrentPaths/Paths at a fresh temp root, then setDataDirChecked()
// before constructing" workaround HtmlServiceTest.php's own docblock
// already established for this identical scenario.

beforeEach(function (): void {
    // A prior test file left Kernel booted without resetting first would
    // otherwise make the boot() call below silently no-op, leaving
    // CurrentPathsTestFactory pointed at whatever root that earlier boot
    // bound instead of this fixture root.
    Kernel::reset();
    Kernel::boot(Paths::fromRoot(sys_get_temp_dir()));
    CurrentConfigTestFactory::get()->dataDirChecked = '1';
});

afterEach(function (): void {
    CurrentUserTestFactory::get()->reset();
    LangTestFactory::get()->reset();
    TranslatorTestFactory::get()->reset();
    CurrentConfigTestFactory::get()->reset();
    Kernel::reset();
});

/**
 * Local to this file (deliberately not shared with
 * TemplateInstanceTest.php's own `template_instance_test_rrmdir()`,
 * which would collide as a duplicate global function declaration if both
 * files were ever loaded together) -- recursively removes a temp theme
 * fixture directory tree created by a single test.
 */
function template_test_rrmdir(string $dir): void
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
        is_dir($path) ? template_test_rrmdir($path) : unlink($path);
    }
    rmdir($dir);
}

/**
 * Narrows Template::getTemplateVars('themes')'s mixed return (same
 * shape TemplateInstanceTest.php's own template_instance_test_themes()
 * narrows) down to the list of per-theme arrays setTheme() itself always
 * appends.
 *
 * @return list<array<string, mixed>>
 */
function template_test_themes(Template $t): array
{
    $themes = $t->getTemplateVars('themes');
    if (! is_array($themes) || ! array_is_list($themes)) {
        throw new RuntimeException('Expected themes to be a list, got ' . get_debug_type($themes));
    }

    $narrowed = [];
    foreach ($themes as $theme) {
        if (! is_array($theme)) {
            throw new RuntimeException('Expected a theme entry array, got ' . get_debug_type($theme));
        }

        $assoc = [];
        foreach ($theme as $key => $value) {
            if (! is_string($key)) {
                throw new RuntimeException('Expected a string-keyed array, found key ' . get_debug_type($key));
            }

            $assoc[$key] = $value;
        }

        $narrowed[] = $assoc;
    }

    return $narrowed;
}

test('assignContext flattens a TemplatePageContext to individually-assigned template vars', function (): void {
    $template = TemplateTestFactory::build();
    $context = new class() implements TemplatePageContext {
        public function toArray(): array
        {
            return [
                'FOO' => 'bar',
                'baz' => 42,
            ];
        }
    };

    $template->assignContext($context);

    expect($template->getTemplateVars('FOO'))
        ->toBe('bar')
        ->and($template->getTemplateVars('baz'))
        ->toBe(42);
});

// --- container-resolver LogicException guards -------------------------------
//
// Every real Kernel::container()->get(X::class) call in config/container.php
// legitimately produces a real instance of X -- these `!$x instanceof X`
// guards only fire on container misconfiguration, reproduced here via
// KernelContainerOverride's own established "rebind one class to a plain
// stdClass" seam (already the precedent for this exact
// "Container returned an unexpected type for" shape, see e.g.
// tests/Unit/Bootstrap/InfrastructureAccessorTest.php). Each test restores
// a real Kernel::boot() afterward -- KernelContainerOverride::with()'s own
// `finally` leaves Kernel unbooted once the exception propagates out past
// it, and this file's own shared afterEach (LangTestFactory::get()->reset()
// in particular, which has no pre-boot fallback) requires a booted Kernel.

test('urlService resolver throws when the container returns an unexpected type', function (): void {
    // Construction alone no longer touches urlService() (the old Smarty
    // modifier registrations that resolved it eagerly are gone) -- parse()
    // is the real, live call site that still needs it, for its own
    // ROOT_URL assign. Passing __FILE__ (a real, absolute, existing path)
    // takes resolveLatteTemplatePath()'s own short-circuit branch, so
    // parse() reaches the urlService() call instead of failing earlier
    // via htmlRenderer()->fatalError() on an unresolvable file -- Latte
    // never actually renders this PHP file, since urlService() throws
    // first.
    $t = TemplateTestFactory::build();

    expect(static fn (): mixed => KernelContainerOverride::with(
        [
            UrlServiceInterface::class => new stdClass(),
        ],
        static fn (): string => $t->parse(__FILE__)
    ))->toThrow(LogicException::class, 'Container returned an unexpected type for ' . UrlServiceInterface::class);

    Kernel::boot(Paths::fromRoot(sys_get_temp_dir()));
    CurrentConfigTestFactory::get()->dataDirChecked = '1';
});

test('lang resolver throws when the container returns an unexpected type', function (): void {
    expect(static fn (): mixed => KernelContainerOverride::with(
        [
            Lang::class => new stdClass(),
        ],
        static fn (): Lang => Template::lang()
    ))->toThrow(LogicException::class, 'Container returned an unexpected type for ' . Lang::class);

    Kernel::boot(Paths::fromRoot(sys_get_temp_dir()));
    CurrentConfigTestFactory::get()->dataDirChecked = '1';
});

test('htmlRenderer resolver throws when the container returns an unexpected type', function (): void {
    $t = TemplateTestFactory::build();

    expect(static fn (): mixed => KernelContainerOverride::with(
        [
            HtmlRenderingInterface::class => new stdClass(),
        ],
        static fn (): string => $t->parse('no-such-handle')
    ))->toThrow(LogicException::class, 'Container returned an unexpected type for ' . HtmlRenderingInterface::class);

    Kernel::boot(Paths::fromRoot(sys_get_temp_dir()));
    CurrentConfigTestFactory::get()->dataDirChecked = '1';
});

test('currentTemplate resolver throws when the container returns an unexpected type', function (): void {
    $t = TemplateTestFactory::build();

    // currentTemplate()'s own docblock: its only real caller is
    // finalizeOutput()'s cssLoader->getCss() call, whose own first
    // argument (self::urlService(), evaluated before currentTemplate())
    // transitively resolves HtmlService -- which independently needs
    // CurrentTemplate as a natively-typed constructor param -- so going
    // through finalizeOutput() trips PHP's own TypeError on THAT
    // unrelated construction first, before this method's own manual
    // instanceof guard ever runs.
    // Invoking the private currentTemplate() directly is the only way to
    // reach its own guard in isolation.
    $currentTemplateMethod = new ReflectionMethod(Template::class, 'currentTemplate');

    expect(static fn (): mixed => KernelContainerOverride::with(
        [
            CurrentTemplate::class => new stdClass(),
        ],
        static fn (): mixed => $currentTemplateMethod->invoke($t)
    ))->toThrow(LogicException::class, 'Container returned an unexpected type for ' . CurrentTemplate::class);

    Kernel::boot(Paths::fromRoot(sys_get_temp_dir()));
    CurrentConfigTestFactory::get()->dataDirChecked = '1';
});

// --- setTheme: load_parent_css / load_parent_local_head propagation --------

test('setTheme lets a parent theme\'s own load_parent_css/load_parent_local_head themeconf keys override the caller-passed load flags', function (): void {
    $root = sys_get_temp_dir() . '/piwigo-template-test-' . bin2hex(random_bytes(8));
    $parentDir = $root . '/gap-parent';
    $childDir = $root . '/gap-child';
    mkdir($parentDir, 0o777, true);
    mkdir($childDir, 0o777, true);
    file_put_contents($parentDir . '/local_head.tpl', 'x');
    file_put_contents(
        $parentDir . '/theme.json',
        json_encode([
            'localHead' => 'local_head.tpl',
        ], JSON_THROW_ON_ERROR),
    );
    file_put_contents(
        $childDir . '/theme.json',
        json_encode([
            'parent' => 'gap-parent',
            'loadParentCss' => false,
            'loadParentLocalHead' => false,
        ], JSON_THROW_ON_ERROR),
    );

    $t = TemplateTestFactory::build();
    // Default load_css/load_local_head are both true -- the child
    // themeconf's own load_parent_css=false/load_parent_local_head=false
    // must win over those caller-passed defaults for the recursive
    // parent-theme call.
    $t->setTheme($root, ThemeId::from('gap-child'), 'template');

    $themes = template_test_themes($t);
    expect($themes)
        ->toHaveCount(2);
    // The parent's own setTheme() call (and therefore its own
    // append('themes', ...)) runs during the child's recursive call,
    // before the child appends its own entry -- so the parent's entry
    // comes first.
    expect($themes[0]['id'])->toBe('gap-parent');
    // A CoalesceRemoveLeft or TernaryNegated mutation on either
    // load_parent_css or load_parent_local_head makes this recursive call
    // receive the caller's own load_css/load_local_head (both true)
    // instead of the themeconf-forced false.
    expect($themes[0]['load_css'])->toBeFalse();
    expect($themes[0])->not->toHaveKey('local_head');
    expect($themes[1]['id'])->toBe('gap-child');
    expect($themes[1]['load_css'])->toBeTrue();

    template_test_rrmdir($root);
});

test('setTheme does not recurse into a non-string parent themeconf value', function (): void {
    // Real gap: a LogicalAndToLogicalOr mutation on this guard's own first
    // `and` (isset(parent) and is_string(parent)) groups the first two
    // clauses into an `or` instead -- isset(parent) alone being true is
    // enough to trigger the recursive setTheme() call even when parent
    // isn't a string, appending a second, unintended themes entry with a
    // non-string 'id'. A non-string parent value proves the real `and`
    // (not `or`) is what prevents that recursion.
    // loadThemeJson() itself already drops a non-string 'parent' before
    // setTheme() ever sees it (a schema-invalid theme.json a real
    // ThemeRegistry scan would reject outright) -- this fixture writes the
    // raw JSON directly (bypassing schema validation, which loadThemeJson()
    // never applies) specifically to prove setTheme()'s own guard is a
    // real second layer, not dead code now that the first layer also
    // filters this case.
    $root = sys_get_temp_dir() . '/piwigo-template-test-' . bin2hex(random_bytes(8));
    $childDir = $root . '/gap-child-nonstring-parent';
    mkdir($childDir, 0o777, true);
    file_put_contents(
        $childDir . '/theme.json',
        json_encode([
            'parent' => 123,
        ], JSON_THROW_ON_ERROR),
    );

    $t = TemplateTestFactory::build();
    $t->setTheme($root, ThemeId::from('gap-child-nonstring-parent'), 'template');

    $themes = template_test_themes($t);
    expect($themes)
        ->toHaveCount(1)
        ->and($themes[0]['id'])->toBe('gap-child-nonstring-parent');

    template_test_rrmdir($root);
});

// --- loadThemeconf caching ---------------------------------------------------

test('loadThemeconf caches the computed themeconf so a second call for the same directory does not re-read a changed file', function (): void {
    $root = sys_get_temp_dir() . '/piwigo-template-test-' . bin2hex(random_bytes(8));
    $themeDir = $root . '/cache-theme';
    mkdir($themeDir, 0o777, true);
    file_put_contents($themeDir . '/theme.json', json_encode([
        'colorscheme' => 'first',
    ], JSON_THROW_ON_ERROR));

    $t = TemplateTestFactory::build();

    $first = $t->loadThemeconf($themeDir);
    // If loadThemeconf() genuinely cached the first computed result under
    // this exact directory's cache key, a changed file on disk must never
    // be observed by a second call for the same directory.
    file_put_contents($themeDir . '/theme.json', json_encode([
        'colorscheme' => 'second',
    ], JSON_THROW_ON_ERROR));
    $second = $t->loadThemeconf($themeDir);

    expect($first['colorscheme'])->toBe('first');
    expect($second['colorscheme'])->toBe('first');

    template_test_rrmdir($root);
});
