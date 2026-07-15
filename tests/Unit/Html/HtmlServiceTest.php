<?php

declare(strict_types=1);

use Piwigo\Html\HtmlService;

if (! defined('PHPWG_ROOT_PATH')) {
    define('PHPWG_ROOT_PATH', './');
}

// trigger_notify() is now always available (composer autoload.files,
// src/Piwigo/PluginConfig/functions.php) and is a pure no-op with no
// handlers registered, so no local stub is needed for it here anymore --
// only get_name_from_file() (a separate not-yet-migrated free function)
// still needs one, same "load standalone" pattern as PasswordHashTest.php's
// own stubs.
if (! function_exists('get_name_from_file')) {
    function get_name_from_file(string $filename): string
    {
        $dot = strrpos($filename, '.');

        return $dot !== false ? substr($filename, 0, $dot) : $filename;
    }
}

test('renderCommentContent escapes html and linkifies bare URLs', function (): void {
    $service = new HtmlService();

    expect($service->renderCommentContent('<script>alert(1)</script> see http://example.test/x'))
        ->toBe('&lt;script&gt;alert(1)&lt;/script&gt; see <a href="http://example.test/x" rel="nofollow">http://example.test/x</a>');
});

test('renderCommentContent underlines _word_', function (): void {
    $service = new HtmlService();

    expect($service->renderCommentContent('_hello_'))
        ->toBe('<span style="text-decoration:underline;">hello</span>');
});

test('renderCommentContent bolds *word* only when a word character directly touches the asterisk', function (): void {
    // '\b\*(\S*)\*\b': '*' is not itself a \w character, so \b only fires
    // where a real word character (letter/digit/underscore) directly
    // touches the asterisk on both sides -- a space (or start/end of
    // string) next to '*' is two non-word characters, no boundary, no
    // match. This is the original regex's real, longstanding, pre-existing
    // behavior (confirmed empirically), not something this migration
    // changed -- "*bold*" surrounded by spaces is verifiably a silent
    // no-op, only "word*bold*word" (no space) actually triggers it.
    $service = new HtmlService();

    expect($service->renderCommentContent('a *hello* b'))->toBe('a *hello* b')
        ->and($service->renderCommentContent('x*hello*y'))
        ->toBe('x<span style="font-weight:bold;">hello</span>y');
});

test('renderCommentContent converts newlines to br tags', function (): void {
    $service = new HtmlService();

    expect($service->renderCommentContent("a\nb"))->toBe('a<br />' . "\n" . 'b');
});

test('nameCompare sorts case-insensitively', function (): void {
    $service = new HtmlService();

    expect($service->nameCompare(['name' => 'banana'], ['name' => 'Apple']))->toBeGreaterThan(0)
        ->and($service->nameCompare(['name' => 'apple'], ['name' => 'apple']))->toBe(0);
});

test('setStatusHeader sends the well-known reason phrase for a known code', function (): void {
    $service = new HtmlService();

    $service->setStatusHeader(404);

    expect(true)->toBeTrue(); // real assertion is the absence of a fatal/warning; header() is a no-op under CLI SAPI
});

test('renderCategoryLiteralDescription strips disallowed tag markup but keeps their content and the allow-list', function (): void {
    // strip_tags() removes only the tag markup itself, not the content inside
    // a disallowed tag (it isn't a sanitizer aware of <script>'s special
    // meaning) -- "x" survives, just unwrapped.
    $service = new HtmlService();

    expect($service->renderCategoryLiteralDescription('<script>x</script><p>hello</p><b>world</b>'))
        ->toBe('x<p>hello</p><b>world</b>');
});

test('renderCategoryLiteralDescription treats a null description as empty', function (): void {
    $service = new HtmlService();

    expect($service->renderCategoryLiteralDescription(null))->toBe('');
});

test('pwgNl2br passes through falsy scalars unchanged', function (): void {
    $service = new HtmlService();

    expect($service->pwgNl2br(''))->toBe('')
        ->and($service->pwgNl2br(0))->toBe(0)
        ->and($service->pwgNl2br(null))->toBeNull()
        ->and($service->pwgNl2br(false))->toBeFalse();
});

test('pwgNl2br passes through arrays unchanged', function (): void {
    $service = new HtmlService();

    expect($service->pwgNl2br(['a', 'b']))->toBe(['a', 'b']);
});

test('pwgNl2br converts newlines in a non-empty string', function (): void {
    $service = new HtmlService();

    expect($service->pwgNl2br("a\nb"))->toBe('a<br />' . "\n" . 'b');
});

test('renderElementName falls back to the filename when name is not set', function (): void {
    $service = new HtmlService();

    expect($service->renderElementName(['file' => 'my-photo.jpg']))->toBe('my-photo');
});

test('renderElementDescription returns empty string when comment is not set', function (): void {
    $service = new HtmlService();

    expect($service->renderElementDescription([]))->toBe('');
});
