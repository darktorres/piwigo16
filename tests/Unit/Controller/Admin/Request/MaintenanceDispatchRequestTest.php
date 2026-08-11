<?php

declare(strict_types=1);

use Piwigo\Controller\Admin\Request\MaintenanceDispatchRequest;
use Piwigo\Validation\InputValidator;

test('fromArray does not require a CSRF check when action is absent', function (): void {
    $request = MaintenanceDispatchRequest::fromArray([], new InputValidator());

    expect($request->requiresCsrfCheck)
        ->toBeFalse()
        ->and($request->tab)
        ->toBe('actions');
});

test('fromArray requires a CSRF check when action is present', function (): void {
    $request = MaintenanceDispatchRequest::fromArray([
        'action' => 'database',
    ], new InputValidator());

    expect($request->requiresCsrfCheck)
        ->toBeTrue();
});

test('fromArray accepts a valid tab', function (): void {
    $request = MaintenanceDispatchRequest::fromArray([
        'tab' => 'sys',
    ], new InputValidator());

    expect($request->tab)
        ->toBe('sys');
});

test('fromArray rejects an unknown tab', function (): void {
    expect(fn (): MaintenanceDispatchRequest => MaintenanceDispatchRequest::fromArray([
        'tab' => '../../etc',
    ], new InputValidator()))
        ->toThrow(RuntimeException::class);
});
