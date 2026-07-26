<?php

declare(strict_types=1);

use Piwigo\Admin\Request\CatListRequest;

test('fromArrays returns defaults for an empty GET/POST', function (): void {
    $request = CatListRequest::fromArrays([], []);

    expect($request->isCsrfCheckRequired)->toBeFalse()
        ->and($request->parentId)->toBeNull()
        ->and($request->deleteId)->toBeNull()
        ->and($request->photoDeletionMode)->toBe('no_delete')
        ->and($request->isSubmitAdd)->toBeFalse()
        ->and($request->virtualName)->toBe('');
});

test('fromArrays requires a CSRF check when POST is non-empty', function (): void {
    $request = CatListRequest::fromArrays([], ['virtual_name' => 'x']);

    expect($request->isCsrfCheckRequired)->toBeTrue();
});

test('fromArrays requires a CSRF check when delete is present in GET', function (): void {
    $request = CatListRequest::fromArrays(['delete' => '5'], []);

    expect($request->isCsrfCheckRequired)->toBeTrue();
});

test('fromArrays parses parent_id as an int', function (): void {
    $request = CatListRequest::fromArrays(['parent_id' => '7'], []);

    expect($request->parentId)->toBe(7);
});

test('fromArrays rejects a non-digit parent_id', function (): void {
    expect(fn (): CatListRequest => CatListRequest::fromArrays(['parent_id' => '1; DROP TABLE'], []))
        ->toThrow(RuntimeException::class);
});

test('fromArrays parses delete as an int when numeric', function (): void {
    $request = CatListRequest::fromArrays(['delete' => '9'], []);

    expect($request->deleteId)->toBe(9);
});

test('fromArrays nulls delete when non-numeric', function (): void {
    $request = CatListRequest::fromArrays(['delete' => 'abc'], []);

    expect($request->deleteId)->toBeNull();
});

test('fromArrays overrides photoDeletionMode when present and a string', function (): void {
    $request = CatListRequest::fromArrays(['delete' => '9', 'photo_deletion_mode' => 'force_delete'], []);

    expect($request->photoDeletionMode)->toBe('force_delete');
});

test('fromArrays keeps the no_delete default for a non-string photo_deletion_mode', function (): void {
    $request = CatListRequest::fromArrays(['delete' => '9', 'photo_deletion_mode' => ['x']], []);

    expect($request->photoDeletionMode)->toBe('no_delete');
});

test('fromArrays reports isSubmitAdd and the raw virtual_name', function (): void {
    $request = CatListRequest::fromArrays([], ['submitAdd' => '1', 'virtual_name' => 'My Album']);

    expect($request->isSubmitAdd)->toBeTrue()
        ->and($request->virtualName)->toBe('My Album');
});

test('fromArrays normalizes a non-string virtual_name to an empty string', function (): void {
    $request = CatListRequest::fromArrays([], ['submitAdd' => '1', 'virtual_name' => ['x']]);

    expect($request->virtualName)->toBe('');
});
