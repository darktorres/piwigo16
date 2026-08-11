<?php

declare(strict_types=1);

use Piwigo\Controller\Admin\Request\BatchManagerRequest;
use Piwigo\Validation\InputValidator;

test('fromArrays accepts a well-formed array selection (validated as an array, not a scalar)', function (): void {
    // isArray=true is a real, load-bearing literal on this validate()
    // call -- forcing it false would make validate() require 'selection'
    // to be a scalar instead, and this well-formed array of real IDs
    // would then fail is_scalar() and throw, wrongly.
    expect(fn (): BatchManagerRequest => BatchManagerRequest::fromArrays([], [
        'selection' => ['5', '10'],
    ], [], new InputValidator()))
        ->not->toThrow(RuntimeException::class);
});

test('fromArrays rejects a selection item that is not a valid ID', function (): void {
    expect(fn (): BatchManagerRequest => BatchManagerRequest::fromArrays([], [
        'selection' => ['not-an-id'],
    ], [], new InputValidator()))
        ->toThrow(RuntimeException::class);
});

test('fromArrays rejects an invalid display value', function (): void {
    expect(fn (): BatchManagerRequest => BatchManagerRequest::fromArrays([], [], [
        'display' => 'bogus',
    ], new InputValidator()))
        ->toThrow(RuntimeException::class);
});

test('fromArrays reads a real page value from GET', function (): void {
    $request = BatchManagerRequest::fromArrays([
        'page' => 'batch_manager',
    ], [], [], new InputValidator());

    expect($request->page)
        ->toBe('batch_manager');
});

test('fromArrays returns defaults for an empty GET/POST/REQUEST', function (): void {
    $request = BatchManagerRequest::fromArrays([], [], [], new InputValidator());

    expect($request->page)
        ->toBe('')
        ->and($request->start)
        ->toBe(0)
        ->and($request->tab)
        ->toBe('global')
        ->and($request->action)
        ->toBeNull()
        ->and($request->nbOrphansDeleted)
        ->toBeNull()
        ->and($request->nbMd5sumAdded)
        ->toBeNull()
        ->and($request->isSubmitFilter)
        ->toBeFalse()
        ->and($request->post)
        ->toBe([])
        ->and($request->urlFilterPresent)
        ->toBeFalse()
        ->and($request->urlFilterTokens)
        ->toBe([]);
});

test('fromArrays parses a valid start from REQUEST', function (): void {
    $request = BatchManagerRequest::fromArrays([], [], [
        'start' => '12',
    ], new InputValidator());

    expect($request->start)
        ->toBe(12);
});

test('fromArrays uses the real negative start value once past the reset boundary, not just 0', function (): void {
    // A -1 REQUEST start is < 0, so it resets to 0, same as any other
    // negative value would -- but the boundary literal `0` itself is a
    // separate mutable target from the `<` comparison operator. Pinning
    // the *exact* threshold (not -1, which a mutated `< -1` would also
    // correctly reset) needs a value strictly between the real and a
    // mutated boundary: -1 is < 0 (real) but NOT < -1 (a mutated
    // DecrementInteger), so it distinguishes them by *not* resetting
    // under the mutant, surfacing the raw -1 instead of 0.
    //
    // (The exact boundary of 0 itself, and a mutated `< 1`, are both
    // confirmed-equivalent here: (int) truncates any REQUEST start in
    // [0, 1) down to 0 anyway, so "reset to 0" and "use the real
    // truncated value" are indistinguishable through this property for
    // every value that would tell `<` apart from `<=`, or 0 apart from
    // a mutated 1.)
    $request = BatchManagerRequest::fromArrays([], [], [
        'start' => '-1',
    ], new InputValidator());

    expect($request->start)
        ->toBe(0);
});

test('fromArrays resets start to 0 when the filter form is submitted, even with a positive REQUEST start', function (): void {
    $request = BatchManagerRequest::fromArrays([], [
        'submitFilter' => '1',
    ], [
        'start' => '12',
        'submitFilter' => '1',
    ], new InputValidator());

    expect($request->isSubmitFilter)
        ->toBeTrue()
        ->and($request->start)
        ->toBe(0);
});

test('fromArrays resets start to 0 when display is all', function (): void {
    $request = BatchManagerRequest::fromArrays([], [], [
        'start' => '12',
        'display' => 'all',
    ], new InputValidator());

    expect($request->start)
        ->toBe(0);
});

test('fromArrays reads a valid mode as tab', function (): void {
    $request = BatchManagerRequest::fromArrays([
        'mode' => 'unit',
    ], [], [], new InputValidator());

    expect($request->tab)
        ->toBe('unit');
});

test('fromArrays rejects an invalid mode', function (): void {
    expect(fn (): BatchManagerRequest => BatchManagerRequest::fromArrays([
        'mode' => 'bogus',
    ], [], [], new InputValidator()))
        ->toThrow(RuntimeException::class);
});

