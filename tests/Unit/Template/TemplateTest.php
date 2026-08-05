<?php

declare(strict_types=1);

use Piwigo\Tests\Support\TemplateTestFactory;
use Smarty\Smarty;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\Kernel;
use Piwigo\Core\Lang;
use Piwigo\Core\Paths;
use Piwigo\Lang\Translator;
use Piwigo\Template\Template;

// get_php_str_val() stays a static, instance-free utility (referenced
// during the SEC-15 eval() audit -- it's the only remaining eval()
// surface in this codebase) -- tested directly with no Template instance
// needed. modcompiler_translate()/modcompiler_translate_dec() became real
// instance methods (singleton/service-locator elimination campaign,
// Phase 11 sub-phase 11E, closing their own compile-time CurrentConfig/
// Lang pre-check reads onto $this->currentConfig/$this->lang -- the
// literal compiled-cache-text return values they still produce are
// untouched, a documented permanent exception, see Template's own
// docblock), so these tests now build a real instance via
// TemplateTestFactory::build() -- safe the same "point CurrentPaths/Paths
// at a fresh temp root" way PictureRateRendererTest.php's own docblock
// already established elsewhere in this suite.
//
// modcompiler_translate() goes through $this->lang->t() (Legacy Coupling
// Retirement Track A batch A2; Lang itself became a real, container-shared
// instance in the singleton/service-locator elimination campaign's
// Phase 8), which delegates to Translator::get()'s singleton -- reset
// both, matching LangTest.php's own established pattern, so no test's
// loaded PO state/lang table leaks into another. Lang::current() is a
// live container resolve with no pre-boot fallback (unlike
// Translator::get()), so this file now also boots/resets a real Kernel
// around each test. A real Paths must be supplied to boot() too -- Lang's
// own constructor needs one, and PHP-DI can't autowire Paths on its own
// (every property is a required string with no default); TemplateTestFactory::build()
// resolves the exact same container-shared CurrentConfig/Lang instances
// these tests manipulate directly, so state set before construction is
// visible through $this->currentConfig/$this->lang at call time.
// setDataDirChecked('1') below skips Template::__construct()'s own
// dataDirChecked()===null branch entirely -- that branch's own
// $this->currentConfigService->get() call throws in this Unit test (never
// set() here, unlike a real request/RequestBootstrap::connect()), same
// "point CurrentPaths/Paths at a fresh temp root, then setDataDirChecked()
// before constructing" workaround HtmlServiceTest.php's own docblock
// already established for this identical scenario.

beforeEach(function (): void {
    Kernel::boot(Paths::fromRoot(sys_get_temp_dir()));
    CurrentConfig::current()->setDataDirChecked('1');
});

afterEach(function (): void {
    Lang::current()->reset();
    Translator::get()->reset();
    CurrentConfig::current()->reset();
    Kernel::reset();
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
    CurrentConfig::current()->setCompiledTemplateCacheLanguage(true);
    Lang::current()->loadArray(['Comment' => 'Commentaire']);

    $result = TemplateTestFactory::build()->modcompiler_translate(["'Comment'"]);

    expect($result)->toBe(var_export('Commentaire', true));
});

test('modcompiler_translate falls back to a runtime Lang::current()->t() call when caching is off', function (): void {
    CurrentConfig::current()->setCompiledTemplateCacheLanguage(false);
    Lang::current()->loadArray(['Comment' => 'Commentaire']);

    $result = TemplateTestFactory::build()->modcompiler_translate(["'Comment'"]);

    expect($result)->toBe("\\Piwigo\\Core\\Lang::current()->t('Comment')");
});

test('modcompiler_translate falls back to a runtime Lang::current()->t() call when the key is not in the cached lang table', function (): void {
    CurrentConfig::current()->setCompiledTemplateCacheLanguage(true);
    Lang::current()->loadArray([]);

    $result = TemplateTestFactory::build()->modcompiler_translate(["'Unknown'"]);

    expect($result)->toBe("\\Piwigo\\Core\\Lang::current()->t('Unknown')");
});

test('modcompiler_translate wraps a runtime Lang::current()->t() call in sprintf when extra params are given', function (): void {
    CurrentConfig::current()->setCompiledTemplateCacheLanguage(false);
    Lang::current()->loadArray([]);

    $result = TemplateTestFactory::build()->modcompiler_translate(["'%d comments'", '$count']);

    expect($result)->toBe("\\Piwigo\\Core\\Lang::current()->t('%d comments',\$count)");
});

test('modcompiler_translate_dec falls back to a runtime Lang::current()->plural() call when caching is off', function (): void {
    CurrentConfig::current()->setCompiledTemplateCacheLanguage(false);

    $result = TemplateTestFactory::build()->modcompiler_translate_dec(['$count', "'%d comment'", "'%d comments'"]);

    expect($result)->toBe("\\Piwigo\\Core\\Lang::current()->plural('%d comment','%d comments',\$count)");
});

test('modcompiler_translate wraps a cached lang lookup in sprintf when extra params are given and caching is on', function (): void {
    CurrentConfig::current()->setCompiledTemplateCacheLanguage(true);
    Lang::current()->loadArray(['%d comments' => '%d commentaires']);

    $result = TemplateTestFactory::build()->modcompiler_translate(["'%d comments'", '$count']);

    expect($result)->toBe("sprintf(" . var_export('%d commentaires', true) . ',$count)');
});

test('modcompiler_translate_dec builds a plain >1 ternary from cached lang lookups when caching is on and zero is not plural', function (): void {
    CurrentConfig::current()->setCompiledTemplateCacheLanguage(true);
    Lang::current()->setLangInfo(['zero_plural' => false]);
    Lang::current()->loadArray(['%d comment' => '%d commentaire', '%d comments' => '%d commentaires']);

    $result = TemplateTestFactory::build()->modcompiler_translate_dec(['$count', "'%d comment'", "'%d comments'"]);

    expect($result)->toBe("sprintf((\$tmp=(\$count))>1?'%d commentaires':'%d commentaire',\$tmp)");
});

test('modcompiler_translate_dec also treats zero as plural when zero_plural is set', function (): void {
    CurrentConfig::current()->setCompiledTemplateCacheLanguage(true);
    Lang::current()->setLangInfo(['zero_plural' => true]);
    Lang::current()->loadArray(['%d comment' => '%d commentaire', '%d comments' => '%d commentaires']);

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
