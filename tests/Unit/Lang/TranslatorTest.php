<?php

declare(strict_types=1);

use Piwigo\Lang\Translator;

beforeEach(function (): void {
    Translator::reset();
    unset($GLOBALS['lang']);
    $this->poFile = sys_get_temp_dir() . '/piwigo-po-test-' . bin2hex(random_bytes(8)) . '.po';
});

afterEach(function (): void {
    Translator::reset();
    unset($GLOBALS['lang']);
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

test('translate falls back to the $lang global for keys with no PO entry', function (): void {
    $GLOBALS['lang'] = ['plugin_key' => 'plugin value'];

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

test('load mirrors translations into $GLOBALS[lang] for plugin/theme code', function (): void {
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

    expect($GLOBALS['lang']['Hello'])->toBe('Bonjour')
        ->and($GLOBALS['lang']['%d photo'])->toBe('%d photo')
        ->and($GLOBALS['lang']['%d photos'])->toBe('%d photos');
});

test('load on an unreadable file is a silent no-op', function (): void {
    Translator::get()->load('fr', '/nonexistent/path.po');

    expect(Translator::get()->translate('Hello'))->toBe('Hello');
});
