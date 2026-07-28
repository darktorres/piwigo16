<?php

declare(strict_types=1);

use Piwigo\Core\CharsetHelper;

/**
 * Piwigo\Core\CharsetHelper -- had zero dedicated coverage (see
 * /home/torres/.claude/plans/piped-enchanting-spark.md, Wave 1).
 *
 * getPwgCharset()'s `defined('PWG_CHARSET')` branch is deliberately NOT
 * exercised here: PWG_CHARSET is only ever define()'d as a string embedded
 * in InstallWizard's generated config-file content (never executed live in
 * this test process), so it stays genuinely undefined throughout a normal
 * test run -- and PHP constants can't be undefined once set, so defining
 * it here would permanently leak into every other test file sharing this
 * same process (Pest runs the whole suite in one process). Not worth that
 * risk for one branch.
 *
 * convertCharset()'s ext-mbstring fallback branch (`! function_exists
 * ('iconv')`) is similarly unreachable: both ext-iconv and ext-mbstring
 * are hard composer.json requirements, and ext-iconv is checked first, so
 * the mbstring fallback can never actually run here.
 */
test('getPwgCharset defaults to utf-8 when PWG_CHARSET is not defined', function (): void {
    expect(defined('PWG_CHARSET'))->toBeFalse();
    expect(CharsetHelper::getPwgCharset())->toBe('utf-8');
});

test('convertCharset is a passthrough when source and destination charsets match', function (): void {
    expect(CharsetHelper::convertCharset('hello', 'utf-8', 'utf-8'))->toBe('hello');
});

test('convertCharset converts iso-8859-1 to utf-8 via the dedicated mb_convert_encoding branch', function (): void {
    expect(CharsetHelper::convertCharset("caf\xe9", 'iso-8859-1', 'utf-8'))->toBe("caf\xc3\xa9");
});

test('convertCharset converts utf-8 to iso-8859-1 via the dedicated mb_convert_encoding branch', function (): void {
    expect(CharsetHelper::convertCharset("caf\xc3\xa9", 'utf-8', 'iso-8859-1'))->toBe("caf\xe9");
});

test('convertCharset falls through to the generic iconv branch for any other charset pair', function (): void {
    $result = CharsetHelper::convertCharset('hello', 'ascii', 'utf-16');

    expect($result)->toBeString();
    expect($result)->not->toBe('hello');
});
