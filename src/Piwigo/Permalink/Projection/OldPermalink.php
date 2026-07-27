<?php

declare(strict_types=1);

namespace Piwigo\Permalink\Projection;

/**
 * Typed row shape for `piwigo_old_permalinks` (P17-23 Stage 1b, Permalink
 * domain -- `docs/PLAN.md`'s own "7 Entity types, 73 projection
 * shapes" reference). `fromRow()` centralises the narrowing
 * {@see \Piwigo\Controller\Admin\PermalinksSubController}'s own caller used
 * to do inline, same shape as {@see \Piwigo\Category\Projection\Category}.
 *
 * `dateDeleted` stays `?string` -- Permalink domain Stage 1a already
 * dropped its 1970-01-01 sentinel default (every real write already sets
 * it explicitly).
 */
final readonly class OldPermalink
{
    public function __construct(
        public int $catId,
        public string $permalink,
        public ?string $dateDeleted,
        public ?string $lastHit,
        public int $hit,
    ) {}

    /**
     * @param array<string, mixed> $row a `SELECT *` (or equivalent) row from `piwigo_old_permalinks`
     */
    public static function fromRow(array $row): self
    {
        return new self(
            catId: is_numeric($row['cat_id'] ?? null) ? (int) $row['cat_id'] : 0,
            permalink: is_string($row['permalink'] ?? null) ? $row['permalink'] : '',
            dateDeleted: is_string($row['date_deleted'] ?? null) ? $row['date_deleted'] : null,
            lastHit: is_string($row['last_hit'] ?? null) ? $row['last_hit'] : null,
            hit: is_numeric($row['hit'] ?? null) ? (int) $row['hit'] : 0,
        );
    }

    /**
     * @return array{cat_id: int, permalink: string, date_deleted: ?string, last_hit: ?string, hit: int}
     */
    public function toArray(): array
    {
        return [
            'cat_id' => $this->catId,
            'permalink' => $this->permalink,
            'date_deleted' => $this->dateDeleted,
            'last_hit' => $this->lastHit,
            'hit' => $this->hit,
        ];
    }
}
