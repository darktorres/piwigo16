<?php

declare(strict_types=1);

use Piwigo\Config\CurrentConfig;
use Piwigo\Lang\Translator;

beforeEach(function (): void {
    Translator::reset();
    $this->poFile = sys_get_temp_dir() . '/piwigo-po-test-' . bin2hex(random_bytes(8)) . '.po';
});

afterEach(function (): void {
    Translator::reset();
    CurrentConfig::reset();
    if (file_exists((is_string($this->poFile) ? $this->poFile : ''))) {
        unlink((is_string($this->poFile) ? $this->poFile : ''));
    }
});

test('translate returns the original key when no PO file is loaded', function (): void {
    expect(Translator::get()->translate('Hello'))->toBe('Hello');
});

test('load parses a PO file and translate resolves the matching string', function (): void {
    file_put_contents((is_string($this->poFile) ? $this->poFile : ''), <<<'PO'
        msgid ""
        msgstr ""
        "Plural-Forms: nplurals=2; plural=(n != 1);\n"

        msgid "Hello"
        msgstr "Bonjour"
        PO);

    Translator::get()->load('fr', (is_string($this->poFile) ? $this->poFile : ''));

    expect(Translator::get()->translate('Hello'))->toBe('Bonjour');
});

test('translate falls back to the mirrored string map for keys with no PO entry', function (): void {
    Translator::get()->loadArray(['plugin_key' => 'plugin value']);

    expect(Translator::get()->translate('plugin_key'))->toBe('plugin value');
});

test('translate applies sprintf-style args', function (): void {
    file_put_contents((is_string($this->poFile) ? $this->poFile : ''), <<<'PO'
        msgid ""
        msgstr ""
        "Plural-Forms: nplurals=2; plural=(n != 1);\n"

        msgid "Hello %s"
        msgstr "Bonjour %s"
        PO);

    Translator::get()->load('fr', (is_string($this->poFile) ? $this->poFile : ''));

    expect(Translator::get()->translate('Hello %s', 'World'))->toBe('Bonjour World');
});

test('plural picks the correct form for a 2-form language', function (): void {
    file_put_contents((is_string($this->poFile) ? $this->poFile : ''), <<<'PO'
        msgid ""
        msgstr ""
        "Plural-Forms: nplurals=2; plural=(n != 1);\n"

        msgid "%d photo"
        msgid_plural "%d photos"
        msgstr[0] "%d photo"
        msgstr[1] "%d photos"
        PO);

    Translator::get()->load('en', (is_string($this->poFile) ? $this->poFile : ''));

    expect(Translator::get()->plural('%d photo', '%d photos', 1))->toBe('1 photo')
        ->and(Translator::get()->plural('%d photo', '%d photos', 5))->toBe('5 photos');
});

test('plural picks the correct form for a 3-form language (Russian-style rule)', function (): void {
    file_put_contents((is_string($this->poFile) ? $this->poFile : ''), <<<'PO'
        msgid ""
        msgstr ""
        "Plural-Forms: nplurals=3; plural=(n%10==1 && n%100!=11 ? 0 : n%10>=2 && n%10<=4 && (n%100<12 || n%100>14) ? 1 : 2);\n"

        msgid "%d photo"
        msgid_plural "%d photos"
        msgstr[0] "%d photo-one"
        msgstr[1] "%d photo-few"
        msgstr[2] "%d photo-many"
        PO);

    Translator::get()->load('ru', (is_string($this->poFile) ? $this->poFile : ''));

    expect(Translator::get()->plural('%d photo', '%d photos', 1))->toBe('1 photo-one')
        ->and(Translator::get()->plural('%d photo', '%d photos', 2))->toBe('2 photo-few')
        ->and(Translator::get()->plural('%d photo', '%d photos', 11))->toBe('11 photo-many');
});

test('load mirrors translations into mirroredStrings(), translate()\'s own fallback', function (): void {
    file_put_contents((is_string($this->poFile) ? $this->poFile : ''), <<<'PO'
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

    Translator::get()->load('fr', (is_string($this->poFile) ? $this->poFile : ''));

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
    CurrentConfig::setDebugL10n(true);

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
    CurrentConfig::setDebugL10n(false);

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
    CurrentConfig::setDebugL10n(true);
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

test('set() replaces the singleton instance returned by get()', function (): void {
    $replacement = new Translator();
    $replacement->loadArray(['swapped_key' => 'swapped value']);

    Translator::set($replacement);

    expect(Translator::get())->toBe($replacement)
        ->and(Translator::get()->translate('swapped_key'))->toBe('swapped value');
});

test('plural applies sprintf-style args after the count', function (): void {
    file_put_contents((is_string($this->poFile) ? $this->poFile : ''), <<<'PO'
        msgid ""
        msgstr ""
        "Plural-Forms: nplurals=2; plural=(n != 1);\n"

        msgid "%d photo by %s"
        msgid_plural "%d photos by %s"
        msgstr[0] "%d photo by %s"
        msgstr[1] "%d photos by %s"
        PO);

    Translator::get()->load('en', (is_string($this->poFile) ? $this->poFile : ''));

    expect(Translator::get()->plural('%d photo by %s', '%d photos by %s', 1, 'Alice'))->toBe('1 photo by Alice')
        ->and(Translator::get()->plural('%d photo by %s', '%d photos by %s', 3, 'Bob'))->toBe('3 photos by Bob');
});

// A msgctxt-tagged entry with an empty msgid is a real, loadable PO shape
// (confirmed empirically against the installed gettext/gettext PoLoader --
// distinct from the file-level header, which is the *context-less* empty
// msgid) that produces a Translation with getOriginal() === '' but a real
// context and translation string. Both toDictionaryEntry() and mirror()
// skip it via their own `$original === ''` guard -- this single load()
// call exercises both continue branches at once, since they iterate the
// same Translations collection.
//
// The sibling `! ($entry instanceof Translation)` guard in both of those
// same foreach loops is NOT exercised anywhere: Gettext\Translations::
// $translations is only ever populated through add()/addOrMerge(), both
// typed to accept a Translation and nothing else, so every element
// getTranslations() can ever yield is genuinely guaranteed to already be
// a Translation instance -- confirmed by reading vendor/gettext/gettext's
// own Translations class. There is no legitimate PO file or public API
// call that reaches that branch; it's unreachable defensive code, not a
// real gap.
test('load skips a context-tagged entry whose msgid is empty', function (): void {
    file_put_contents((is_string($this->poFile) ? $this->poFile : ''), <<<'PO'
        msgid ""
        msgstr ""
        "Plural-Forms: nplurals=2; plural=(n != 1);\n"

        msgctxt "empty-original-context"
        msgid ""
        msgstr "should never surface"

        msgid "Hello"
        msgstr "Bonjour"
        PO);

    Translator::get()->load('fr', (is_string($this->poFile) ? $this->poFile : ''));

    $mirror = Translator::get()->mirroredStrings();
    expect($mirror)->not->toHaveKey('')
        ->and($mirror['Hello'])->toBe('Bonjour')
        ->and(Translator::get()->translate('Hello'))->toBe('Bonjour');
});
