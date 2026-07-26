<?php

declare(strict_types=1);

use Piwigo\Controller\Admin\Request\MaintenanceDispatchRequest;

test('fromArray does not require a CSRF check when action is absent', function (): void {
    $request = MaintenanceDispatchRequest::fromArray([]);

    expect($request->requiresCsrfCheck)->toBeFalse()
        ->and($request->tab)->toBe('actions');
});

test('fromArray requires a CSRF check when action is present', function (): void {
    $request = MaintenanceDispatchRequest::fromArray(['action' => 'database']);

    expect($request->requiresCsrfCheck)->toBeTrue();
});

test('fromArray accepts a valid tab', function (): void {
    $request = MaintenanceDispatchRequest::fromArray(['tab' => 'sys']);

    expect($request->tab)->toBe('sys');
});

test('fromArray rejects an unknown tab', function (): void {
    expect(fn (): MaintenanceDispatchRequest => MaintenanceDispatchRequest::fromArray(['tab' => '../../etc']))
        ->toThrow(RuntimeException::class);
});
