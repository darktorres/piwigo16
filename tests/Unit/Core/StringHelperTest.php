<?php

declare(strict_types=1);

use Piwigo\Core\StringHelper;

/**
 * pwgTransliterate()'s `function_exists('mb_strtolower') && defined
 * ('PWG_CHARSET')` branch is deliberately NOT exercised here: same
 * reasoning as tests/Unit/Core/CharsetHelperTest.php's own documented
 * skip for PWG_CHARSET -- it stays undefined for this whole shared
 * PHPUnit/Pest process (that file's own test asserts so directly), and
 * constants can't be undefined once define()'d, so forcing it true here
 * would permanently leak into every other test file, including that
 * one's own explicit `expect(defined('PWG_CHARSET'))->toBeFalse()`
 * assertion. Not worth that risk for one branch.
 */
test('getExtension returns the part after the last dot', function (): void {
    expect(StringHelper::getExtension('archive.tar.gz'))->toBe('gz');
    expect(StringHelper::getExtension('photo.JPG'))->toBe('JPG');
});

test('getExtension returns an empty string for null or a dot-less filename', function (): void {
    expect(StringHelper::getExtension(null))->toBe('');
    expect(StringHelper::getExtension('no_extension'))->toBe('');
});

test('getFilenameWoExtension returns everything before the last dot', function (): void {
    expect(StringHelper::getFilenameWoExtension('archive.tar.gz'))->toBe('archive.tar');
});

test('getFilenameWoExtension returns the filename unchanged when there is no dot', function (): void {
    expect(StringHelper::getFilenameWoExtension('no_extension'))->toBe('no_extension');
});

test('qualifyUtf8 returns 0 for a plain ASCII string', function (): void {
    expect(StringHelper::qualifyUtf8('Hello World 123'))->toBe(0);
});

test('qualifyUtf8 returns 1 for well-formed multi-byte UTF-8', function (): void {
    expect(StringHelper::qualifyUtf8("Caf\xc3\xa9"))->toBe(1);
});

test('qualifyUtf8 returns -1 for a malformed continuation byte', function (): void {
    // 0xC3 signals a 2-byte sequence, but is followed by a plain ASCII
    // byte instead of a 10bbbbbb continuation byte.
    expect(StringHelper::qualifyUtf8("\xc3A"))->toBe(-1);
});

test('qualifyUtf8 returns -1 when a multi-byte sequence is cut off at the end of the string', function (): void {
    expect(StringHelper::qualifyUtf8("Caf\xc3"))->toBe(-1);
});

test('qualifyUtf8 returns 1 for a well-formed 4-byte sequence (a real UTF-8 emoji)', function (): void {
    expect(StringHelper::qualifyUtf8("\xf0\x9f\x98\x80"))->toBe(1); // U+1F600
});

test('qualifyUtf8 returns 1 for well-formed 5-byte and 6-byte lead sequences', function (): void {
    // 0xF8-0xFB (5-byte) and 0xFC-0xFD (6-byte) lead bytes are outside
    // the RFC 3629 UTF-8 range (capped at 4 bytes) but are part of the
    // original, broader UTF-8 scheme this method still recognizes --
    // synthetic since no real character encodes to either width.
    expect(StringHelper::qualifyUtf8("\xf8\x88\x88\x88\x88"))->toBe(1)
        ->and(StringHelper::qualifyUtf8("\xfc\x88\x88\x88\x88\x88"))->toBe(1);
});

test('qualifyUtf8 returns -1 for a 5-byte sequence cut off before its continuation bytes complete', function (): void {
    expect(StringHelper::qualifyUtf8("\xf8\x88\x88"))->toBe(-1);
});

test('qualifyUtf8 returns -1 for a lead byte matching none of the recognized bit patterns', function (): void {
    // 0xFE is 11111110 -- fails every (ord & mask) === pattern check.
    expect(StringHelper::qualifyUtf8("\xfe"))->toBe(-1);
});

test('removeAccents leaves a plain ASCII string unchanged', function (): void {
    expect(StringHelper::removeAccents('Hello World'))->toBe('Hello World');
});

test('removeAccents strips accents from UTF-8 input', function (): void {
    expect(StringHelper::removeAccents("\xc3\x89t\xc3\xa9"))->toBe('Ete'); // "Été"
});

test('removeAccents handles the Euro and Pound signs specially', function (): void {
    expect(StringHelper::removeAccents("100\xe2\x82\xac"))->toBe('100E');
    expect(StringHelper::removeAccents("\xc2\xa310"))->toBe('10');
});

test('removeAccents transliterates ISO-8859-1 input via the non-UTF-8 branch', function (): void {
    // chr(233) is é in ISO-8859-1 -- a single byte >= 0x80 with no valid
    // UTF-8 continuation sequence, so qualifyUtf8() returns -1 for it.
    $iso8859_1_e_acute = chr(233);

    expect(StringHelper::removeAccents($iso8859_1_e_acute))->toBe('e');
});

test('removeAccents expands ISO-8859-1 digraphs (OE/AE/etc) to their 2-letter ASCII form', function (): void {
    expect(StringHelper::removeAccents(chr(198)))->toBe('AE'); // Æ
    expect(StringHelper::removeAccents(chr(223)))->toBe('ss'); // ß
});

test('pwgTransliterate lowercases plain ASCII input', function (): void {
    expect(StringHelper::pwgTransliterate('HELLO'))->toBe('hello');
});

test('pwgTransliterate strips accents from already-lowercase UTF-8 input', function (): void {
    // Deliberately already-lowercase input: whether this environment's
    // mb_strtolower()/PWG_CHARSET branch or the plain strtolower()
    // fallback fires, a lowercase accented character is a no-op for
    // either lowercasing path, so this assertion holds regardless of
    // which branch this environment takes.
    expect(StringHelper::pwgTransliterate("caf\xc3\xa9"))->toBe('cafe'); // "café"
});

test('str2url replaces spaces and punctuation with underscores, in lowercase', function (): void {
    expect(StringHelper::str2url('My Photo: Summer 2026'))->toBe('my_photo_summer_2026');
});

test('str2url falls back to the pre-strip transliteration when stripping empties the result', function (): void {
    // Every character is punctuation the URL-safe regex strips outright,
    // leaving an empty $res -- falls back to $safe (the merely-
    // transliterated, not-yet-stripped string) instead of returning ''.
    expect(StringHelper::str2url('!!!'))->toBe('!!!');
});

test('getNameFromFile strips the extension and replaces underscores with spaces', function (): void {
    expect(StringHelper::getNameFromFile('my_holiday_photo.jpg'))->toBe('my holiday photo');
});
