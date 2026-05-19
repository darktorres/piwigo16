<?php

declare(strict_types=1);

namespace Piwigo\Comment\Projection;

use Piwigo\Common\ValueObject\UserId;

/**
 * Per-author comment counts shipped in the `pwg.userComments.getList`
 * filters block. NULL `authorId` represents the legacy guest fan-out
 * (comments posted without a registered account).
 */
final readonly class AuthorCount
{
    public function __construct(
        public ?string $author,
        public ?UserId $authorId,
        public int $nbAuthors,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        $authorRaw = $row['author'] ?? null;
        $nbRaw     = $row['nb_authors'] ?? null;
        return new self(
            author:    is_string($authorRaw) ? $authorRaw : null,
            authorId:  UserId::tryFrom($row['author_id'] ?? null),
            nbAuthors: is_numeric($nbRaw) ? (int) $nbRaw : 0,
        );
    }

    /** @return array<string, int|string|null> */
    public function toArray(): array
    {
        return [
            'author'     => $this->author,
            'author_id'  => $this->authorId?->value,
            'nb_authors' => $this->nbAuthors,
        ];
    }
}
