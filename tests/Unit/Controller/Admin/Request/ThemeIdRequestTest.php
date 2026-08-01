<?php

declare(strict_types=1);

use Piwigo\Controller\Admin\Request\ThemeIdRequest;

/**
 * A mutation-testing sweep found fromArray()'s own `is_string($theme_raw)
 * ? $theme_raw : ''` fallback is a confirmed-equivalent mutant: for any
 * non-string $theme_raw, the resulting $theme (real '' or a mutated
 * non-empty placeholder) is only ever used inside the very next
 * validate() call, and InputValidator's own generic "is not valid"
 * rejection message is identical whether that call rejects the value for
 * being empty-and-mandatory (the real '') or for failing the theme-id
 * pattern (a mutated placeholder containing spaces/punctuation) -- $theme
 * is never otherwise observable, since construction never completes for
 * either input.
 */
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
