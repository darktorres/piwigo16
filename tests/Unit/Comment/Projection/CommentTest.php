<?php

declare(strict_types=1);

use Piwigo\Comment\Projection\Comment;

/**
 * @return array<string, mixed>
 */
function fullCommentRow(): array
{
    return [
        'id' => '2',
        'author' => 'regular_user',
        'author_id' => '3',
        'user_email' => 'regular@example.test',
        'date' => '2026-08-01 00:00:00',
        'image_id' => '2',
        'website_url' => 'http://example.test',
        'email' => 'guest@example.test',
        'content' => 'Another perspective on this photo.',
        'validated' => '1',
    ];
}

test('fromRow narrows every column to its real type', function (): void {
    $comment = Comment::fromRow(fullCommentRow());

    expect($comment->id)->toBe(2)
        ->and($comment->author)->toBe('regular_user')
        ->and($comment->authorId)->toBe(3)
        ->and($comment->userEmail)->toBe('regular@example.test')
        ->and($comment->date)->toBe('2026-08-01 00:00:00')
        ->and($comment->imageId)->toBe(2)
        ->and($comment->websiteUrl)->toBe('http://example.test')
        ->and($comment->email)->toBe('guest@example.test')
        ->and($comment->content)->toBe('Another perspective on this photo.')
        ->and($comment->validated)->toBeTrue();
});

test('fromRow defaults every nullable column to null when absent, and validated to false', function (): void {
    $row = fullCommentRow();
    foreach (['author', 'author_id', 'user_email', 'date', 'website_url', 'email', 'content', 'validated'] as $key) {
        $row[$key] = null;
    }

    $comment = Comment::fromRow($row);

    expect($comment->author)->toBeNull()
        ->and($comment->authorId)->toBeNull()
        ->and($comment->userEmail)->toBeNull()
        ->and($comment->date)->toBeNull()
        ->and($comment->websiteUrl)->toBeNull()
        ->and($comment->email)->toBeNull()
        ->and($comment->content)->toBeNull()
        ->and($comment->validated)->toBeFalse();
    // The NOT NULL columns (id/image_id) fall back to their type's zero
    // value instead, matching every other narrowing helper in this codebase
    // (is_numeric(...) ? (int) ... : 0, etc.) -- never actually null for a
    // real fetched row, since these are NOT NULL DB columns; this only
    // guards a malformed/partial row.
});

test('toArray round-trips the exact same DB column shape fromRow narrowed', function (): void {
    $roundTripped = Comment::fromRow(fullCommentRow())->toArray();

    expect($roundTripped)->toBe([
        'id' => 2,
        'author' => 'regular_user',
        'author_id' => 3,
        'user_email' => 'regular@example.test',
        'date' => '2026-08-01 00:00:00',
        'image_id' => 2,
        'website_url' => 'http://example.test',
        'email' => 'guest@example.test',
        'content' => 'Another perspective on this photo.',
        'validated' => true,
    ]);
});
