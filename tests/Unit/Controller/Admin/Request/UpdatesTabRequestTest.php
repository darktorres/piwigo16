<?php

declare(strict_types=1);

use Piwigo\Controller\Admin\Request\UpdatesTabRequest;

test('fromArray accepts the pwg tab', function (): void {
    $request = UpdatesTabRequest::fromArray(['tab' => 'pwg']);

    expect($request->tab)->toBe('pwg');
});

test('fromArray accepts the ext tab', function (): void {
    $request = UpdatesTabRequest::fromArray(['tab' => 'ext']);

    expect($request->tab)->toBe('ext');
});

test('fromArray defaults to pwg when the tab param is missing', function (): void {
    $request = UpdatesTabRequest::fromArray([]);

    expect($request->tab)->toBe('pwg');
});

test('fromArray rejects an unknown tab value', function (): void {
    expect(fn (): UpdatesTabRequest => UpdatesTabRequest::fromArray(['tab' => '../../etc/passwd']))
        ->toThrow(RuntimeException::class);
});
