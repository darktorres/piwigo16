<?php

declare(strict_types=1);

use Piwigo\Common\ValueObject\LangCode;
use Piwigo\Common\ValueObject\UserId;
use Piwigo\Common\ValueObject\Username;
use Piwigo\Core\HtmlRenderingInterface;
use Piwigo\Core\Lang;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Template\CurrentTemplate;
use Piwigo\Tests\Support\CurrentUserTestFactory;
use Piwigo\Tests\Support\KernelContainerOverride;
use Piwigo\Tests\Support\TemplateTestFactory;
use Piwigo\Users\User;
use Piwigo\Users\UserStatus;
use Smarty\Smarty;
use Piwigo\Tests\Support\CurrentConfigTestFactory;
use Piwigo\Core\Kernel;
use Piwigo\Core\TemplatePageContext;
use Piwigo\Tests\Support\LangTestFactory;
use Piwigo\Core\Paths;
use Piwigo\Tests\Support\TranslatorTestFactory;
use Piwigo\Template\Template;

// get_php_str_val() stays a static, instance-free utility (the only
// remaining eval() surface in this codebase) -- tested directly with no
// Template instance needed. modcompiler_translate()/modcompiler_translate_dec()
// are real instance methods, reading their own compile-time
// CurrentConfig/Lang checks onto $this->currentConfig/$this->lang -- the
// literal compiled-cache-text return values they still produce are
// untouched, a documented permanent exception (see Template's own
// docblock) -- so these tests build a real instance via
// TemplateTestFactory::build(), the same "point CurrentPaths/Paths
// at a fresh temp root" way PictureRateRendererTest.php's own docblock
// establishes elsewhere in this suite.
//
// modcompiler_translate() goes through $this->lang->t(); Lang is a real,
// container-shared instance that delegates to TranslatorTestFactory::get()'s
// singleton -- reset both, matching LangTest.php's own established
// pattern, so no test's loaded PO state/lang table leaks into another.
// LangTestFactory::get() is a live container resolve with no pre-boot
// fallback (unlike TranslatorTestFactory::get()), so this file also
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
    Kernel::boot(Paths::fromRoot(sys_get_temp_dir()));
    CurrentConfigTestFactory::get()->setDataDirChecked('1');
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
 * Narrows Template::get_template_vars('themes')'s mixed return (same
 * shape TemplateInstanceTest.php's own template_instance_test_themes()
 * narrows) down to the list of per-theme arrays set_theme() itself always
 * appends.
 *
 * @return list<array<string, mixed>>
 */
function template_test_themes(Template $t): array
{
    $themes = $t->get_template_vars('themes');
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
    $context = new class () implements TemplatePageContext {
        public function toArray(): array
        {
            return ['FOO' => 'bar', 'baz' => 42];
        }
    };

    $template->assignContext($context);

    expect($template->get_template_vars('FOO'))->toBe('bar')
        ->and($template->get_template_vars('baz'))->toBe(42);
});

test('get_php_str_val evaluates a single-quoted PHP string literal', function (): void {
    expect(Template::get_php_str_val("'hello world'"))->toBe('hello world');
});

test('get_php_str_val evaluates a double-quoted PHP string literal', function (): void {
    expect(Template::get_php_str_val('"hello world"'))->toBe('hello world');
});

test('get_php_str_val returns null for an unquoted value', function (): void {
    expect(Template::get_php_str_val('$variable'))->toBeNull();
});

test('get_php_str_val returns null for a string too short to be quoted', function (): void {
    expect(Template::get_php_str_val("'"))->toBeNull();
});

test('get_php_str_val evaluates a minimal 2-character empty single-quoted string', function (): void {
    expect(Template::get_php_str_val("''"))->toBe('');
});

test('get_php_str_val checks the first character for a matching quote, not just the last', function (): void {
    // Ends with a single quote but does not start with one -- under a
    // buggy "only check the last character" implementation this would
    // wrongly look like a valid quoted literal and attempt to eval() the
    // syntactically invalid "5'", instead of returning null cleanly.
    expect(Template::get_php_str_val("5'"))->toBeNull();
});

test('get_php_str_val checks the first character for a matching double-quote, not just the last', function (): void {
    expect(Template::get_php_str_val('5"'))->toBeNull();
});

test('modcompiler_translate returns a cached lang lookup when compiled_template_cache_language is on', function (): void {
    CurrentConfigTestFactory::get()->setCompiledTemplateCacheLanguage(true);
    LangTestFactory::get()->loadArray(['Comment' => 'Commentaire']);

    $result = TemplateTestFactory::build()->modcompiler_translate(["'Comment'"]);

    expect($result)->toBe(var_export('Commentaire', true));
});

