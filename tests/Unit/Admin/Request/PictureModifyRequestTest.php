<?php

declare(strict_types=1);

use Piwigo\Admin\Request\PictureModifyRequest;
use Piwigo\Validation\InputValidator;

test('fromArrays returns defaults for an empty GET/POST', function (): void {
    $request = PictureModifyRequest::fromArrays([], [], new InputValidator());

    expect($request->imageId)->toBe(0)
        ->and($request->deletePresent)->toBeFalse()
        ->and($request->syncMetadataPresent)->toBeFalse()
        ->and($request->isSubmitted)->toBeFalse()
        ->and($request->postLevel)->toBeNull()
        ->and($request->nameField)->toBeNull()
        ->and($request->authorField)->toBeNull()
        ->and($request->commentField)->toBeNull()
        ->and($request->dateCreation)->toBeNull()
        ->and($request->tagsRaw)->toBeNull()
        ->and($request->associate)->toBe([])
        ->and($request->represent)->toBe([]);
});

test('fromArrays parses image_id', function (): void {
    $request = PictureModifyRequest::fromArrays(['image_id' => '12'], [], new InputValidator());

    expect($request->imageId)->toBe(12);
});

test('fromArrays rejects a malformed image_id', function (): void {
    expect(fn (): PictureModifyRequest => PictureModifyRequest::fromArrays(['image_id' => '1; DROP TABLE'], [], new InputValidator()))
        ->toThrow(RuntimeException::class);
});

test('fromArrays rejects a malformed level', function (): void {
    expect(fn (): PictureModifyRequest => PictureModifyRequest::fromArrays([], ['level' => 'not-a-digit'], new InputValidator()))
        ->toThrow(RuntimeException::class);
});

test('fromArrays distinguishes an empty-string name submission from an absent one', function (): void {
    $submitted = PictureModifyRequest::fromArrays([], ['name' => ''], new InputValidator());
    $absent = PictureModifyRequest::fromArrays([], [], new InputValidator());

    expect($submitted->nameField)->toBe('')
        ->and($absent->nameField)->toBeNull();
});

test('fromArrays normalizes a non-string name to null, not empty string', function (): void {
    $request = PictureModifyRequest::fromArrays([], ['name' => ['x']], new InputValidator());

    expect($request->nameField)->toBeNull();
});

test('fromArrays passes author/comment strings through unchanged', function (): void {
    $request = PictureModifyRequest::fromArrays([], ['author' => 'Alice', 'comment' => 'A nice photo'], new InputValidator());

    expect($request->authorField)->toBe('Alice')
        ->and($request->commentField)->toBe('A nice photo');
});

test('fromArrays parses a valid date_creation', function (): void {
    $request = PictureModifyRequest::fromArrays([], ['date_creation' => '2026-01-15 10:00:00'], new InputValidator());

    expect($request->dateCreation)->toBe('2026-01-15 10:00:00');
});

test('fromArrays rejects a malformed date_creation', function (): void {
    expect(fn (): PictureModifyRequest => PictureModifyRequest::fromArrays([], ['date_creation' => 'not-a-date'], new InputValidator()))
        ->toThrow(RuntimeException::class);
});

test('fromArrays parses a valid associate list', function (): void {
    $request = PictureModifyRequest::fromArrays([], ['associate' => ['1', '3']], new InputValidator());

    expect($request->associate)->toBe([1, 3]);
});

test('fromArrays rejects a non-digit associate element', function (): void {
    expect(fn (): PictureModifyRequest => PictureModifyRequest::fromArrays([], ['associate' => ['1; DROP TABLE']], new InputValidator()))
        ->toThrow(RuntimeException::class);
});

test('fromArrays defaults associate/represent to empty lists when absent, without throwing', function (): void {
    $request = PictureModifyRequest::fromArrays([], [], new InputValidator());

    expect($request->associate)->toBe([])
        ->and($request->represent)->toBe([]);
});

test('fromArrays filters represent to numeric values only', function (): void {
    $request = PictureModifyRequest::fromArrays([], ['represent' => ['2', '4']], new InputValidator());

    expect($request->represent)->toBe([2, 4]);
});

test('fromArrays rejects a non-digit represent element', function (): void {
    expect(fn (): PictureModifyRequest => PictureModifyRequest::fromArrays([], ['represent' => ['1; DROP TABLE']], new InputValidator()))
        ->toThrow(RuntimeException::class);
});

test('fromArrays passes tagsRaw through unvalidated', function (): void {
    $request = PictureModifyRequest::fromArrays([], ['tags' => ['tag1', 'tag2']], new InputValidator());

    expect($request->tagsRaw)->toBe(['tag1', 'tag2']);
});

test('fromArrays casts a valid postLevel to int', function (): void {
    $request = PictureModifyRequest::fromArrays([], ['level' => '8'], new InputValidator());

    expect($request->postLevel)->toBe(8);
});
