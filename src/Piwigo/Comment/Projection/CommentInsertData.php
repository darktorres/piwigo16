<?php

declare(strict_types=1);

namespace Piwigo\Comment\Projection;

use Piwigo\Common\ValueObject\UserId;

/**
 * {@see \Piwigo\Comment\CommentService::insertComment()}'s own working
 * state -- `$author`/`$content`/`$imageId`/`$websiteUrl`/`$email` are
 * caller-supplied; `$ip`/`$agent`/`$authorId`/`$id` are resolved by
 * `insertComment()` itself (server-side, never caller-supplied), hence
 * their `null`/`''` defaults here. Deliberately mutable (not `final
 * readonly` like most row Projections in this codebase) -- object
 * identity, not a by-ref array trick, is what lets `insertComment()`
 * mutate the caller's own instance across its many conditional
 * branches, same rationale as {@see \Piwigo\Admin\Integrity\Projection\
 * AnomalyRow}/{@see \Piwigo\Controller\Projection\ImageOrderOption}.
 *
 * `$authorId` is `?UserId` (P51-J), unlike
 * {@see \Piwigo\Comment\Projection\Comment}'s own identically-named
 * field, which deliberately stays `?int` -- that one is extracted via
 * DQL's `IDENTITY()`, which never hydrates a VO; this one is set by
 * `insertComment()` itself from a real, already-known-valid source (the
 * current session's own `CurrentUser::get()->id`, or the configured
 * guest id), never a raw external boundary read.
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
        public ?UserId $authorId = null,
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
            // Unwrapped: this array feeds UserCommentCheck's own
            // array<string, mixed> event payload, a legacy filter meant
            // for third-party plugin consumption -- kept a plain scalar
            // there, same as every other Latte/JSON/plugin-event
            // boundary in this codebase.
            $result['author_id'] = $this->authorId->value;
        }

        if ($this->id !== null) {
            $result['id'] = $this->id;
        }

        return $result;
    }
}
