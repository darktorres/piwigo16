<?php

declare(strict_types=1);

namespace Piwigo\Comment\Projection;

/**
 * {@see \Piwigo\Comment\CommentRepository::findAllWithConditions()}'s own
 * row shape -- `Controller\CommentsController`'s admin/public comment
 * listing.
 *
 * `commentId`/`imageId`/`categoryId`/`authorId` stay `int|string`, not a VO,
 * and `validated` stays `bool|int` -- this is a raw-DBAL query (not DQL),
 * so every numeric column comes back as whichever native PHP type the
 * active driver hands back (a real int under mysqli's own
 * MYSQLI_OPT_INT_AND_FLOAT_NATIVE, a numeric string under some pgsql
 * paths), never a VO -- same driver-dependent-typing rationale
 * {@see \Piwigo\Db\SqlDialect::getBoolean()}'s own docblock documents for
 * `$validated`. Narrowing happens in
 * {@see \Piwigo\Comment\CommentRepository::findAllWithConditions()} itself
 * (a row with a comment_id/image_id/category_id/validated outside these
 * types is skipped there, not defaulted here), so this constructor takes
 * already-validated values.
 */
final readonly class CommentListRow
{
    public function __construct(
        public int|string $commentId,
        public int|string $imageId,
        public int|string $categoryId,
        public ?string $author,
        public int|string|null $authorId,
        public ?string $userEmail,
        public ?string $email,
        public ?string $date,
        public ?string $websiteUrl,
        public ?string $content,
        public bool|int $validated,
    ) {}
}
