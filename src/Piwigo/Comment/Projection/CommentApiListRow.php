<?php

declare(strict_types=1);

namespace Piwigo\Comment\Projection;

/**
 * {@see \Piwigo\Comment\CommentRepository::findList()}'s own row shape --
 * `Controller\Api\Comments\CommentListController`'s (`GET /api/v1/comments`)
 * paginated admin listing, joined with the commenting image and user.
 *
 * `id`/`imageId`/`authorId` stay `int|string`, same driver-dependent
 * id-like-column caveat as {@see \Piwigo\Comment\Projection\CommentListRow}
 * (a different row, `findAllWithConditions()`'s own); `validated`
 * stays `bool|int` for the same reason. `username`/`status` come from
 * LEFT JOINs (null when no matching row); `path`/`file` are
 * `ImageEntity`'s own non-nullable columns (INNER JOIN, so always
 * present); `date`/`author`/`representativeExt`/`dateAvailable`/
 * `content` are nullable on their respective entities.
 */
final readonly class CommentApiListRow
{
    public function __construct(
        public int|string $id,
        public int|string $imageId,
        public ?string $date,
        public ?string $author,
        public int|string|null $authorId,
        public ?string $username,
        public ?string $status,
        public ?string $content,
        public string $path,
        public ?string $representativeExt,
        public string $file,
        public ?string $dateAvailable,
        public bool|int $validated,
    ) {}
}
