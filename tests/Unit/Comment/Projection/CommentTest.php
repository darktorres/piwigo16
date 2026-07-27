<?php

declare(strict_types=1);

use Piwigo\Comment\Projection\Comment;
use Piwigo\Common\ValueObject\CommentId;

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

    expect($comment->id)->toEqual(CommentId::from(2))
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
    // image_id (still plain int) falls back to its type's zero value,
    // matching every other narrowing helper in this codebase -- never
    // actually null for a real fetched row, since it's a NOT NULL DB
    // column; this only guards a malformed/partial row. id is CommentId
    // now and can't silently default to zero -- see the dedicated
    // "throws when id is invalid" test below.
});

test('fromRow throws when id is missing', function (): void {
    // Behavior change, deliberate: id is a CommentId now, and
    // CommentId::from(0) always throws (0 was never a valid id) -- the
    // previous "silently default id to 0" contract is structurally
    // impossible to keep once the field is really typed. Unlike Group's
    // own fromRow(), this one has exactly one real, production caller
    // (CommentRepository::findForImage()), whose `com.id` column is
    // always the table's own NOT NULL primary key -- a missing id can't
    // actually occur for a real fetched row, so a loud failure here is
    // safe and correct, not a behavior change any real caller could hit.
    $row = fullCommentRow();
    $row['id'] = null;

    Comment::fromRow($row);
})->throws(InvalidArgumentException::class);

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
