<?php

declare(strict_types=1);

namespace Piwigo\Comment\Projection;

/**
 * {@see \Piwigo\Comment\CommentService::insertComment()}'s own working
 * state -- `$author`/`$content`/`$imageId`/`$websiteUrl`/`$email` are
 * caller-supplied; `$ip`/`$agent`/`$authorId`/`$id` are resolved by
 * `insertComment()` itself (server-side, never caller-supplied), hence
 * their `null`/`''` defaults here. Deliberately mutable (not `final
 * readonly` like most row Projections in this codebase) -- object
 * identity, not a by-ref array trick, is what lets `insertComment()`
 * mutate the caller's own instance across its many conditional
 * branches, same rationale as {@see \Piwigo\Category\Projection\
 * ComputedCategoryRow}.
 */
final class CommentInsertData
{
    public function __construct(
        public string $author,
        public string $content,
        public int $imageId,
        public ?string $websiteUrl = null,
        public ?string $email = null,
        public string $ip = '',
        public string $agent = '',
        public ?int $authorId = null,
        public ?int $id = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $result = [
            'author' => $this->author,
            'content' => $this->content,
            'image_id' => $this->imageId,
            'ip' => $this->ip,
            'agent' => $this->agent,
        ];

        if ($this->websiteUrl !== null) {
            $result['website_url'] = $this->websiteUrl;
        }

        if ($this->email !== null) {
            $result['email'] = $this->email;
        }

        if ($this->authorId !== null) {
            $result['author_id'] = $this->authorId;
        }

        if ($this->id !== null) {
            $result['id'] = $this->id;
        }

        return $result;
    }
}
