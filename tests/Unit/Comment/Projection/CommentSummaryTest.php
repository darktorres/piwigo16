<?php

declare(strict_types=1);

use Piwigo\Comment\Projection\CommentSummary;
use Piwigo\Common\ValueObject\CommentId;

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

test('toArray round-trips the exact same DB column shape fromRow narrowed', function (): void {
    $roundTripped = CommentSummary::fromRow(fullCommentSummaryRow())->toArray();

    expect($roundTripped)->toBe([
        'id' => 4,
        'date' => '2026-08-01 00:00:00',
        'author' => 'power_user',
        'content' => 'I keep coming back to this one.',
    ]);
});
