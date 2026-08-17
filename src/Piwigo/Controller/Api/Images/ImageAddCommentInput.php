<?php

declare(strict_types=1);

namespace Piwigo\Controller\Api\Images;

/**
 * `POST /api/v1/images/{id}/comments` body DTO -- mirrors
 * `Ws\Images\AddCommentParams`'s own `author`/`content`/`key` fields.
 * `author` defaults to 'guest' when absent, matching the WS
 * registration's own dynamic default (accepting either a plain string
 * or, for a real signed-in caller, the current username).
 */
final readonly class ImageAddCommentInput
{
    public function __construct(
        public string $author,
        public string $content,
        public string $key,
    ) {}

    /**
     * @param array<int|string, mixed> $raw
     */
    public static function fromArray(array $raw): self
    {
        $author = $raw['author'] ?? null;
        $content = $raw['content'] ?? null;
        $key = $raw['key'] ?? null;

        return new self(
            author: is_string($author) ? $author : 'guest',
            content: is_string($content) ? $content : '',
            key: is_string($key) ? $key : '',
        );
    }
}
