<?php

declare(strict_types=1);

use Piwigo\Ws\Request\TagListRequest;

test('fromArray reports absent when tag_list is missing', function (): void {
    $request = TagListRequest::fromArray([]);

    expect($request->present)
        ->toBeFalse()
        ->and($request->items)
        ->toBe([]);
});

test('fromArray reports absent when tag_list is not an array', function (): void {
    $request = TagListRequest::fromArray([
        'tag_list' => 'not-an-array',
    ]);

    expect($request->present)
        ->toBeFalse()
        ->and($request->items)
        ->toBe([]);
});

test('fromArray retains every raw element, including non-string ones', function (): void {
    $request = TagListRequest::fromArray([
        'tag_list' => ['sunset', 123, 'beach'],
    ]);

    expect($request->present)
        ->toBeTrue()
        ->and($request->items)
        ->toBe(['sunset', 123, 'beach']);
});
