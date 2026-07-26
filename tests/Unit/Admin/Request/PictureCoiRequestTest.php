<?php

declare(strict_types=1);

use Piwigo\Admin\Request\PictureCoiRequest;

test('fromArrays returns defaults for an empty GET/POST', function (): void {
    $request = PictureCoiRequest::fromArrays([], []);

    expect($request->imageId)->toBe(0)
        ->and($request->isSubmitted)->toBeFalse()
        ->and($request->coi)->toBeNull();
});

test('fromArrays parses a numeric image_id', function (): void {
    $request = PictureCoiRequest::fromArrays(['image_id' => '12'], []);

    expect($request->imageId)->toBe(12);
});

test('fromArrays rejects a non-digit image_id', function (): void {
    expect(fn (): PictureCoiRequest => PictureCoiRequest::fromArrays(['image_id' => '1; DROP TABLE'], []))
        ->toThrow(RuntimeException::class);
});

test('fromArrays reports isSubmitted when submit is present', function (): void {
    $request = PictureCoiRequest::fromArrays([], ['submit' => '1']);

    expect($request->isSubmitted)->toBeTrue();
});

test('fromArrays leaves coi null when l is absent', function (): void {
    $request = PictureCoiRequest::fromArrays([], ['submit' => '1', 't' => '0.1', 'r' => '0.2', 'b' => '0.3']);

    expect($request->coi)->toBeNull();
});

test('fromArrays leaves coi null when l is an empty string', function (): void {
    $request = PictureCoiRequest::fromArrays([], ['submit' => '1', 'l' => '']);

    expect($request->coi)->toBeNull();
});

test('fromArrays computes a 4-char coi code when l is a non-empty string', function (): void {
    $request = PictureCoiRequest::fromArrays([], [
        'submit' => '1',
        'l' => '0',
        't' => '0',
        'r' => '1',
        'b' => '1',
    ]);

    \PHPUnit\Framework\Assert::assertIsString($request->coi);
    expect($request->coi)->toBeString()
        ->and(strlen($request->coi))->toBe(4);
});

test('fromArrays treats a non-numeric t/r/b as a zero fraction', function (): void {
    $request = PictureCoiRequest::fromArrays([], [
        'submit' => '1',
        'l' => '0',
        't' => 'not-a-number',
        'r' => 'not-a-number',
        'b' => 'not-a-number',
    ]);

    \PHPUnit\Framework\Assert::assertIsString($request->coi);
    expect($request->coi)->toBeString()
        ->and(strlen($request->coi))->toBe(4);
});
