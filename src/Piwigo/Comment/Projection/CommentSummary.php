<?php

declare(strict_types=1);

namespace Piwigo\Comment\Projection;

use InvalidArgumentException;
use Piwigo\Common\ValueObject\CommentId;
use Piwigo\Common\ValueObject\SqlDateTime;

/**
 * Typed row shape for {@see \Piwigo\Ws\Images::getInfo()}'s own
 * "related comments" block (`pwg.images.getInfo`'s `SELECT id, date,
 * author, content FROM comments ...`).
 *
 * `date` stays `?string`, not `SqlDateTime`, at the DTO level -- this
 * Projection's own convention (matches `Comment\Projection\Comment::$date`).
 * `CommentEntity::$date` itself is `SqlDateTime`-typed --
 * `findSummariesForImage()` (this class's one real caller, which builds
 * this DTO directly rather than through `fromRow()`) already unwraps it
 * the same way `fromRow()` does here, for the same `getArrayResult()`
 * Gotcha #1 reason.
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
     * @param array<string, mixed> $row a `SELECT id, date, author, content` row from `comments`
     */
    public static function fromRow(array $row): self
    {
        $id = CommentId::tryFrom($row['id'] ?? null);
        if ($id === null) {
            throw new InvalidArgumentException(sprintf('Expected a positive comment id, got %s', get_debug_type($row['id'] ?? null)));
        }

        $dateValue = $row['date'] ?? null;

        return new self(
            id: $id,
            date: $dateValue instanceof SqlDateTime ? $dateValue->value : (is_string($dateValue) ? $dateValue : null),
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
