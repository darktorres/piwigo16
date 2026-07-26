<?php

declare(strict_types=1);

use Piwigo\Admin\Maintenance\Request\MaintenanceActionRequest;

test('fromArray returns the requested action', function (): void {
    $request = MaintenanceActionRequest::fromArray(['action' => 'database']);

    expect($request->action)->toBe('database');
});

test('fromArray defaults to an empty string when the action param is missing', function (): void {
    $request = MaintenanceActionRequest::fromArray([]);

    expect($request->action)->toBe('');
});

test('fromArray defaults to an empty string when the action param is not a string', function (): void {
    $request = MaintenanceActionRequest::fromArray(['action' => ['nested' => 'array']]);

    expect($request->action)->toBe('');
});
