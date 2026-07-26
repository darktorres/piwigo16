<?php

declare(strict_types=1);

use Piwigo\Admin\Request\ExtendForTemplatesSubmitRequest;

test('fromArray reports not submitted when the submit key is absent', function (): void {
    $request = ExtendForTemplatesSubmitRequest::fromArray([]);

    expect($request->isSubmitted)->toBeFalse();
});

test('fromArray parses the 4 parallel row arrays', function (): void {
    $request = ExtendForTemplatesSubmitRequest::fromArray([
        'submit' => '',
        'reptpl' => ['a.tpl', 'b.tpl'],
        'original' => ['a.tpl', 'b.tpl'],
        'url' => ['x', 'y'],
        'bound' => ['z', 'w'],
    ]);

    expect($request->isSubmitted)->toBeTrue()
        ->and($request->reptpl)->toBe(['a.tpl', 'b.tpl'])
        ->and($request->original)->toBe(['a.tpl', 'b.tpl'])
        ->and($request->url)->toBe(['x', 'y'])
        ->and($request->bound)->toBe(['z', 'w']);
});

test('fromArray defaults each row array to empty when not an array', function (): void {
    $request = ExtendForTemplatesSubmitRequest::fromArray([
        'submit' => '',
        'reptpl' => 'not-an-array',
    ]);

    expect($request->reptpl)->toBe([])
        ->and($request->original)->toBe([])
        ->and($request->url)->toBe([])
        ->and($request->bound)->toBe([]);
});
