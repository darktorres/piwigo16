<?php

declare(strict_types=1);

use Piwigo\Admin\Request\ThemesInstalledActionRequest;

test('fromArray returns both action and theme when present', function (): void {
    $request = ThemesInstalledActionRequest::fromArray(['action' => 'activate', 'theme' => 'my-theme']);

    expect($request->action)->toBe('activate')
        ->and($request->themeId)->toBe('my-theme');
});

test('fromArray returns null action when absent', function (): void {
    $request = ThemesInstalledActionRequest::fromArray(['theme' => 'my-theme']);

    expect($request->action)->toBeNull();
});

test('fromArray returns null theme when absent', function (): void {
    $request = ThemesInstalledActionRequest::fromArray(['action' => 'activate']);

    expect($request->themeId)->toBeNull();
});

test('fromArray returns null for non-string values', function (): void {
    $request = ThemesInstalledActionRequest::fromArray(['action' => ['x'], 'theme' => ['y']]);

    expect($request->action)->toBeNull()
        ->and($request->themeId)->toBeNull();
});
