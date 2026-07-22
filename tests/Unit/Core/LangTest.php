<?php

declare(strict_types=1);

use Piwigo\Core\Lang;
use Piwigo\Lang\Translator;

beforeEach(function (): void {
    Lang::reset();
    Translator::reset();
});

afterEach(function (): void {
    Lang::reset();
    Translator::reset();
});

test('t returns the key itself when nothing is loaded', function (): void {
    expect(Lang::t('Some_Untranslated_Key'))->toBe('Some_Untranslated_Key');
});

test('t formats sprintf-style args', function (): void {
    Translator::get()->loadArray(['Hello %s' => 'Bonjour %s']);

    expect(Lang::t('Hello %s', 'World'))->toBe('Bonjour World');
});

test('has reflects the loaded data set', function (): void {
    Lang::loadArray(['known' => 'value']);

    expect(Lang::has('known'))->toBeTrue()
        ->and(Lang::has('unknown'))->toBeFalse();
});

test('attachGlobals seeds from Translator\'s already-mirrored strings', function (): void {
    Translator::get()->loadArray(['greeting' => 'hi']);

    Lang::attachGlobals();

    expect(Lang::has('greeting'))->toBeTrue();
});

test('attachGlobals takes a one-time snapshot -- a later Translator mirror change is not retroactively visible', function (): void {
    Translator::get()->loadArray(['greeting' => 'hi']);
    Lang::attachGlobals();

    Translator::get()->loadArray(['greeting' => 'hi', 'legacy_key' => 'legacy value']);

    expect(Lang::has('legacy_key'))->toBeFalse();
});

test('day returns the day name at the given index', function (): void {
    Lang::loadArray(['day' => [0 => 'Sunday', 1 => 'Monday']]);

    expect(Lang::day(1))->toBe('Monday')
        ->and(Lang::day(9))->toBe('');
});

test('month returns the month name at the given index', function (): void {
    Lang::loadArray(['month' => [1 => 'January']]);

    expect(Lang::month(1))->toBe('January')
        ->and(Lang::month(13))->toBe('');
});
