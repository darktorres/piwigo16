<?php

declare(strict_types=1);

use Piwigo\Admin\Request\MaintenanceSysMethodRequest;

test('fromArray recognizes the pwg.activity_sys.getList method', function (): void {
    $request = MaintenanceSysMethodRequest::fromArray([
        'method' => 'pwg.activity_sys.getList',
    ]);

    expect($request->isActivitySysGetList)
        ->toBeTrue();
});

test('fromArray treats a missing method as not activity_sys.getList', function (): void {
    $request = MaintenanceSysMethodRequest::fromArray([]);

    expect($request->isActivitySysGetList)
        ->toBeFalse();
});

test('fromArray treats any other method value as not activity_sys.getList', function (): void {
    $request = MaintenanceSysMethodRequest::fromArray([
        'method' => 'pwg.something.else',
    ]);

    expect($request->isActivitySysGetList)
        ->toBeFalse();
});
