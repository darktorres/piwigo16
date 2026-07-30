<?php

declare(strict_types=1);

use Piwigo\Config\CurrentConfig;
use Piwigo\Core\Lang;
use Piwigo\Lang\Translator;
use Piwigo\Template\Template;

// Constructing a real Template instance needs a booted Smarty engine +
// global $conf/$lang_info -- Smarty-rendering integration, already covered
// indirectly by the Browser suite (see docs/PLAN.md's P16 audit
// note). These tests cover the class's static, instance-free logic
// instead: the Smarty template-compiler modifier callbacks
// (modcompiler_translate*(), referenced during the SEC-15 eval() audit --
// get_php_str_val()'s eval() is the only remaining eval() surface in this
// codebase) and the simple variable modifiers.
//
// modcompiler_translate() now goes through Lang::t() (Legacy Coupling
// Retirement Track A batch A2), which delegates to Translator::get()'s
// singleton -- reset both, matching LangTest.php's own established
// pattern, so no test's loaded PO state/lang table leaks into another.

afterEach(function (): void {
    Lang::reset();
    Translator::reset();
    CurrentConfig::reset();
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
    CurrentConfig::setCompiledTemplateCacheLanguage(true);
    Lang::loadArray(['Comment' => 'Commentaire']);

    $result = Template::modcompiler_translate(["'Comment'"]);

    expect($result)->toBe(var_export('Commentaire', true));
});

test('modcompiler_translate falls back to a runtime Lang::t() call when caching is off', function (): void {
    CurrentConfig::setCompiledTemplateCacheLanguage(false);
    Lang::loadArray(['Comment' => 'Commentaire']);

    $result = Template::modcompiler_translate(["'Comment'"]);

    expect($result)->toBe("\\Piwigo\\Core\\Lang::t('Comment')");
});

test('modcompiler_translate falls back to a runtime Lang::t() call when the key is not in the cached lang table', function (): void {
    CurrentConfig::setCompiledTemplateCacheLanguage(true);
    Lang::loadArray([]);

    $result = Template::modcompiler_translate(["'Unknown'"]);

    expect($result)->toBe("\\Piwigo\\Core\\Lang::t('Unknown')");
});

test('modcompiler_translate wraps a runtime Lang::t() call in sprintf when extra params are given', function (): void {
    CurrentConfig::setCompiledTemplateCacheLanguage(false);
    Lang::loadArray([]);

    $result = Template::modcompiler_translate(["'%d comments'", '$count']);

    expect($result)->toBe("\\Piwigo\\Core\\Lang::t('%d comments',\$count)");
});

test('modcompiler_translate_dec falls back to a runtime Lang::plural() call when caching is off', function (): void {
    CurrentConfig::setCompiledTemplateCacheLanguage(false);

    $result = Template::modcompiler_translate_dec(['$count', "'%d comment'", "'%d comments'"]);

    expect($result)->toBe("\\Piwigo\\Core\\Lang::plural('%d comment','%d comments',\$count)");
});

test('modcompiler_translate wraps a cached lang lookup in sprintf when extra params are given and caching is on', function (): void {
    CurrentConfig::setCompiledTemplateCacheLanguage(true);
    Lang::loadArray(['%d comments' => '%d commentaires']);

    $result = Template::modcompiler_translate(["'%d comments'", '$count']);

    expect($result)->toBe("sprintf(" . var_export('%d commentaires', true) . ',$count)');
});

test('modcompiler_translate_dec builds a plain >1 ternary from cached lang lookups when caching is on and zero is not plural', function (): void {
    CurrentConfig::setCompiledTemplateCacheLanguage(true);
    Lang::setLangInfo(['zero_plural' => false]);
    Lang::loadArray(['%d comment' => '%d commentaire', '%d comments' => '%d commentaires']);

    $result = Template::modcompiler_translate_dec(['$count', "'%d comment'", "'%d comments'"]);

    expect($result)->toBe("sprintf((\$tmp=(\$count))>1?'%d commentaires':'%d commentaire',\$tmp)");
});

test('modcompiler_translate_dec also treats zero as plural when zero_plural is set', function (): void {
    CurrentConfig::setCompiledTemplateCacheLanguage(true);
    Lang::setLangInfo(['zero_plural' => true]);
    Lang::loadArray(['%d comment' => '%d commentaire', '%d comments' => '%d commentaires']);

    $result = Template::modcompiler_translate_dec(['$count', "'%d comment'", "'%d comments'"]);

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
    $result = Template::postfilter_language("<?php echo 'Hello World';?>\n", new Smarty\Smarty());

    expect($result)->toBe('Hello World');
});

test('postfilter_language handles a double-quoted literal and leaves non-matching PHP untouched', function (): void {
    $result = Template::postfilter_language("<?php echo \"Bonjour\";?>\n<?php \$x = 1; ?>\n", new Smarty\Smarty());

    expect($result)->toBe("Bonjour<?php \$x = 1; ?>\n");
});

test('prefilter_white_space strips leading whitespace before every recognized tag, and their closing counterparts where applicable', function (): void {
    // Trailing "END" sentinel line: without something after the last real
    // tag, \s*$ (greedy, multiline) would swallow the source string's own
    // final newline too -- a pre-existing quirk of this regex, not
    // something this test is trying to pin down.
    $source = "  {if x}\n  {/if}\n  {foreach x}\n  {/foreach}\n  {section x}\n  {/section}\n  {footer_script}\n  {/footer_script}\n  {include x}\n  {else}\n  {combine_script x}\n  {html_head}\nEND\n";

    $result = Template::prefilter_white_space($source, new Smarty\Smarty());

    $expected = "{if x}\n{/if}\n{foreach x}\n{/foreach}\n{section x}\n{/section}\n{footer_script}\n{/footer_script}\n{include x}\n{else}\n{combine_script x}\n{html_head}\nEND\n";
    expect($result)->toBe($expected);
});
