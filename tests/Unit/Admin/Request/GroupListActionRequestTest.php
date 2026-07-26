<?php

declare(strict_types=1);

use Piwigo\Admin\Request\GroupListActionRequest;

test('fromArrays does not require a CSRF check for a plain GET with no relevant params', function (): void {
    $request = GroupListActionRequest::fromArrays([], ['page' => 'group_list']);

    expect($request->requiresCsrfCheck)->toBeFalse();
});

test('fromArrays requires a CSRF check when POST data is present', function (): void {
    $request = GroupListActionRequest::fromArrays(['group_name' => 'x'], []);

    expect($request->requiresCsrfCheck)->toBeTrue();
});

test('fromArrays requires a CSRF check when the delete GET param is present', function (): void {
    $request = GroupListActionRequest::fromArrays([], ['delete' => '3']);

    expect($request->requiresCsrfCheck)->toBeTrue();
});

test('fromArrays requires a CSRF check when the toggle_is_default GET param is present', function (): void {
    $request = GroupListActionRequest::fromArrays([], ['toggle_is_default' => '3']);

    expect($request->requiresCsrfCheck)->toBeTrue();
});
