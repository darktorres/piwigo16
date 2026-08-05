<?php

declare(strict_types=1);

use Piwigo\Admin\Request\BatchManagerGlobalRequest;
use Piwigo\Validation\InputValidator;

test('fromArrays returns defaults for an empty GET/POST', function (): void {
    $request = BatchManagerGlobalRequest::fromArrays([], [], new InputValidator());

    expect($request->isSubmitted)->toBeFalse()
        ->and($request->selectAction)->toBe('')
        ->and($request->nbPhotosDeletedPresent)->toBeFalse()
        ->and($request->nbPhotosDeleted)->toBe(0)
        ->and($request->isSetSelected)->toBeFalse()
        ->and($request->wholeSet)->toBe('')
        ->and($request->selectionPresent)->toBeFalse()
        ->and($request->selection)->toBe([])
        ->and($request->post)->toBe([])
        ->and($request->page)->toBe('')
        ->and($request->displayRequested)->toBeFalse()
        ->and($request->displayRaw)->toBeNull();
});

test('fromArrays parses nb_photos_deleted', function (): void {
    $request = BatchManagerGlobalRequest::fromArrays([], ['nb_photos_deleted' => '5'], new InputValidator());

    expect($request->nbPhotosDeletedPresent)->toBeTrue()
        ->and($request->nbPhotosDeleted)->toBe(5);
});

test('fromArrays rejects a non-digit nb_photos_deleted', function (): void {
    expect(fn (): BatchManagerGlobalRequest => BatchManagerGlobalRequest::fromArrays([], ['nb_photos_deleted' => 'abc'], new InputValidator()))
        ->toThrow(RuntimeException::class);
});

test('fromArrays exposes whole_set when setSelected is present', function (): void {
    $request = BatchManagerGlobalRequest::fromArrays([], ['setSelected' => '1', 'whole_set' => '1,2,3'], new InputValidator());

    expect($request->isSetSelected)->toBeTrue()
        ->and($request->wholeSet)->toBe('1,2,3');
});

test('fromArrays exposes the raw selection array', function (): void {
    $request = BatchManagerGlobalRequest::fromArrays([], ['selection' => ['1', '2']], new InputValidator());

    expect($request->selectionPresent)->toBeTrue()
        ->and($request->selection)->toBe(['1', '2']);
});

test('fromArrays falls back to an empty whole_set when the value is present but not a string', function (): void {
    // isset() alone isn't enough -- requires both isset AND is_string. A
    // non-string value that slipped through an OR instead of the real AND
    // would blow up at the constructor's `string $wholeSet` boundary
    // (strict_types), so this doubles as a no-throw assertion.
    $request = BatchManagerGlobalRequest::fromArrays([], ['whole_set' => 123], new InputValidator());

    expect($request->wholeSet)->toBe('');
});

test('fromArrays treats a non-array selection as absent, not throwing at the array $selection boundary', function (): void {
    $request = BatchManagerGlobalRequest::fromArrays([], ['selection' => 'not-an-array'], new InputValidator());

    expect($request->selectionPresent)->toBeFalse()
        ->and($request->selection)->toBe([]);
});

test('fromArrays rejects a non-digit del_tags element', function (): void {
    expect(fn (): BatchManagerGlobalRequest => BatchManagerGlobalRequest::fromArrays([], ['del_tags' => ['abc']], new InputValidator()))
        ->toThrow(RuntimeException::class);
});

test('fromArrays accepts a valid del_tags array without throwing', function (): void {
    // del_tags is validated as an array (is_array, not is_scalar) -- a
    // valid array of digit ids must NOT throw, matching the ID pattern.
    $request = BatchManagerGlobalRequest::fromArrays([], ['del_tags' => ['5', '6']], new InputValidator());

    expect($request->post['del_tags'])->toBe(['5', '6']);
});

test('fromArrays rejects a non-digit associate element', function (): void {
    expect(fn (): BatchManagerGlobalRequest => BatchManagerGlobalRequest::fromArrays([], ['associate' => ['abc']], new InputValidator()))
        ->toThrow(RuntimeException::class);
});

test('fromArrays accepts a valid associate array without throwing', function (): void {
    $request = BatchManagerGlobalRequest::fromArrays([], ['associate' => ['5', '6']], new InputValidator());

    expect($request->post['associate'])->toBe(['5', '6']);
});

