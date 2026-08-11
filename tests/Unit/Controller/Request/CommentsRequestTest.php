<?php

declare(strict_types=1);

use Piwigo\Controller\Request\CommentsRequest;
use Piwigo\Validation\InputValidator;

test('fromArrays returns defaults for an empty GET/POST', function (): void {
    $request = CommentsRequest::fromArrays([], [], 20, new InputValidator());

    expect($request->since)
        ->toBeNull()
        ->and($request->sortBy)
        ->toBe('date')
        ->and($request->sortOrder)
        ->toBe('DESC')
        ->and($request->itemsNumber)
        ->toBe(20)
        ->and($request->catDisplay)
        ->toBeNull()
        ->and($request->catId)
        ->toBeNull()
        ->and($request->authorFilter)
        ->toBeNull()
        ->and($request->authorDisplay)
        ->toBeNull()
        ->and($request->commentId)
        ->toBeNull()
        ->and($request->keywordFilter)
        ->toBeNull()
        ->and($request->keywordDisplay)
        ->toBeNull()
        ->and($request->action)
        ->toBeNull()
        ->and($request->actionCommentId)
        ->toBeNull()
        ->and($request->start)
        ->toBe(0)
        ->and($request->content)
        ->toBeNull()
        ->and($request->key)
        ->toBe('')
        ->and($request->imageId)
        ->toBeNull()
        ->and($request->websiteUrl)
        ->toBeNull();
});

test('fromArrays keeps a real since value, but excludes empty-string and "0" sentinels', function (): void {
    $real = CommentsRequest::fromArrays([
        'since' => '2026-08-01',
    ], [], 20, new InputValidator());
    expect($real->since)
        ->toBe('2026-08-01');

    $empty = CommentsRequest::fromArrays([
        'since' => '',
    ], [], 20, new InputValidator());
    expect($empty->since)
        ->toBeNull();

    $zero = CommentsRequest::fromArrays([
        'since' => '0',
    ], [], 20, new InputValidator());
    expect($zero->since)
        ->toBeNull();

    // A non-string, non-null since -- proves the is_string() check is
    // load-bearing on its own, not just redundant with the !== ''/!==
    // '0' checks either side of it (a bare int would satisfy both of
    // those but must still be rejected).
    $nonString = CommentsRequest::fromArrays([
        'since' => 123,
    ], [], 20, new InputValidator());
    expect($nonString->since)
        ->toBeNull();
});

test('fromArrays falls back to 10 for a non-numeric, non-all items_number', function (): void {
    $request = CommentsRequest::fromArrays([
        'items_number' => 'lots',
    ], [], 20, new InputValidator());

    expect($request->itemsNumber)
        ->toBe(10);
});

test('fromArrays keeps a valid items_number override', function (): void {
    $request = CommentsRequest::fromArrays([
        'items_number' => '50',
    ], [], 20, new InputValidator());

    expect($request->itemsNumber)
        ->toBe('50');
});

test('fromArrays keeps all as items_number', function (): void {
    $request = CommentsRequest::fromArrays([
        'items_number' => 'all',
    ], [], 20, new InputValidator());

    expect($request->itemsNumber)
        ->toBe('all');
});

test('fromArrays falls back to a real, present-but-neither-int-nor-string items_number to 10, not a mutated sentinel', function (): void {
    // A bool is neither is_int() nor is_string(), so the ternary's own
    // else-branch (10) is what's assigned here -- and, unlike the
    // "non-numeric string" test above, this value is never overwritten
    // by the later is_numeric() fallback (10 already is numeric), so
    // the exact literal 10 (not 9 or 11) is what has to survive to the
    // final value.
    $request = CommentsRequest::fromArrays([
        'items_number' => true,
    ], [], 20, new InputValidator());

    expect($request->itemsNumber)
        ->toBe(10);
});

/**
 * A mutation-testing sweep found the sort_by/sort_order allow-list
 * checks each carry one confirmed-equivalent mutant (RemoveArrayItem):
 * removing 'date' from sort_by's own ['date', 'image_id'] list, or
 * removing 'DESC' from sort_order's own ['DESC', 'ASC'] list. Both are
 * unobservable for the exact reason those two values are ALSO each
 * field's own ternary else-branch default -- verified live via a
 * temporary sed-applied mutation: an explicit sort_by=date/sort_order=
 * DESC request produces the identical final value whether the check
 * accepts the override or falls through to the same-valued default.
 */
