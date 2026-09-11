<?php

declare(strict_types=1);

use Piwigo\Core\InstallationFlag;
use Piwigo\Core\Lang;
use Piwigo\Core\Paths;
use Piwigo\Lang\LangService;
use Piwigo\Tests\Support\HtmlServiceTestFactory;
use Piwigo\Tests\Support\TranslatorTestFactory;

/**
 * Lang is a real, container-shared instance -- this file never boots
 * Kernel, so a throwaway instance is constructed directly instead of
 * resolving the real one. TranslatorTestFactory::get() is shared with this
 * Lang instance so loadArray()'s Translator-mirror side effect (see
 * Lang::loadArray()'s own docblock) lands in the same Translator instance
 * the file's own assertions read from.
 *
 * `loadLanguageForPlugin()`/`isInstalledLocale()` and their own dedicated
 * tests were removed (P29.6) along with the methods themselves -- see
 * LangService's own docblock for where that responsibility moved.
 */
function langServiceTestNewLang(Paths $paths): Lang
{
    return new Lang(TranslatorTestFactory::get(), HtmlServiceTestFactory::build(), $paths, new InstallationFlag());
}

beforeEach(function (): void {
    TranslatorTestFactory::get()->reset();
    $this->paths = Paths::fromRoot(dirname(__DIR__, 3));
    $this->lang = langServiceTestNewLang($this->paths);
    $this->service = new LangService($this->lang);
});

afterEach(function (): void {
    TranslatorTestFactory::get()->reset();
});

test('t delegates to Lang::t', function (): void {
    $this->lang->loadArray([
        'greeting' => 'hi',
    ]);

    expect($this->service->t('greeting'))
        ->toBe('hi');
});

test('t treats a null key as an empty string, matching l10n()s legacy contract', function (): void {
    expect($this->service->t(null))
        ->toBe('');
});

test('l10n is an alias for t', function (): void {
    $this->lang->loadArray([
        'greeting' => 'hi',
    ]);

    expect($this->service->l10n('greeting'))
        ->toBe($this->service->t('greeting'));
});
