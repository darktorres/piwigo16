<?php

declare(strict_types=1);

use Piwigo\Controller\Admin\Request\ThemeIdRequest;

test('fromArray accepts a well-formed theme id', function (): void {
    $request = ThemeIdRequest::fromArray(['theme' => 'my-theme_2']);

    expect($request->themeId)->toBe('my-theme_2');
});

test('fromArray rejects a missing theme param', function (): void {
    expect(fn (): ThemeIdRequest => ThemeIdRequest::fromArray([]))
        ->toThrow(RuntimeException::class);
});

test('fromArray rejects an empty theme param', function (): void {
    expect(fn (): ThemeIdRequest => ThemeIdRequest::fromArray(['theme' => '']))
        ->toThrow(RuntimeException::class);
});

test('fromArray rejects a theme param with a path separator', function (): void {
    expect(fn (): ThemeIdRequest => ThemeIdRequest::fromArray(['theme' => '../../etc/passwd']))
        ->toThrow(RuntimeException::class);
});

test('fromArray rejects a non-string theme param', function (): void {
    expect(fn (): ThemeIdRequest => ThemeIdRequest::fromArray(['theme' => ['nested' => 'array']]))
        ->toThrow(RuntimeException::class);
});
