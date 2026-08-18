<?php

declare(strict_types=1);

namespace Piwigo\Comment\Projection;

/**
 * {@see \Piwigo\Comment\CommentRepository::findAllWithConditions()}'s own
 * row shape -- `Controller\CommentsController`'s admin/public comment
 * listing.
 *
 * `commentId`/`imageId`/`categoryId`/`authorId` stay `int|string`, not a
 * VO, and `validated` stays `bool|int`. `findAllWithConditions()` is
 * DQL-backed: `commentId`/`imageId` are unwrapped from their real
 * `CommentId`/`ImageId` VO instances back to plain scalars before this
 * class ever sees them (see that method's own
 * `unwrapCommentListRowVoFields()`); `categoryId`/`authorId` are
 * extracted via `IDENTITY()`, which never hydrates a VO in the first
 * place; `validated` stays `bool|int` because
 * {@see \Piwigo\Db\SqlDialect::getBoolean()} (the one real consumer) is
 * this codebase's established "genuinely arbitrary boolean-ish input"
 * boundary, not because the column's own driver-dependent typing
 * requires it. Narrowing happens in
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
