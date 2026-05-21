<?php

declare(strict_types=1);

namespace Piwigo\Comment;

/** Fields allowed to change when a user edits an existing comment. */
final readonly class CommentUpdateData
{
    public function __construct(
        public string $content,
        public bool $validated,
        public string|null $websiteUrl = null,
    ) {
    }
}