test('fromArrays rejects a non-digit move', function (): void {
    expect(fn (): BatchManagerGlobalRequest => BatchManagerGlobalRequest::fromArrays([], ['move' => 'abc'], new InputValidator()))
        ->toThrow(RuntimeException::class);
});

test('fromArrays accepts a valid scalar move without throwing', function (): void {
    // move is validated as a scalar (is_scalar, not is_array) -- a valid
    // digit id must NOT throw.
    $request = BatchManagerGlobalRequest::fromArrays([], ['move' => '5'], new InputValidator());

    expect($request->post['move'])->toBe('5');
});

test('fromArrays rejects a non-digit dissociate', function (): void {
    expect(fn (): BatchManagerGlobalRequest => BatchManagerGlobalRequest::fromArrays([], ['dissociate' => 'abc'], new InputValidator()))
        ->toThrow(RuntimeException::class);
});

test('fromArrays accepts a valid scalar dissociate without throwing', function (): void {
    $request = BatchManagerGlobalRequest::fromArrays([], ['dissociate' => '5'], new InputValidator());

    expect($request->post['dissociate'])->toBe('5');
});

test('fromArrays defaults nb_photos_deleted to 0 when present but empty', function (): void {
    // '' is "empty" per InputValidator's own emptyValue() check (so
    // validate() no-ops without even checking the digit pattern) but is
    // NOT is_numeric(), so this is the only way to reach the `: 0` fallback
    // on a present key without a validation throw.
    $request = BatchManagerGlobalRequest::fromArrays([], ['nb_photos_deleted' => ''], new InputValidator());

    expect($request->nbPhotosDeletedPresent)->toBeTrue()
        ->and($request->nbPhotosDeleted)->toBe(0);
});

test('fromArrays reads selectAction and retains the full post bag', function (): void {
    $request = BatchManagerGlobalRequest::fromArrays([], ['selectAction' => 'author', 'author' => 'Alice'], new InputValidator());

    expect($request->selectAction)->toBe('author')
        ->and($request->post)->toBe(['selectAction' => 'author', 'author' => 'Alice']);
});

test('fromArrays reads page from GET', function (): void {
    $request = BatchManagerGlobalRequest::fromArrays(['page' => 'batch_manager'], [], new InputValidator());

    expect($request->page)->toBe('batch_manager');
});

test('fromArrays treats an absent or zero display as not requested', function (): void {
    $absent = BatchManagerGlobalRequest::fromArrays([], [], new InputValidator());
    $zero = BatchManagerGlobalRequest::fromArrays(['display' => '0'], [], new InputValidator());

    expect($absent->displayRequested)->toBeFalse()
        ->and($zero->displayRequested)->toBeFalse();
});

test('fromArrays keeps a requested display value, including "all"', function (): void {
    $numeric = BatchManagerGlobalRequest::fromArrays(['display' => '50'], [], new InputValidator());
    $all = BatchManagerGlobalRequest::fromArrays(['display' => 'all'], [], new InputValidator());

    expect($numeric->displayRequested)->toBeTrue()
        ->and($numeric->displayRaw)->toBe('50')
        ->and($all->displayRequested)->toBeTrue()
        ->and($all->displayRaw)->toBe('all');
});

test('fromArrays treats a present but empty-string display as not requested', function (): void {
    // Distinct from the absent/'0' cases above -- '' is its own explicit
    // rejection branch (`$display_raw !== ''`), not covered by either.
    $request = BatchManagerGlobalRequest::fromArrays(['display' => ''], [], new InputValidator());

    expect($request->displayRequested)->toBeFalse();
});

test('fromArrays leaves displayRaw null when display is requested but not a string', function (): void {
    // display_requested can be true off of a non-string $get['display']
    // (fromArrays() doesn't require $get to come from a real superglobal),
    // in which case is_string() must gate displayRaw independently --
    // an OR here would pass the non-string through to the constructor's
    // `?string $displayRaw` boundary and throw under strict_types.
    $request = BatchManagerGlobalRequest::fromArrays(['display' => 123], [], new InputValidator());

    expect($request->displayRequested)->toBeTrue()
        ->and($request->displayRaw)->toBeNull();
});
