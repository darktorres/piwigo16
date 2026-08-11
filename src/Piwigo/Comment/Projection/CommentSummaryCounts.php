<?php

declare(strict_types=1);

namespace Piwigo\Comment\Projection;

/**
 * {@see \Piwigo\Comment\CommentRepository::findSummaryCounts()}'s own
 * total/validated/pending row shape -- `Ws\Comments::getList()`'s own
 * summary block.
 */
final readonly class CommentSummaryCounts
{
    public function __construct(
        public int $allComments,
        public int $validated,
        public int $pending,
    ) {}
}
