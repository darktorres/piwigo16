<?php

declare(strict_types=1);

use Piwigo\Comment\Projection\CommentSummary;
use Piwigo\Common\ValueObject\CommentId;
use Piwigo\Common\ValueObject\SqlDateTime;

/**
 * @return array<string, mixed>
 */
function fullCommentSummaryRow(): array
{
    return [
        'id' => '4',
        'date' => '2026-08-01 00:00:00',
        'author' => 'power_user',
        'content' => 'I keep coming back to this one.',
    ];
}

test('fromRow narrows every column to its real type', function (): void {
    $summary = CommentSummary::fromRow(fullCommentSummaryRow());

    expect($summary->id)->toEqual(CommentId::from(4))
        ->and($summary->date)->toBe('2026-08-01 00:00:00')
        ->and($summary->author)->toBe('power_user')
        ->and($summary->content)->toBe('I keep coming back to this one.');
});

test('fromRow keeps an already-hydrated SqlDateTime instance as-is', function (): void {
    // Covers the getArrayResult() Gotcha #1 shape: a real Doctrine array
    // hydration would already have converted date via SqlDateTimeType.
    $row = fullCommentSummaryRow();
    $row['date'] = SqlDateTime::from('2026-08-02 12:00:00');

    expect(CommentSummary::fromRow($row)->date)->toBe('2026-08-02 12:00:00');
});

test('fromRow defaults every nullable column to null when absent', function (): void {
    $row = fullCommentSummaryRow();
    foreach (['date', 'author', 'content'] as $key) {
        $row[$key] = null;
    }

    $summary = CommentSummary::fromRow($row);

    expect($summary->date)->toBeNull()
        ->and($summary->author)->toBeNull()
        ->and($summary->content)->toBeNull();
});

test('fromRow throws when id is missing', function (): void {
    // Same reasoning as Comment::fromRow()'s own "throws when id is
    // missing" test: this table's `id` column is the real NOT NULL
    // primary key, always present for a real fetched row -- a loud
    // failure here isn't a behavior change any real caller
    // (PwgImages::getInfo()) could actually hit.
    $row = fullCommentSummaryRow();
    $row['id'] = null;

    CommentSummary::fromRow($row);
})->throws(InvalidArgumentException::class);

test('fromRow throws with the real debug type of a non-null but invalid id', function (): void {
    // The test above sets id to null itself, so `$row['id'] ?? null`
    // resolves to null whether or not that coalesce actually reads
    // $row['id'] -- can't tell it apart from a mutated bare `null`. A
    // non-null-but-still-invalid id (CommentId::tryFrom() rejects any
    // non-positive-integer-string) forces the exception message's own
    // get_debug_type($row['id'] ?? null) call to reflect the real value.
    $row = fullCommentSummaryRow();
    $row['id'] = 'not-a-number';

    expect(fn () => CommentSummary::fromRow($row))
        ->toThrow(InvalidArgumentException::class, 'Expected a positive comment id, got string');
});

test('toArray round-trips the exact same DB column shape fromRow narrowed', function (): void {
    $roundTripped = CommentSummary::fromRow(fullCommentSummaryRow())->toArray();

    expect($roundTripped)->toBe([
        'id' => 4,
        'date' => '2026-08-01 00:00:00',
        'author' => 'power_user',
        'content' => 'I keep coming back to this one.',
    ]);
});
