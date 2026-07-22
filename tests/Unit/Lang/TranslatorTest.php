<?php

declare(strict_types=1);

use Piwigo\Config\Config;
use Piwigo\Lang\Translator;

beforeEach(function (): void {
    Translator::reset();
    $this->poFile = sys_get_temp_dir() . '/piwigo-po-test-' . bin2hex(random_bytes(8)) . '.po';
});

afterEach(function (): void {
    Translator::reset();
    Config::reset();
    if (file_exists($this->poFile)) {
        unlink($this->poFile);
    }
});

test('translate returns the original key when no PO file is loaded', function (): void {
    expect(Translator::get()->translate('Hello'))->toBe('Hello');
});

test('load parses a PO file and translate resolves the matching string', function (): void {
    file_put_contents($this->poFile, <<<'PO'
        msgid ""
        msgstr ""
        "Plural-Forms: nplurals=2; plural=(n != 1);\n"

        msgid "Hello"
        msgstr "Bonjour"
        PO);

    Translator::get()->load('fr', $this->poFile);

    expect(Translator::get()->translate('Hello'))->toBe('Bonjour');
});

test('translate falls back to the mirrored string map for keys with no PO entry', function (): void {
    Translator::get()->loadArray(['plugin_key' => 'plugin value']);

    expect(Translator::get()->translate('plugin_key'))->toBe('plugin value');
});

test('translate applies sprintf-style args', function (): void {
    file_put_contents($this->poFile, <<<'PO'
        msgid ""
        msgstr ""
        "Plural-Forms: nplurals=2; plural=(n != 1);\n"

        msgid "Hello %s"
        msgstr "Bonjour %s"
        PO);

    Translator::get()->load('fr', $this->poFile);

    expect(Translator::get()->translate('Hello %s', 'World'))->toBe('Bonjour World');
});

test('plural picks the correct form for a 2-form language', function (): void {
    file_put_contents($this->poFile, <<<'PO'
        msgid ""
        msgstr ""
        "Plural-Forms: nplurals=2; plural=(n != 1);\n"

        msgid "%d photo"
        msgid_plural "%d photos"
        msgstr[0] "%d photo"
        msgstr[1] "%d photos"
        PO);

    Translator::get()->load('en', $this->poFile);

    expect(Translator::get()->plural('%d photo', '%d photos', 1))->toBe('1 photo')
        ->and(Translator::get()->plural('%d photo', '%d photos', 5))->toBe('5 photos');
});

test('plural picks the correct form for a 3-form language (Russian-style rule)', function (): void {
    file_put_contents($this->poFile, <<<'PO'
        msgid ""
        msgstr ""
        "Plural-Forms: nplurals=3; plural=(n%10==1 && n%100!=11 ? 0 : n%10>=2 && n%10<=4 && (n%100<12 || n%100>14) ? 1 : 2);\n"

        msgid "%d photo"
        msgid_plural "%d photos"
        msgstr[0] "%d photo-one"
        msgstr[1] "%d photo-few"
        msgstr[2] "%d photo-many"
        PO);

    Translator::get()->load('ru', $this->poFile);

    expect(Translator::get()->plural('%d photo', '%d photos', 1))->toBe('1 photo-one')
        ->and(Translator::get()->plural('%d photo', '%d photos', 2))->toBe('2 photo-few')
        ->and(Translator::get()->plural('%d photo', '%d photos', 11))->toBe('11 photo-many');
});

test('load mirrors translations into mirroredStrings(), translate()\'s own fallback', function (): void {
    file_put_contents($this->poFile, <<<'PO'
        msgid ""
        msgstr ""
        "Plural-Forms: nplurals=2; plural=(n != 1);\n"

        msgid "Hello"
        msgstr "Bonjour"

        msgid "%d photo"
        msgid_plural "%d photos"
        msgstr[0] "%d photo"
        msgstr[1] "%d photos"
        PO);

    Translator::get()->load('fr', $this->poFile);

    $mirror = Translator::get()->mirroredStrings();
    expect($mirror['Hello'])->toBe('Bonjour')
        ->and($mirror['%d photo'])->toBe('%d photo')
        ->and($mirror['%d photos'])->toBe('%d photos');
});

test('load on an unreadable file is a silent no-op', function (): void {
    Translator::get()->load('fr', '/nonexistent/path.po');

    expect(Translator::get()->translate('Hello'))->toBe('Hello');
});

test('translate warns about a missing key when debug_l10n is enabled', function (): void {
    Config::override('debug_l10n', true);

    $triggered = null;
    set_error_handler(function (int $errno, string $errstr) use (&$triggered): bool {
        $triggered = $errstr;
        return true;
    }, E_USER_WARNING);

    Translator::get()->translate('missing_key');

    restore_error_handler();

    expect($triggered)->toBe('[l10n] language key "missing_key" not defined');
});

test('translate does not warn about a missing key when debug_l10n is disabled', function (): void {
    Config::override('debug_l10n', false);

    $triggered = false;
    set_error_handler(function () use (&$triggered): bool {
        $triggered = true;
        return true;
    }, E_USER_WARNING);

    Translator::get()->translate('missing_key');

    restore_error_handler();

    expect($triggered)->toBeFalse();
});

test('translate does not warn about a resolved key even when debug_l10n is enabled', function (): void {
    Config::override('debug_l10n', true);
    Translator::get()->loadArray(['known_key' => 'known value']);

    $triggered = false;
    set_error_handler(function () use (&$triggered): bool {
        $triggered = true;
        return true;
    }, E_USER_WARNING);

    $result = Translator::get()->translate('known_key');

    restore_error_handler();

    expect($result)->toBe('known value')
        ->and($triggered)->toBeFalse();
});