test('fromArrays ignores an unrecognized sort_by/sort_order', function (): void {
    $request = CommentsRequest::fromArrays([
        'sort_by' => 'evil',
        'sort_order' => 'evil',
    ], [], 20, new InputValidator());

    expect($request->sortBy)
        ->toBe('date')
        ->and($request->sortOrder)
        ->toBe('DESC');
});

test('fromArrays accepts a recognized sort_by/sort_order', function (): void {
    $request = CommentsRequest::fromArrays([
        'sort_by' => 'image_id',
        'sort_order' => 'ASC',
    ], [], 20, new InputValidator());

    expect($request->sortBy)
        ->toBe('image_id')
        ->and($request->sortOrder)
        ->toBe('ASC');
});

test('fromArrays skips the cat filter when cat is 0', function (): void {
    $request = CommentsRequest::fromArrays([
        'cat' => '0',
    ], [], 20, new InputValidator());

    expect($request->catId)
        ->toBeNull()
        ->and($request->catDisplay)
        ->toBe('0');
});

test('fromArrays validates and keeps a numeric cat', function (): void {
    $request = CommentsRequest::fromArrays([
        'cat' => '5',
    ], [], 20, new InputValidator());

    expect($request->catId)
        ->toBe('5')
        ->and($request->catDisplay)
        ->toBe('5');
});

test('fromArrays rejects a non-digit cat', function (): void {
    expect(fn (): CommentsRequest => CommentsRequest::fromArrays([
        'cat' => 'abc',
    ], [], 20, new InputValidator()))
        ->toThrow(RuntimeException::class);
});

test('fromArrays splits author filter (truthy-gated) from author display (presence-gated)', function (): void {
    $zero = CommentsRequest::fromArrays([
        'author' => '0',
    ], [], 20, new InputValidator());
    expect($zero->authorFilter)
        ->toBeNull()
        ->and($zero->authorDisplay)
        ->toBe('0');

    $normal = CommentsRequest::fromArrays([
        'author' => 'alice',
    ], [], 20, new InputValidator());
    expect($normal->authorFilter)
        ->toBe('alice')
        ->and($normal->authorDisplay)
        ->toBe('alice');
});

test('fromArrays treats an empty author as absent, same as "0"', function (): void {
    $request = CommentsRequest::fromArrays([
        'author' => '',
    ], [], 20, new InputValidator());

    expect($request->authorFilter)
        ->toBeNull()
        ->and($request->authorDisplay)
        ->toBe('');
});

test('fromArrays rejects a non-string, non-empty-looking author for the filter', function (): void {
    // A bare int satisfies both !== '' and !== '0' but must still be
    // excluded by the leading is_string() check on its own.
    $request = CommentsRequest::fromArrays([
        'author' => 123,
    ], [], 20, new InputValidator());

    expect($request->authorFilter)
        ->toBeNull();
});

test('fromArrays splits keyword filter (truthy-gated) from keyword display (presence-gated)', function (): void {
    $zero = CommentsRequest::fromArrays([
        'keyword' => '0',
    ], [], 20, new InputValidator());
    expect($zero->keywordFilter)
        ->toBeNull()
        ->and($zero->keywordDisplay)
        ->toBe('0');
});

test('fromArrays treats an empty keyword as absent, same as "0"', function (): void {
    $request = CommentsRequest::fromArrays([
        'keyword' => '',
    ], [], 20, new InputValidator());

    expect($request->keywordFilter)
        ->toBeNull()
        ->and($request->keywordDisplay)
        ->toBe('');
});

test('fromArrays rejects a non-string, non-empty-looking keyword for the filter', function (): void {
    $request = CommentsRequest::fromArrays([
        'keyword' => 456,
    ], [], 20, new InputValidator());

    expect($request->keywordFilter)
        ->toBeNull();
});

