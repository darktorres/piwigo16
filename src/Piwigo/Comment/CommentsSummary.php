<?php

declare(strict_types=1);

namespace Piwigo\Comment;

/** Three-way comment count returned by CommentRepository::findCommentsSummary(). */
final readonly class CommentsSummary
{
    public function __construct(
        public int $allComments,
        public int $validated,
        public int $pending,
    ) {
    }

    /** @return array{all_comments: int, validated: int, pending: int} */
    public function toArray(): array
    {
        return ['all_comments' => $this->allComments, 'validated' => $this->validated, 'pending' => $this->pending];
    }
}