test('modcompiler_translate falls back to a runtime Template::lang()->t() call when caching is off', function (): void {
    CurrentConfigTestFactory::get()->setCompiledTemplateCacheLanguage(false);
    LangTestFactory::get()->loadArray(['Comment' => 'Commentaire']);

    $result = TemplateTestFactory::build()->modcompiler_translate(["'Comment'"]);

    expect($result)->toBe("\\Piwigo\\Template\\Template::lang()->t('Comment')");
});

test('modcompiler_translate falls back to a runtime Template::lang()->t() call when the key is not in the cached lang table', function (): void {
    CurrentConfigTestFactory::get()->setCompiledTemplateCacheLanguage(true);
    LangTestFactory::get()->loadArray([]);

    $result = TemplateTestFactory::build()->modcompiler_translate(["'Unknown'"]);

    expect($result)->toBe("\\Piwigo\\Template\\Template::lang()->t('Unknown')");
});

test('modcompiler_translate wraps a runtime Template::lang()->t() call in sprintf when extra params are given', function (): void {
    CurrentConfigTestFactory::get()->setCompiledTemplateCacheLanguage(false);
    LangTestFactory::get()->loadArray([]);

    $result = TemplateTestFactory::build()->modcompiler_translate(["'%d comments'", '$count']);

    expect($result)->toBe("\\Piwigo\\Template\\Template::lang()->t('%d comments',\$count)");
});

test('modcompiler_translate_dec falls back to a runtime Template::lang()->plural() call when caching is off', function (): void {
    CurrentConfigTestFactory::get()->setCompiledTemplateCacheLanguage(false);

    $result = TemplateTestFactory::build()->modcompiler_translate_dec(['$count', "'%d comment'", "'%d comments'"]);

    expect($result)->toBe("\\Piwigo\\Template\\Template::lang()->plural('%d comment','%d comments',\$count)");
});

test('modcompiler_translate wraps a cached lang lookup in sprintf when extra params are given and caching is on', function (): void {
    CurrentConfigTestFactory::get()->setCompiledTemplateCacheLanguage(true);
    LangTestFactory::get()->loadArray(['%d comments' => '%d commentaires']);

    $result = TemplateTestFactory::build()->modcompiler_translate(["'%d comments'", '$count']);

    expect($result)->toBe("sprintf(" . var_export('%d commentaires', true) . ',$count)');
});

test('modcompiler_translate_dec builds a plain >1 ternary from cached lang lookups when caching is on and zero is not plural', function (): void {
    CurrentConfigTestFactory::get()->setCompiledTemplateCacheLanguage(true);
    LangTestFactory::get()->setLangInfo(['zero_plural' => false]);
    LangTestFactory::get()->loadArray(['%d comment' => '%d commentaire', '%d comments' => '%d commentaires']);

    $result = TemplateTestFactory::build()->modcompiler_translate_dec(['$count', "'%d comment'", "'%d comments'"]);

    expect($result)->toBe("sprintf((\$tmp=(\$count))>1?'%d commentaires':'%d commentaire',\$tmp)");
});

test('modcompiler_translate_dec also treats zero as plural when zero_plural is set', function (): void {
    CurrentConfigTestFactory::get()->setCompiledTemplateCacheLanguage(true);
    LangTestFactory::get()->setLangInfo(['zero_plural' => true]);
    LangTestFactory::get()->loadArray(['%d comment' => '%d commentaire', '%d comments' => '%d commentaires']);

    $result = TemplateTestFactory::build()->modcompiler_translate_dec(['$count', "'%d comment'", "'%d comments'"]);

    expect($result)->toBe("sprintf((\$tmp=(\$count))>1||\$tmp==0?'%d commentaires':'%d commentaire',\$tmp)");
});

test('mod_explode splits on the given delimiter', function (): void {
    expect(Template::mod_explode('a,b,c', ','))->toBe(['a', 'b', 'c']);
});

test('mod_explode defaults to comma when no delimiter is given', function (): void {
    expect(Template::mod_explode('a,b,c'))->toBe(['a', 'b', 'c']);
});

test('mod_explode throws for an empty delimiter', function (): void {
    Template::mod_explode('a,b,c', '');
})->throws(Exception::class, 'mod_explode(): delimiter must not be empty');

test('mod_ternary returns the true branch for a truthy param', function (): void {
    expect(Template::mod_ternary(1, 'yes', 'no'))->toBe('yes');
});

test('mod_ternary returns the false branch for a falsy param', function (): void {
    expect(Template::mod_ternary(0, 'yes', 'no'))->toBe('no');
});

test('mod_ternary returns the false branch for an empty string param', function (): void {
    expect(Template::mod_ternary('', 'yes', 'no'))->toBe('no');
});

