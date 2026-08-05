<?php

declare(strict_types=1);

use Piwigo\Controller\Admin\Request\UpdatesTabRequest;
use Piwigo\Validation\InputValidator;

test('fromArray accepts the pwg tab', function (): void {
    $request = UpdatesTabRequest::fromArray(['tab' => 'pwg'], new InputValidator());

    expect($request->tab)->toBe('pwg');
});

test('fromArray accepts the ext tab', function (): void {
    $request = UpdatesTabRequest::fromArray(['tab' => 'ext'], new InputValidator());

    expect($request->tab)->toBe('ext');
});

test('fromArray defaults to pwg when the tab param is missing', function (): void {
    $request = UpdatesTabRequest::fromArray([], new InputValidator());

    expect($request->tab)->toBe('pwg');
});

test('fromArray rejects an unknown tab value', function (): void {
    expect(fn (): UpdatesTabRequest => UpdatesTabRequest::fromArray(['tab' => '../../etc/passwd'], new InputValidator()))
        ->toThrow(RuntimeException::class);
});
