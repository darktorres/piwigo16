<?php

declare(strict_types=1);

use Piwigo\Core\Lang;
use Piwigo\Lang\Translator;

beforeEach(function (): void {
    Lang::reset();
    Translator::reset();
    unset($GLOBALS['lang']);
});

afterEach(function (): void {
    Lang::reset();
    Translator::reset();
    unset($GLOBALS['lang']);
});

test('t returns the key itself when nothing is loaded', function (): void {
    expect(Lang::t('Some_Untranslated_Key'))->toBe('Some_Untranslated_Key');
});

test('t formats sprintf-style args', function (): void {
    Lang::loadArray(['Hello %s' => 'Bonjour %s']);
    $GLOBALS['lang'] = ['Hello %s' => 'Bonjour %s'];

    expect(Lang::t('Hello %s', 'World'))->toBe('Bonjour World');
});

test('has reflects the loaded data set', function (): void {
    Lang::loadArray(['known' => 'value']);

    expect(Lang::has('known'))->toBeTrue()
        ->and(Lang::has('unknown'))->toBeFalse();
});

test('attachGlobals seeds from an already-populated $GLOBALS[lang]', function (): void {
    $GLOBALS['lang'] = ['greeting' => 'hi'];

    Lang::attachGlobals();

    expect(Lang::has('greeting'))->toBeTrue();
});

test('attachGlobals bridges $GLOBALS[lang] so legacy array writes are visible on has()', function (): void {
    Lang::attachGlobals();

    $GLOBALS['lang']['legacy_key'] = 'legacy value';

    expect(Lang::has('legacy_key'))->toBeTrue();
});

test('day returns the day name at the given index', function (): void {
    $GLOBALS['lang'] = ['day' => [0 => 'Sunday', 1 => 'Monday']];

    expect(Lang::day(1))->toBe('Monday')
        ->and(Lang::day(9))->toBe('');
});

test('month returns the month name at the given index', function (): void {
    $GLOBALS['lang'] = ['month' => [1 => 'January']];

    expect(Lang::month(1))->toBe('January')
        ->and(Lang::month(13))->toBe('');
});
