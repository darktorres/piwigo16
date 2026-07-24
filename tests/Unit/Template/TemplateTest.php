<?php

declare(strict_types=1);

use Piwigo\Config\CurrentConfig;
use Piwigo\Core\Lang;
use Piwigo\Lang\Translator;
use Piwigo\Template\Template;

// Constructing a real Template instance needs a booted Smarty engine +
// global $conf/$lang_info -- Smarty-rendering integration, already covered
// indirectly by the Browser suite (see docs/PLAN-REPLAY.md's P16 audit
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
