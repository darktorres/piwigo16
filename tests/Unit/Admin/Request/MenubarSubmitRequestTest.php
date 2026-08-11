<?php

declare(strict_types=1);

use Piwigo\Admin\Request\MenubarSubmitRequest;

test('fromArray detects an empty submit', function (): void {
    $request = MenubarSubmitRequest::fromArray([
        'submit' => '',
    ]);

    expect($request->isSubmitted)
        ->toBeTrue();
});

test('fromArray reports not submitted when the submit key is absent', function (): void {
    $request = MenubarSubmitRequest::fromArray([]);

    expect($request->isSubmitted)
        ->toBeFalse();
});

test('isHidden reflects the per-block hide_ key', function (): void {
    $request = MenubarSubmitRequest::fromArray([
        'hide_42' => '',
    ]);

    expect($request->isHidden(42))
        ->toBeTrue()
        ->and($request->isHidden(43))
        ->toBeFalse();
});

test('positionFor parses the per-block pos_ key', function (): void {
    $request = MenubarSubmitRequest::fromArray([
        'pos_42' => '150',
    ]);

    expect($request->positionFor(42))
        ->toBe(150);
});

test('positionFor defaults to 0 when the pos_ key is missing or not numeric', function (): void {
    $request = MenubarSubmitRequest::fromArray([
        'pos_42' => 'abc',
    ]);

    expect($request->positionFor(42))
        ->toBe(0)
        ->and($request->positionFor(99))
        ->toBe(0);
});
