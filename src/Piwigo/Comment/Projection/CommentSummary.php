<?php

declare(strict_types=1);

namespace Piwigo\Comment\Projection;

use Piwigo\Common\ValueObject\CommentId;
use Piwigo\Common\ValueObject\MysqlDateTime;

/**
 * Narrow projection of a `comments` row used by `pwg.images.getInfo` — the
 * fields the per-image comment list ships back to the client (no joined
 * picture / user metadata).
 */
final readonly class CommentSummary
{
    public function __construct(
        public CommentId $id,
        public ?MysqlDateTime $date,
        public ?string $author,
        public ?string $content,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        $idRaw = $row['id'] ?? null;
        if (!is_numeric($idRaw)) {
            throw new \InvalidArgumentException('Comment row is missing required `id` field');
        }
        return new self(
            id:      CommentId::from((int) $idRaw),
            date:    MysqlDateTime::tryFrom($row['date'] ?? null),
            author:  is_string($row['author'] ?? null) ? $row['author'] : null,
            content: is_string($row['content'] ?? null) ? $row['content'] : null,
        );
    }
}
