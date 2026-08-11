<?php

declare(strict_types=1);

use Piwigo\Comment\Projection\Comment;
use Piwigo\Common\ValueObject\CommentId;
use Piwigo\Common\ValueObject\Email;
use Piwigo\Common\ValueObject\ImageId;
use Piwigo\Common\ValueObject\SqlDateTime;

/**
 * @return array<string, mixed>
 */
function fullCommentRow(): array
{
    return [
        'id' => '2',
        'author' => 'regular_user',
        'author_id' => '3',
        // A real row's `user_email` (u.mailAddress, the joined UserEntity
        // column) is an Email instance -- DQL array hydration applies the
        // custom Type. `email` below is com.email, CommentEntity's own
        // plain-string column, unaffected.
        'user_email' => Email::from('regular@example.test'),
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

    expect($comment->id)
        ->toEqual(CommentId::from(2))
        ->and($comment->author)
        ->toBe('regular_user')
        ->and($comment->authorId)
        ->toBe(3)
        ->and($comment->userEmail)
        ->toBe('regular@example.test')
        ->and($comment->date)
        ->toBe('2026-08-01 00:00:00')
        ->and($comment->imageId)
        ->toEqual(ImageId::from(2))
        ->and($comment->websiteUrl)
        ->toBe('http://example.test')
        ->and($comment->email)
        ->toBe('guest@example.test')
        ->and($comment->content)
        ->toBe('Another perspective on this photo.')
        ->and($comment->validated)
        ->toBeTrue();
});

test('fromRow defaults every nullable column to null when absent, and validated to false', function (): void {
    $row = fullCommentRow();
    foreach (['author', 'author_id', 'user_email', 'date', 'website_url', 'email', 'content', 'validated'] as $key) {
        $row[$key] = null;
    }

    $comment = Comment::fromRow($row);

    expect($comment->author)
        ->toBeNull()
        ->and($comment->authorId)
        ->toBeNull()
        ->and($comment->userEmail)
        ->toBeNull()
        ->and($comment->date)
        ->toBeNull()
        ->and($comment->websiteUrl)
        ->toBeNull()
        ->and($comment->email)
        ->toBeNull()
        ->and($comment->content)
        ->toBeNull()
        ->and($comment->validated)
        ->toBeFalse();
    // image_id is deliberately excluded above -- it's CommentId's sibling
    // NOT NULL FK column now, so it can't silently default to null/zero
    // either; see the dedicated "throws when image_id is invalid" test
    // below, matching id's own pattern.
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

    expect(fn (): Comment => Comment::fromRow($row))
        ->toThrow(InvalidArgumentException::class, 'Expected a positive comment id, got string');
});

test('fromRow throws when image_id is missing', function (): void {
    // Same behavior change as id above: image_id is ImageId now, and
    // ImageId::from(0) always throws -- the previous "silently default
    // image_id to 0" contract is structurally impossible to keep once the
    // field is really typed. com.image_id is the table's own NOT NULL FK
    // column, so a missing value can't actually occur for a real fetched
    // row.
    $row = fullCommentRow();
    $row['image_id'] = null;

    Comment::fromRow($row);
})->throws(InvalidArgumentException::class);

test('fromRow throws with the real debug type of a non-null but invalid image_id', function (): void {
    $row = fullCommentRow();
    $row['image_id'] = 'not-a-number';

    expect(fn (): Comment => Comment::fromRow($row))
        ->toThrow(InvalidArgumentException::class, 'Expected a positive image id, got string');
});

test('fromRow keeps an already-hydrated ImageId instance as-is', function (): void {
    // Covers the getArrayResult() Gotcha #1 shape: a real Doctrine array
    // hydration would already have converted image_id via ImageIdType,
    // not left it as a raw string.
    $row = fullCommentRow();
    $row['image_id'] = ImageId::from(9);

    expect(Comment::fromRow($row)->imageId)->toEqual(ImageId::from(9));
});

test('fromRow keeps an already-hydrated SqlDateTime instance for date as-is', function (): void {
    // Covers the getArrayResult() Gotcha #1 shape: a real Doctrine array
    // hydration would already have converted date via SqlDateTimeType.
    $row = fullCommentRow();
    $row['date'] = SqlDateTime::from('2026-08-02 12:00:00');

    expect(Comment::fromRow($row)->date)->toBe('2026-08-02 12:00:00');
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

    expect($roundTripped)
        ->toBe([
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
