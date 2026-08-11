<?php

declare(strict_types=1);

use Piwigo\Admin\Request\CatListRequest;
use Piwigo\Validation\InputValidator;

test('fromArrays returns defaults for an empty GET/POST', function (): void {
    $request = CatListRequest::fromArrays([], [], new InputValidator());

    expect($request->isCsrfCheckRequired)
        ->toBeFalse()
        ->and($request->parentId)
        ->toBeNull()
        ->and($request->deleteId)
        ->toBeNull()
        ->and($request->photoDeletionMode)
        ->toBe('no_delete')
        ->and($request->isSubmitAdd)
        ->toBeFalse()
        ->and($request->virtualName)
        ->toBe('');
});

test('fromArrays requires a CSRF check when POST is non-empty', function (): void {
    $request = CatListRequest::fromArrays([], [
        'virtual_name' => 'x',
    ], new InputValidator());

    expect($request->isCsrfCheckRequired)
        ->toBeTrue();
});

test('fromArrays requires a CSRF check when delete is present in GET', function (): void {
    $request = CatListRequest::fromArrays([
        'delete' => '5',
    ], [], new InputValidator());

    expect($request->isCsrfCheckRequired)
        ->toBeTrue();
});

test('fromArrays parses parent_id as an int', function (): void {
    $request = CatListRequest::fromArrays([
        'parent_id' => '7',
    ], [], new InputValidator());

    expect($request->parentId)
        ->toBe(7);
});

test('fromArrays rejects a non-digit parent_id', function (): void {
    expect(fn (): CatListRequest => CatListRequest::fromArrays([
        'parent_id' => '1; DROP TABLE',
    ], [], new InputValidator()))
        ->toThrow(RuntimeException::class);
});

test('fromArrays parses delete as an int when numeric', function (): void {
    $request = CatListRequest::fromArrays([
        'delete' => '9',
    ], [], new InputValidator());

    expect($request->deleteId)
        ->toBe(9);
});

test('fromArrays nulls delete when non-numeric', function (): void {
    $request = CatListRequest::fromArrays([
        'delete' => 'abc',
    ], [], new InputValidator());

    expect($request->deleteId)
        ->toBeNull();
});

test('fromArrays overrides photoDeletionMode when present and a string', function (): void {
    $request = CatListRequest::fromArrays([
        'delete' => '9',
        'photo_deletion_mode' => 'force_delete',
    ], [], new InputValidator());

    expect($request->photoDeletionMode)
        ->toBe('force_delete');
});

test('fromArrays keeps the no_delete default for a non-string photo_deletion_mode', function (): void {
    $request = CatListRequest::fromArrays([
        'delete' => '9',
        'photo_deletion_mode' => ['x'],
    ], [], new InputValidator());

    expect($request->photoDeletionMode)
        ->toBe('no_delete');
});

test('fromArrays reports isSubmitAdd and the raw virtual_name', function (): void {
    $request = CatListRequest::fromArrays([], [
        'submitAdd' => '1',
        'virtual_name' => 'My Album',
    ], new InputValidator());

    expect($request->isSubmitAdd)
        ->toBeTrue()
        ->and($request->virtualName)
        ->toBe('My Album');
});

test('fromArrays normalizes a non-string virtual_name to an empty string', function (): void {
    $request = CatListRequest::fromArrays([], [
        'submitAdd' => '1',
        'virtual_name' => ['x'],
    ], new InputValidator());

    expect($request->virtualName)
        ->toBe('');
});
