<?php

declare(strict_types=1);

use Piwigo\Admin\Request\ThemesNewInstallRequest;

test('fromArray returns null for all fields when absent', function (): void {
    $request = ThemesNewInstallRequest::fromArray([]);

    expect($request->revision)
        ->toBeNull()
        ->and($request->extension)
        ->toBeNull()
        ->and($request->installStatus)
        ->toBeNull()
        ->and($request->installedThemeId)
        ->toBeNull();
});

test('fromArray parses revision/extension when both present', function (): void {
    $request = ThemesNewInstallRequest::fromArray([
        'revision' => '123',
        'extension' => 'my-theme',
    ]);

    expect($request->revision)
        ->toBe('123')
        ->and($request->extension)
        ->toBe('my-theme');
});

test('fromArray parses a real installstatus string', function (): void {
    $request = ThemesNewInstallRequest::fromArray([
        'installstatus' => 'ok',
    ]);

    expect($request->installStatus)
        ->toBe('ok');
});

test('fromArray coerces a present but non-string installstatus to an empty string, staying non-null', function (): void {
    $request = ThemesNewInstallRequest::fromArray([
        'installstatus' => ['x'],
    ]);

    expect($request->installStatus)
        ->toBe('');
});

test('fromArray parses installedThemeId when present', function (): void {
    $request = ThemesNewInstallRequest::fromArray([
        'theme_id' => 'my-theme',
    ]);

    expect($request->installedThemeId)
        ->toBe('my-theme');
});
