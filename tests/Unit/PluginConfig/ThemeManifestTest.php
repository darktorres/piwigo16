<?php

declare(strict_types=1);

use Piwigo\PluginConfig\ThemeManifest;

test('fromArray populates every field from a fully-populated array', function (): void {
    $manifest = ThemeManifest::fromArray([
        'id' => 'modus',
        'name' => 'Modus',
        'version' => '16.3.0.1',
        'description' => 'A modern Piwigo theme.',
        'license' => 'GPL-3.0-or-later',
        'minPiwigo' => '16.3.0',
        'main' => 'Piwigo\\Themes\\Modus\\Theme',
        'homepage' => 'https://piwigo.org',
        'author' => 'Piwigo Team',
        'authorUri' => 'https://piwigo.org',
        'hasSettings' => true,
        'require' => [
            'piwigo' => '>=16.3.0',
        ],
        'autoload' => [
            'psr-4' => [
                'Piwigo\\Themes\\Modus\\' => 'src/',
            ],
        ],
        'parent' => 'elegant',
        'loadParentCss' => true,
        'assets' => [
            'icons' => 'icon/',
            'css' => 'css/',
        ],
        'localHead' => 'template/local-head.tpl',
        'colorscheme' => 'dark',
        'useStandardPages' => true,
    ]);

    expect($manifest->id)
        ->toBe('modus')
        ->and($manifest->name)
        ->toBe('Modus')
        ->and($manifest->version)
        ->toBe('16.3.0.1')
        ->and($manifest->description)
        ->toBe('A modern Piwigo theme.')
        ->and($manifest->license)
        ->toBe('GPL-3.0-or-later')
        ->and($manifest->minPiwigo)
        ->toBe('16.3.0')
        ->and($manifest->main)
        ->toBe('Piwigo\\Themes\\Modus\\Theme')
        ->and($manifest->homepage)
        ->toBe('https://piwigo.org')
        ->and($manifest->author)
        ->toBe('Piwigo Team')
        ->and($manifest->authorUri)
        ->toBe('https://piwigo.org')
        ->and($manifest->hasSettings)
        ->toBeTrue()
        ->and($manifest->require)
        ->toBe([
            'piwigo' => '>=16.3.0',
        ])
        ->and($manifest->autoloadPsr4)
        ->toBe([
            'Piwigo\\Themes\\Modus\\' => 'src/',
        ])
        ->and($manifest->parent)
        ->toBe('elegant')
        ->and($manifest->loadParentCss)
        ->toBeTrue()
        ->and($manifest->assets)
        ->toBe([
            'icons' => 'icon/',
            'css' => 'css/',
        ])
        ->and($manifest->localHead)
        ->toBe('template/local-head.tpl')
        ->and($manifest->colorscheme)
        ->toBe('dark')
        ->and($manifest->useStandardPages)
        ->toBeTrue();
});

test('fromArray defaults every optional field when only the 7 required fields are present', function (): void {
    $manifest = ThemeManifest::fromArray([
        'id' => 'elegant',
        'name' => 'Elegant',
        'version' => '16.3.0',
        'description' => 'A theme',
        'license' => 'GPL-3.0-or-later',
        'minPiwigo' => '16.3.0',
        'main' => 'Piwigo\\Themes\\Elegant\\Theme',
    ]);

    expect($manifest->parent)
        ->toBeNull()
        ->and($manifest->loadParentCss)
        ->toBeFalse()
        ->and($manifest->assets)
        ->toBe([])
        ->and($manifest->localHead)
        ->toBeNull()
        ->and($manifest->colorscheme)
        ->toBeNull()
        ->and($manifest->useStandardPages)
        ->toBeFalse();
});

test('fromArray drops non-string entries from assets', function (): void {
    $manifest = ThemeManifest::fromArray([
        'id' => 'x',
        'name' => 'x',
        'version' => '1.0.0',
        'description' => 'x',
        'license' => 'MIT',
        'minPiwigo' => '16.3.0',
        'main' => 'X',
        'assets' => [
            'css' => 'css/',
            'bad' => 42,
        ],
    ]);

    expect($manifest->assets)
        ->toBe([
            'css' => 'css/',
        ]);
});

test('fromArray throws when a required field is missing', function (): void {
    ThemeManifest::fromArray([
        'id' => 'x',
        'name' => 'x',
        'version' => '1.0.0',
        'description' => 'x',
        'license' => 'MIT',
        'minPiwigo' => '16.3.0',
        // 'main' missing
    ]);
})->throws(InvalidArgumentException::class, "theme.json field 'main' must be a string");
