<?php

declare(strict_types=1);

namespace Piwigo\Comment\Projection;

use InvalidArgumentException;
use Piwigo\Common\ValueObject\CommentId;

/**
 * Typed row shape for {@see \Piwigo\Ws\PwgImages::getInfo()}'s own
 * "related comments" block (`pwg.images.getInfo`'s `SELECT id, date,
 * author, content FROM comments ...`) -- P24 gap-closure, Comment domain
 * (16.x-rewrite's own `CommentSummary`, 4 missing projections found
 * against that reference; this is the only one of the 4 with a real,
 * live, retrofittable call site in 17.x-rewrite -- see the Stage A1 plan
 * notes for why `AdminListingRow`/`AuthorCount`/`RecentCommentRow` aren't
 * built here).
 *
 * `date` stays `?string`, not `SqlDateTime` -- that VO's own domain-wide
 * propagation is Stage C, out of scope for this CommentId-focused pass,
 * same reasoning `Comment\Projection\Comment::$date` already documents.
 */
final readonly class CommentSummary
{
    public function __construct(
        public CommentId $id,
        public ?string $date,
        public ?string $author,
        public ?string $content,
    ) {}

    /**
     * @param array<string, mixed> $row a `SELECT id, date, author, content` row from `piwigo_comments`
     */
    public static function fromRow(array $row): self
    {
        $id = CommentId::tryFrom($row['id'] ?? null);
        if ($id === null) {
            throw new InvalidArgumentException(sprintf('Expected a positive comment id, got %s', get_debug_type($row['id'] ?? null)));
        }

        return new self(
            id: $id,
            date: is_string($row['date'] ?? null) ? $row['date'] : null,
            author: is_string($row['author'] ?? null) ? $row['author'] : null,
            content: is_string($row['content'] ?? null) ? $row['content'] : null,
        );
    }

    /**
     * @return array{id: int, date: ?string, author: ?string, content: ?string}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id->value,
            'date' => $this->date,
            'author' => $this->author,
            'content' => $this->content,
        ];
    }
}
