<?php

declare(strict_types=1);

namespace Piwigo\Comment;

/** Data required to insert a new user comment row. */
final readonly class NewCommentData
{
    public function __construct(
        public string $author,
        public int $authorId,
        public string $anonymousId,
        public string $content,
        public bool $validated,
        public int $imageId,
        public string|null $websiteUrl = null,
        public string|null $email = null,
    ) {
    }
}