test('postfilter_language replaces a compiled echo-string-literal with its evaluated value', function (): void {
    // $smarty is genuinely unused by this method's own body -- a bare
    // Smarty instance (no template dirs configured) is enough to satisfy
    // the parameter type.
    $result = Template::postfilter_language("<?php echo 'Hello World';?>\n", new Smarty());

    expect($result)->toBe('Hello World');
});

test('postfilter_language handles a double-quoted literal and leaves non-matching PHP untouched', function (): void {
    $result = Template::postfilter_language("<?php echo \"Bonjour\";?>\n<?php \$x = 1; ?>\n", new Smarty());

    expect($result)->toBe("Bonjour<?php \$x = 1; ?>\n");
});

test('prefilter_white_space strips leading whitespace before every recognized tag, and their closing counterparts where applicable', function (): void {
    // Trailing "END" sentinel line: without something after the last real
    // tag, \s*$ (greedy, multiline) would swallow the source string's own
    // final newline too -- a pre-existing quirk of this regex, not
    // something this test is trying to pin down.
    $source = "  {if x}\n  {/if}\n  {foreach x}\n  {/foreach}\n  {section x}\n  {/section}\n  {footer_script}\n  {/footer_script}\n  {include x}\n  {else}\n  {combine_script x}\n  {html_head}\nEND\n";

    $result = Template::prefilter_white_space($source, new Smarty());

    $expected = "{if x}\n{/if}\n{foreach x}\n{/foreach}\n{section x}\n{/section}\n{footer_script}\n{/footer_script}\n{include x}\n{else}\n{combine_script x}\n{html_head}\nEND\n";
    expect($result)->toBe($expected);
});

// --- constructor-registered is_admin/is_classic_user modifier defaults -----
//
// Smarty always calls a piped `|is_admin`/`|is_classic_user` modifier with
// the piped value as its one argument, so these closures' own `$userStatus
// = ''` default is unreachable through ordinary `{$x|is_admin}` template
// syntax -- but the closures themselves are real, directly reachable PHP
// values via Smarty's own public `registered_plugins` property (already
// established as a legitimate access path by
// TemplateInstanceTest.php:420), so calling the registered closure
// directly with zero arguments exercises that default exactly like any
// other real caller reaching it (AccessLevelChecker::getUserStatus()'s own
// `$userStatus === ''` branch falls back to the real current user).

test('constructor registers an is_admin modifier that reads the current user\'s own status when called with no argument', function (): void {
    CurrentUserTestFactory::get()->set(new User(
        id: UserId::from(7),
        username: Username::from('admin-user'),
        email: null,
        language: LangCode::from('en_GB'),
        theme: 'dummy',
        status: UserStatus::Admin,
        enabledHigh: false,
    ));

    $t = TemplateTestFactory::build();
    // Smarty's own @var array docblock on $registered_plugins (vendor/
    // smarty/smarty/src/Smarty.php) doesn't specify the real nested shape
    // -- registerPlugin() always stores [callback, cacheable] pairs keyed
    // by [type][name].
    /** @var array<string, array<string, array{0: callable, 1: bool}>> $registeredPlugins */
    $registeredPlugins = $t->smarty->registered_plugins;
    $isAdmin = $registeredPlugins['modifier']['is_admin'][0];

    expect($isAdmin())->toBeTrue();
});

test('constructor registers an is_classic_user modifier that reads the current user\'s own status when called with no argument', function (): void {
    CurrentUserTestFactory::get()->set(new User(
        id: UserId::from(8),
        username: Username::from('normal-user'),
        email: null,
        language: LangCode::from('en_GB'),
        theme: 'dummy',
        status: UserStatus::Normal,
        enabledHigh: false,
    ));

    $t = TemplateTestFactory::build();
    /** @var array<string, array<string, array{0: callable, 1: bool}>> $registeredPlugins */
    $registeredPlugins = $t->smarty->registered_plugins;
    $isClassicUser = $registeredPlugins['modifier']['is_classic_user'][0];

    expect($isClassicUser())->toBeTrue();
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
    expect(static fn (): mixed => KernelContainerOverride::with(
        [
            Paths::class => Paths::fromRoot(sys_get_temp_dir()),
            UrlServiceInterface::class => new stdClass(),
        ],
        static function (): void {
            CurrentConfigTestFactory::get()->setDataDirChecked('1');
            TemplateTestFactory::build();
        }
    ))->toThrow(LogicException::class, 'Container returned an unexpected type for ' . UrlServiceInterface::class);

    Kernel::boot(Paths::fromRoot(sys_get_temp_dir()));
    CurrentConfigTestFactory::get()->setDataDirChecked('1');
});

