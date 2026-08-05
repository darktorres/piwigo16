<?php

declare(strict_types=1);

use Piwigo\Controller\Admin\Request\ExtensionTabRequest;
use Piwigo\Validation\InputValidator;

test('fromArray defaults to installed when the tab param is absent', function (): void {
    $request = ExtensionTabRequest::fromArray([], '/^(installed|update|new)$/', new InputValidator());

    expect($request->tab)->toBe('installed');
});

test('fromArray accepts a tab matching the given pattern', function (): void {
    $request = ExtensionTabRequest::fromArray(['tab' => 'update'], '/^(installed|update|new)$/', new InputValidator());

    expect($request->tab)->toBe('update');
});

test('fromArray accepts the extra standard_pages tab when the caller passes that pattern', function (): void {
    $request = ExtensionTabRequest::fromArray(['tab' => 'standard_pages'], '/^(installed|update|new|standard_pages)$/', new InputValidator());

    expect($request->tab)->toBe('standard_pages');
});

test('fromArray rejects a tab not matching the given pattern', function (): void {
    expect(fn (): ExtensionTabRequest => ExtensionTabRequest::fromArray(['tab' => 'standard_pages'], '/^(installed|update|new)$/', new InputValidator()))
        ->toThrow(RuntimeException::class);
});

test('fromArray rejects a path-traversal tab value', function (): void {
    expect(fn (): ExtensionTabRequest => ExtensionTabRequest::fromArray(['tab' => '../../etc/passwd'], '/^(installed|update|new)$/', new InputValidator()))
        ->toThrow(RuntimeException::class);
});
