<?php

declare(strict_types=1);

namespace Piwigo\Comment;

/** MIN/MAX date pair returned by CommentRepository::findCommentDateRange(). */
final readonly class CommentDateRange
{
    public function __construct(
        public ?string $startedAt,
        public ?string $endedAt,
    ) {
    }
}
