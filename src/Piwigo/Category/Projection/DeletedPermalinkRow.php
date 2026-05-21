<?php

declare(strict_types=1);

namespace Piwigo\Category\Projection;

/** (cat_id, permalink, date_deleted, last_hit, hit) row from CategoryRepository::findOldPermalinks(). */
final readonly class DeletedPermalinkRow
{
    public function __construct(
        public int $catId,
        public string $permalink,
        public string $dateDeleted,
        public ?string $lastHit,
        public int $hit,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        $catIdRaw = $row['cat_id'] ?? null;
        if (!is_numeric($catIdRaw)) {
            throw new \InvalidArgumentException('DeletedPermalinkRow: missing `cat_id`');
        }
        return new self(
            catId:       (int) $catIdRaw,
            permalink:   is_string($row['permalink'] ?? null) ? $row['permalink'] : '',
            dateDeleted: is_string($row['date_deleted'] ?? null) ? $row['date_deleted'] : '',
            lastHit:     is_string($row['last_hit'] ?? null) ? $row['last_hit'] : null,
            hit:         is_numeric($row['hit'] ?? null) ? (int) $row['hit'] : 0,
        );
    }
}
