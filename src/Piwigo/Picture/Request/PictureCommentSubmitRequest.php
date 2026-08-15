<?php

declare(strict_types=1);

namespace Piwigo\Picture\Request;

/**
 * Validated `$_GET`/`$_POST` shape for PictureCommentRenderer::render().
 *
 * `contentPresent` stays separate from `content`: the original gates
 * both the submit branch and its own "ugly spammer" reject branch on
 * mere presence of `$_POST['content']` (`isset(...)`), independent of
 * whether the value is actually a string.
 *
 * `author`/`content`/`websiteUrl`/`email` are each read twice by the
 * original at 2 different points -- once (trimmed, emptiness-collapsed
 * to `''`) to build the comment insert array, and again (untrimmed,
 * `htmlspecialchars(...)`'d) to redisplay the submitted
 * values after a 'reject' outcome -- both call sites only ever care
 * about `is_string(...)`, so one narrowed field each covers both reads.
 */
final readonly class PictureCommentSubmitRequest
{
    private function __construct(
        public bool $contentPresent,
        public ?string $author,
        public ?string $content,
        public ?string $websiteUrl,
        public ?string $email,
        public ?string $key,
        public ?string $commentsOrderRaw,
    ) {}

    public static function fromGlobals(): self
    {
        return self::fromArrays($_GET, $_POST);
    }

    /**
     * @param array<int|string, mixed> $get
     * @param array<int|string, mixed> $post
     */
    public static function fromArrays(array $get, array $post): self
    {
        $author_raw = $post['author'] ?? null;
        $content_raw = $post['content'] ?? null;
        $website_url_raw = $post['website_url'] ?? null;
        $email_raw = $post['email'] ?? null;
        $key_raw = $post['key'] ?? null;
        $comments_order_raw = $get['comments_order'] ?? null;

        return new self(
            isset($post['content']),
            is_string($author_raw) ? $author_raw : null,
            is_string($content_raw) ? $content_raw : null,
            is_string($website_url_raw) ? $website_url_raw : null,
            is_string($email_raw) ? $email_raw : null,
            is_string($key_raw) ? $key_raw : null,
            is_string($comments_order_raw) ? $comments_order_raw : null,
        );
    }
}
