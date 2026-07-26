<?php

declare(strict_types=1);

use Piwigo\Admin\Request\AdminShellRequest;

test('fromArrays returns defaults for an empty GET/POST', function (): void {
    $request = AdminShellRequest::fromArrays([], []);

    expect($request->pluginsNewOrderPresent)->toBeFalse()
        ->and($request->pluginsNewOrder)->toBeNull()
        ->and($request->changeThemePresent)->toBeFalse()
        ->and($request->changeThemeUrlParams)->toBe([])
        ->and($request->testGet)->toBe([])
        ->and($request->isPostNonEmpty)->toBeFalse();
});

test('fromArrays rejects a malformed page', function (): void {
    expect(fn (): AdminShellRequest => AdminShellRequest::fromArrays(['page' => 'bad page!'], []))
        ->toThrow(RuntimeException::class);
});

test('fromArrays rejects a malformed section', function (): void {
    expect(fn (): AdminShellRequest => AdminShellRequest::fromArrays(['section' => '123'], []))
        ->toThrow(RuntimeException::class);
});

test('fromArrays reports pluginsNewOrderPresent and passes the raw value through', function (): void {
    $request = AdminShellRequest::fromArrays(['plugins_new_order' => 'foo,bar'], []);

    expect($request->pluginsNewOrderPresent)->toBeTrue()
        ->and($request->pluginsNewOrder)->toBe('foo,bar');
});

test('fromArrays builds changeThemeUrlParams in page/tab/section order', function (): void {
    $request = AdminShellRequest::fromArrays(['change_theme' => '1', 'section' => 'default', 'page' => 'configuration'], []);

    expect(array_keys($request->changeThemeUrlParams))->toBe(['page', 'section'])
        ->and($request->changeThemeUrlParams['page'])->toBe('configuration')
        ->and($request->changeThemeUrlParams['section'])->toBe('default');
});

test('fromArrays skips a non-scalar changeThemeUrlParams entry', function (): void {
    $request = AdminShellRequest::fromArrays(['tab' => ['x']], []);

    expect($request->changeThemeUrlParams)->toBe([]);
});

test('fromArrays strips page/section/tag from testGet but keeps other keys', function (): void {
    $request = AdminShellRequest::fromArrays(['page' => 'cat_list', 'section' => 'x', 'tag' => 'y', 'start' => '10'], []);

    expect($request->testGet)->toBe(['start' => '10']);
});

test('fromArrays reports isPostNonEmpty when POST has data', function (): void {
    $request = AdminShellRequest::fromArrays([], ['submit' => '1']);

    expect($request->isPostNonEmpty)->toBeTrue();
});