test('fromArrays validates and parses nb_orphans_deleted only for the delete_orphans action', function (): void {
    $matching = BatchManagerRequest::fromArrays([
        'action' => 'delete_orphans',
        'nb_orphans_deleted' => '3',
    ], [], [], new InputValidator());
    $wrongAction = BatchManagerRequest::fromArrays([
        'action' => 'sync_md5sum',
        'nb_orphans_deleted' => '3',
    ], [], [], new InputValidator());

    expect($matching->nbOrphansDeleted)
        ->toBe(3)
        ->and($wrongAction->nbOrphansDeleted)
        ->toBeNull();
});

test('fromArrays rejects a non-digit nb_orphans_deleted', function (): void {
    expect(fn (): BatchManagerRequest => BatchManagerRequest::fromArrays([
        'action' => 'delete_orphans',
        'nb_orphans_deleted' => 'abc',
    ], [], [], new InputValidator()))
        ->toThrow(RuntimeException::class);
});

test('fromArrays defaults nb_orphans_deleted to exactly 0 for an empty (non-numeric) value', function (): void {
    // An empty string passes InputValidator::validate() itself (its own
    // emptyValue() short-circuit skips the /^\d+$/ pattern check for an
    // empty, non-mandatory value), reaching the is_numeric() ? (int) : 0
    // fallback for real -- '3'/'abc' above only ever exercise the
    // already-numeric and validation-rejected paths, neither of which
    // reaches this literal 0.
    $request = BatchManagerRequest::fromArrays([
        'action' => 'delete_orphans',
        'nb_orphans_deleted' => '',
    ], [], [], new InputValidator());

    expect($request->nbOrphansDeleted)
        ->toBe(0);
});

test('fromArrays validates and parses nb_md5sum_added only for the sync_md5sum action', function (): void {
    $request = BatchManagerRequest::fromArrays([
        'action' => 'sync_md5sum',
        'nb_md5sum_added' => '7',
    ], [], [], new InputValidator());

    expect($request->nbMd5sumAdded)
        ->toBe(7);
});

test('fromArrays defaults nb_md5sum_added to exactly 0 for an empty (non-numeric) value', function (): void {
    $request = BatchManagerRequest::fromArrays([
        'action' => 'sync_md5sum',
        'nb_md5sum_added' => '',
    ], [], [], new InputValidator());

    expect($request->nbMd5sumAdded)
        ->toBe(0);
});

test('fromArrays rejects a non-digit nb_md5sum_added', function (): void {
    expect(fn (): BatchManagerRequest => BatchManagerRequest::fromArrays([
        'action' => 'sync_md5sum',
        'nb_md5sum_added' => 'abc',
    ], [], [], new InputValidator()))
        ->toThrow(RuntimeException::class);
});

test('fromArrays retains the full raw post bag', function (): void {
    $request = BatchManagerRequest::fromArrays([], [
        'filter_category_use' => '1',
        'filter_category' => '5',
    ], [], new InputValidator());

    expect($request->post)
        ->toBe([
            'filter_category_use' => '1',
            'filter_category' => '5',
        ]);
});

test('fromArrays normalizes a comma-separated filter string into tokens', function (): void {
    $request = BatchManagerRequest::fromArrays([
        'filter' => 'category-5,level-1',
    ], [], [], new InputValidator());

    expect($request->urlFilterPresent)
        ->toBeTrue()
        ->and($request->urlFilterTokens)
        ->toBe(['category-5', 'level-1']);
});

test('fromArrays keeps an already-array filter as tokens', function (): void {
    $request = BatchManagerRequest::fromArrays([
        'filter' => ['category-5', 'level-1'],
    ], [], [], new InputValidator());

    expect($request->urlFilterTokens)
        ->toBe(['category-5', 'level-1']);
});

test('fromArrays casts a non-string scalar array-filter element to string, and \'\' for a non-scalar element', function (): void {
    // The two existing array-filter/string-filter tests above only ever
    // use values that are already strings, which can't tell the real
    // (string) cast apart from a mutated no-op, or the '' fallback apart
    // from a mutated non-empty placeholder.
    $request = BatchManagerRequest::fromArrays([
        'filter' => [5, ['nested']],
    ], [], [], new InputValidator());

    expect($request->urlFilterTokens)
        ->toBe(['5', '']);
});

test('fromArrays casts a non-string scalar filter value to string, and \'\' for a non-scalar value', function (): void {
    $intFilter = BatchManagerRequest::fromArrays([
        'filter' => 5,
    ], [], [], new InputValidator());
    expect($intFilter->urlFilterTokens)
        ->toBe(['5']);

    $nonScalarFilter = BatchManagerRequest::fromArrays([
        'filter' => new stdClass(),
    ], [], [], new InputValidator());
    expect($nonScalarFilter->urlFilterTokens)
        ->toBe(['']);
});
