<?php

declare(strict_types=1);

use Piwigo\PluginConfig\PluginManifest;

test('fromArray populates every field from a fully-populated array', function (): void {
    $manifest = PluginManifest::fromArray([
        'id' => 'TakeATour',
        'name' => 'Take A Tour of Your Piwigo',
        'version' => '17.0.0',
        'description' => 'Visit your Piwigo to discover its features.',
        'license' => 'GPL-3.0-or-later',
        'minPiwigo' => '16.3.0',
        'main' => 'Piwigo\\Plugins\\TakeATour\\Plugin',
        'homepage' => 'https://piwigo.org',
        'author' => 'Piwigo Team',
        'authorUri' => 'https://piwigo.org',
        'hasSettings' => true,
        'require' => [
            'piwigo' => '>=16.3.0',
        ],
        'autoload' => [
            'psr-4' => [
                'Piwigo\\Plugins\\TakeATour\\' => 'src/',
            ],
        ],
    ]);

    expect($manifest->id)
        ->toBe('TakeATour')
        ->and($manifest->name)
        ->toBe('Take A Tour of Your Piwigo')
        ->and($manifest->version)
        ->toBe('17.0.0')
        ->and($manifest->description)
        ->toBe('Visit your Piwigo to discover its features.')
        ->and($manifest->license)
        ->toBe('GPL-3.0-or-later')
        ->and($manifest->minPiwigo)
        ->toBe('16.3.0')
        ->and($manifest->main)
        ->toBe('Piwigo\\Plugins\\TakeATour\\Plugin')
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
            'Piwigo\\Plugins\\TakeATour\\' => 'src/',
        ]);
});

test('fromArray defaults every optional field when only the 7 required fields are present', function (): void {
    $manifest = PluginManifest::fromArray([
        'id' => 'modus',
        'name' => 'Modus',
        'version' => '1.0.0',
        'description' => 'A theme',
        'license' => 'MIT',
        'minPiwigo' => '16.3.0',
        'main' => 'Piwigo\\Plugins\\Modus\\Plugin',
    ]);

    expect($manifest->homepage)
        ->toBeNull()
        ->and($manifest->author)
        ->toBeNull()
        ->and($manifest->authorUri)
        ->toBeNull()
        ->and($manifest->hasSettings)
        ->toBeFalse()
        ->and($manifest->require)
        ->toBe([])
        ->and($manifest->autoloadPsr4)
        ->toBe([]);
});

test('fromArray accepts the webmaster hasSettings value verbatim', function (): void {
    $manifest = PluginManifest::fromArray([
        'id' => 'x',
        'name' => 'x',
        'version' => '1.0.0',
        'description' => 'x',
        'license' => 'MIT',
        'minPiwigo' => '16.3.0',
        'main' => 'X',
        'hasSettings' => 'webmaster',
    ]);

    expect($manifest->hasSettings)
        ->toBe('webmaster');
});

test('fromArray narrows an invalid hasSettings value down to false', function (): void {
    $manifest = PluginManifest::fromArray([
        'id' => 'x',
        'name' => 'x',
        'version' => '1.0.0',
        'description' => 'x',
        'license' => 'MIT',
        'minPiwigo' => '16.3.0',
        'main' => 'X',
        'hasSettings' => 'not-a-real-value',
    ]);

    expect($manifest->hasSettings)
        ->toBeFalse();
});

test('fromArray drops non-string entries from require and autoload.psr-4', function (): void {
    $manifest = PluginManifest::fromArray([
        'id' => 'x',
        'name' => 'x',
        'version' => '1.0.0',
        'description' => 'x',
        'license' => 'MIT',
        'minPiwigo' => '16.3.0',
        'main' => 'X',
        'require' => [
            'piwigo' => '>=16.3.0',
            'plugin/bad' => 42,
        ],
        'autoload' => [
            'psr-4' => [
                'Good\\' => 'src/',
                'Bad\\' => 42,
            ],
        ],
    ]);

    expect($manifest->require)
        ->toBe([
            'piwigo' => '>=16.3.0',
        ])
        ->and($manifest->autoloadPsr4)
        ->toBe([
            'Good\\' => 'src/',
        ]);
});

test('fromArray throws when a required field is missing', function (): void {
    PluginManifest::fromArray([
        'id' => 'x',
        'name' => 'x',
        'version' => '1.0.0',
        'description' => 'x',
        'license' => 'MIT',
        'minPiwigo' => '16.3.0',
        // 'main' missing
    ]);
})->throws(InvalidArgumentException::class, "plugin.json field 'main' must be a string");

test('fromArray throws when a required field is the wrong type', function (): void {
    PluginManifest::fromArray([
        'id' => 'x',
        'name' => 'x',
        'version' => '1.0.0',
        'description' => 'x',
        'license' => 'MIT',
        'minPiwigo' => '16.3.0',
        'main' => ['not', 'a', 'string'],
    ]);
})->throws(InvalidArgumentException::class, "plugin.json field 'main' must be a string");
