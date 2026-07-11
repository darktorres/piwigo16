<?php

declare(strict_types=1);

use Piwigo\Core\Lang;
use Piwigo\Core\Paths;
use Piwigo\Lang\LangService;
use Piwigo\Lang\Translator;

beforeEach(function (): void {
    Lang::reset();
    Translator::reset();
    unset($GLOBALS['lang']);
    $this->paths = Paths::fromRoot(dirname(__DIR__, 3));
    $this->service = new LangService($this->paths);
});

afterEach(function (): void {
    Lang::reset();
    Translator::reset();
    unset($GLOBALS['lang']);
});

test('t delegates to Lang::t', function (): void {
    Lang::loadArray(['greeting' => 'hi']);
    $GLOBALS['lang'] = ['greeting' => 'hi'];

    expect($this->service->t('greeting'))->toBe('hi');
});

test('t treats a null key as an empty string, matching l10n()s legacy contract', function (): void {
    expect($this->service->t(null))->toBe('');
});

test('l10n is an alias for t', function (): void {
    Lang::loadArray(['greeting' => 'hi']);
    $GLOBALS['lang'] = ['greeting' => 'hi'];

    expect($this->service->l10n('greeting'))->toBe($this->service->t('greeting'));
});

test('loadLanguageForPlugin rejects a locale not installed under language/', function (): void {
    expect($this->service->loadLanguageForPlugin(sys_get_temp_dir(), '../../../../etc'))->toBeFalse();
});

test('loadLanguageForPlugin rejects a locale with path traversal characters', function (): void {
    expect($this->service->loadLanguageForPlugin(sys_get_temp_dir(), '..'))->toBeFalse();
});

test('loadLanguageForPlugin returns false when the plugin has no PO file for a real installed locale', function (): void {
    expect($this->service->loadLanguageForPlugin(sys_get_temp_dir() . '/nonexistent-plugin-dir', 'en_UK'))->toBeFalse();
});

test('loadLanguageForPlugin loads a real PO file for a real installed locale', function (): void {
    $pluginDir = sys_get_temp_dir() . '/piwigo-lang-service-test-plugin';
    @mkdir($pluginDir . '/language/en_UK', 0o777, true);
    file_put_contents($pluginDir . '/language/en_UK/plugin.po', <<<'PO'
        msgid ""
        msgstr ""
        "Plural-Forms: nplurals=2; plural=(n != 1);\n"

        msgid "plugin_greeting"
        msgstr "plugin hi"
        PO);

    try {
        expect($this->service->loadLanguageForPlugin($pluginDir, 'en_UK'))->toBeTrue()
            ->and(Translator::get()->translate('plugin_greeting'))->toBe('plugin hi');
    } finally {
        unlink($pluginDir . '/language/en_UK/plugin.po');
        rmdir($pluginDir . '/language/en_UK');
        rmdir($pluginDir . '/language');
        rmdir($pluginDir);
    }
});
