<?php

declare(strict_types=1);

use Piwigo\Comment\Projection\Comment;
use Piwigo\Common\ValueObject\CommentId;
use Piwigo\Common\ValueObject\ImageId;

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

test('fromRow accepts an already-hydrated CommentId instance for id, not just a raw scalar', function (): void {
    // `id` is `com.id`, a custom-Typed CommentId under DQL array
    // hydration (fromRow()'s own "gotcha #4" comment) -- DQL can hand
    // back the VO directly rather than a raw scalar. CommentId::tryFrom()
    // itself can't accept a CommentId instance (only int|numeric-string,
    // falling through to null for anything else), so this branch must
    // short-circuit before ever reaching tryFrom().
    $row = fullCommentRow();
    $row['id'] = CommentId::from(7);

    expect(Comment::fromRow($row)->id)->toEqual(CommentId::from(7));
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

test('fromRow throws with the real debug type of a non-null but invalid id', function (): void {
    // The test above sets id to null itself, so `$row['id'] ?? null`
    // resolves to null whether or not that coalesce actually reads
    // $row['id'] -- can't tell it apart from a mutated bare `null`. A
    // non-null-but-still-invalid id (CommentId::tryFrom() rejects any
    // non-positive-integer-string) forces the exception message's own
    // get_debug_type($row['id'] ?? null) call to reflect the real value.
    $row = fullCommentRow();
    $row['id'] = 'not-a-number';

    expect(fn () => Comment::fromRow($row))
        ->toThrow(InvalidArgumentException::class, 'Expected a positive comment id, got string');
});

test('fromRow defaults imageId to 0 when image_id is missing', function (): void {
    // The "defaults every nullable column..." test above deliberately
    // excludes image_id from the columns it nulls (its own docblock
    // explains why: a NOT NULL DB column, never actually null for a real
    // fetched row) -- meaning no existing test ever actually exercises
    // this fallback, only describes it.
    $row = fullCommentRow();
    $row['image_id'] = null;

    expect(Comment::fromRow($row)->imageId)->toBe(0);
});

test('fromRow accepts an already-hydrated ImageId instance for image_id, not just a raw scalar', function (): void {
    // Same DQL-array-hydration acceptance as id's own instance branch
    // above, applied to image_id: is_numeric() on an ImageId object is
    // false, so a mutant that never takes the instanceof branch would
    // fall through to the is_numeric()/0-default path and silently lose
    // the real value.
    $row = fullCommentRow();
    $row['image_id'] = ImageId::from(9);

    expect(Comment::fromRow($row)->imageId)->toBe(9);
});

test('fromRow casts a numeric-but-falsy-as-int validated string correctly via the int cast', function (): void {
    // '0.0' is_numeric() but (bool) '0.0' directly is true (only '' and
    // '0' are falsy strings in PHP) -- the (int) cast is what actually
    // makes this evaluate to false, matching the DB's own int(0)/int(1)
    // validated column shape. fullCommentRow()'s own '1'/existing null
    // case can't tell the (int) cast apart from a bare (bool) cast.
    $row = fullCommentRow();
    $row['validated'] = '0.0';

    expect(Comment::fromRow($row)->validated)->toBeFalse();
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