test('fromArrays validates and parses a numeric comment_id', function (): void {
    $request = CommentsRequest::fromArrays([
        'comment_id' => '42',
    ], [], 20, new InputValidator());

    expect($request->commentId)
        ->toBe(42);
});

test('fromArrays rejects a non-digit comment_id', function (): void {
    expect(fn (): CommentsRequest => CommentsRequest::fromArrays([
        'comment_id' => 'abc',
    ], [], 20, new InputValidator()))
        ->toThrow(RuntimeException::class);
});

test('fromArrays treats an empty comment_id as absent, not a validation failure', function (): void {
    $request = CommentsRequest::fromArrays([
        'comment_id' => '',
    ], [], 20, new InputValidator());

    expect($request->commentId)
        ->toBeNull();
});

test('fromArrays picks the first matching action in delete/validate/edit order', function (): void {
    $request = CommentsRequest::fromArrays([
        'validate' => '7',
        'edit' => '9',
    ], [], 20, new InputValidator());

    expect($request->action)
        ->toBe('validate')
        ->and($request->actionCommentId)
        ->toBe(7);
});

test('fromArrays recognizes a bare delete action with no other action keys present', function (): void {
    // Kills the RemoveArrayItem mutant that drops 'delete' from the
    // foreach loop's own action list: with nothing else set, that
    // mutant would silently skip 'delete' entirely and leave action
    // null instead.
    $request = CommentsRequest::fromArrays([
        'delete' => '3',
    ], [], 20, new InputValidator());

    expect($request->action)
        ->toBe('delete')
        ->and($request->actionCommentId)
        ->toBe(3);
});

test('fromArrays recognizes a bare edit action with no other action keys present', function (): void {
    // Same as the "delete" test above, for the RemoveArrayItem mutant
    // that drops 'edit' instead.
    $request = CommentsRequest::fromArrays([
        'edit' => '5',
    ], [], 20, new InputValidator());

    expect($request->action)
        ->toBe('edit')
        ->and($request->actionCommentId)
        ->toBe(5);
});

test('fromArrays rejects a non-digit action value', function (): void {
    // Kills the RemoveMethodCall mutant on the per-action validate()
    // call: without it, the later `assert(is_numeric(...))` is a total
    // no-op in this environment (zend.assertions=-1), so a non-digit
    // value would silently (int)-cast to 0 instead of throwing.
    expect(fn (): CommentsRequest => CommentsRequest::fromArrays([
        'delete' => 'abc',
    ], [], 20, new InputValidator()))
        ->toThrow(RuntimeException::class);
});

test('fromArrays parses start as an int', function (): void {
    $request = CommentsRequest::fromArrays([
        'start' => '30',
    ], [], 20, new InputValidator());

    expect($request->start)
        ->toBe(30);
});

test('fromArrays falls back to 0 when start is present but not scalar', function (): void {
    // Kills the LogicalAndToLogicalOr mutant (isset() and is_scalar()
    // -> isset() or is_scalar()): a present-but-non-scalar $start
    // (isset() true, is_scalar() false) is real-0 vs. a mutated
    // intval() of the array itself (PHP's own intval() on a non-empty
    // array is 1, not 0).
    $request = CommentsRequest::fromArrays([
        'start' => ['nested'],
    ], [], 20, new InputValidator());

    expect($request->start)
        ->toBe(0);
});

test('fromArrays parses the edit-submit POST fields', function (): void {
    $request = CommentsRequest::fromArrays([], [
        'content' => 'hello',
        'key' => 'somekey',
        'image_id' => '12',
        'website_url' => 'example.com',
    ], 20, new InputValidator());

    expect($request->content)
        ->toBe('hello')
        ->and($request->key)
        ->toBe('somekey')
        ->and($request->imageId)
        ->toBe('12')
        ->and($request->websiteUrl)
        ->toBe('example.com');
});

test('fromArrays treats an empty or zero content as absent', function (): void {
    $empty = CommentsRequest::fromArrays([], [
        'content' => '',
    ], 20, new InputValidator());
    $zero = CommentsRequest::fromArrays([], [
        'content' => '0',
    ], 20, new InputValidator());

    expect($empty->content)
        ->toBeNull()
        ->and($zero->content)
        ->toBeNull();
});