test('lang resolver throws when the container returns an unexpected type', function (): void {
    expect(static fn (): mixed => KernelContainerOverride::with(
        [Lang::class => new stdClass()],
        static fn (): Lang => Template::lang()
    ))->toThrow(LogicException::class, 'Container returned an unexpected type for ' . Lang::class);

    Kernel::boot(Paths::fromRoot(sys_get_temp_dir()));
    CurrentConfigTestFactory::get()->setDataDirChecked('1');
});

test('htmlRenderer resolver throws when the container returns an unexpected type', function (): void {
    $t = TemplateTestFactory::build();

    expect(static fn (): mixed => KernelContainerOverride::with(
        [HtmlRenderingInterface::class => new stdClass()],
        static fn (): ?string => $t->parse('no-such-handle')
    ))->toThrow(LogicException::class, 'Container returned an unexpected type for ' . HtmlRenderingInterface::class);

    Kernel::boot(Paths::fromRoot(sys_get_temp_dir()));
    CurrentConfigTestFactory::get()->setDataDirChecked('1');
});

test('currentTemplate resolver throws when the container returns an unexpected type', function (): void {
    $t = TemplateTestFactory::build();

    // currentTemplate()'s own docblock: its only real caller is
    // finalizeOutput()'s cssLoader->get_css() call, whose own first
    // argument (self::urlService(), evaluated before currentTemplate())
    // transitively resolves HtmlService -- which independently needs
    // CurrentTemplate as a natively-typed constructor param -- so going
    // through finalizeOutput() (confirmed live via a full stack trace)
    // trips PHP's own TypeError on THAT unrelated construction first,
    // before this method's own manual instanceof guard ever runs.
    // Invoking the private currentTemplate() directly is the only way to
    // reach its own guard in isolation.
    $currentTemplateMethod = new ReflectionMethod(Template::class, 'currentTemplate');

    expect(static fn (): mixed => KernelContainerOverride::with(
        [CurrentTemplate::class => new stdClass()],
        static fn (): mixed => $currentTemplateMethod->invoke($t)
    ))->toThrow(LogicException::class, 'Container returned an unexpected type for ' . CurrentTemplate::class);

    Kernel::boot(Paths::fromRoot(sys_get_temp_dir()));
    CurrentConfigTestFactory::get()->setDataDirChecked('1');
});

// --- set_theme: load_parent_css / load_parent_local_head propagation --------

test('set_theme lets a parent theme\'s own load_parent_css/load_parent_local_head themeconf keys override the caller-passed load flags', function (): void {
    $root = sys_get_temp_dir() . '/piwigo-template-test-' . bin2hex(random_bytes(8));
    $parentDir = $root . '/gap-parent';
    $childDir = $root . '/gap-child';
    mkdir($parentDir, 0o777, true);
    mkdir($childDir, 0o777, true);
    file_put_contents($parentDir . '/local_head.tpl', 'x');
    file_put_contents(
        $parentDir . '/themeconf.inc.php',
        "<?php\n\$themeconf = ['local_head' => 'local_head.tpl'];\n"
    );
    file_put_contents(
        $childDir . '/themeconf.inc.php',
        "<?php\n\$themeconf = ['parent' => 'gap-parent', 'load_parent_css' => false, 'load_parent_local_head' => false, 'local_head' => ''];\n"
    );

    $t = TemplateTestFactory::build();
    // Default load_css/load_local_head are both true -- the child
    // themeconf's own load_parent_css=false/load_parent_local_head=false
    // must win over those caller-passed defaults for the recursive
    // parent-theme call.
    $t->set_theme($root, 'gap-child', 'template');

    $themes = template_test_themes($t);
    expect($themes)->toHaveCount(2);
    // The parent's own set_theme() call (and therefore its own
    // smarty->append('themes', ...)) runs during the child's recursive
    // call, before the child appends its own entry -- so the parent's
    // entry comes first.
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

// --- load_themeconf caching ---------------------------------------------------

test('load_themeconf caches the computed themeconf so a second call for the same directory does not re-include a changed file', function (): void {
    $root = sys_get_temp_dir() . '/piwigo-template-test-' . bin2hex(random_bytes(8));
    $themeDir = $root . '/cache-theme';
    mkdir($themeDir, 0o777, true);
    file_put_contents($themeDir . '/themeconf.inc.php', "<?php\n\$themeconf = ['marker' => 'first'];\n");

    $t = TemplateTestFactory::build();

    $first = $t->load_themeconf($themeDir);
    // If load_themeconf() genuinely cached the first computed result under
    // this exact directory's cache key, a changed file on disk must never
    // be observed by a second call for the same directory.
    file_put_contents($themeDir . '/themeconf.inc.php', "<?php\n\$themeconf = ['marker' => 'second'];\n");
    $second = $t->load_themeconf($themeDir);

    expect($first['marker'])->toBe('first');
    expect($second['marker'])->toBe('first');

    template_test_rrmdir($root);
});
