<?php

declare(strict_types=1);

namespace Piwigo\Permalink\Projection;

use Piwigo\Permalink\OldPermalinkEntity;

/**
 * Typed row shape for `piwigo_old_permalinks` (P17-23 Stage 1b, Permalink
 * domain -- `docs/PLAN.md`'s own "7 Entity types, 73 projection
 * shapes" reference), built from {@see \Piwigo\Permalink\OldPermalinkEntity}.
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
     * Further SQL-modernization audit, Item 16B: replaces the former
     * raw-row `fromRow()` now that {@see \Piwigo\Permalink\
     * PermalinkRepository}'s own one real caller
     * (`findAllOrderedBy()`) went DQL -- `OldPermalinkEntity`'s own
     * properties are already typed, so no defensive casting is needed
     * the way `fromRow()`'s own untyped raw-array input required.
     */
    public static function fromEntity(OldPermalinkEntity $entity): self
    {
        return new self(
            catId: $entity->catId,
            permalink: $entity->permalink,
            dateDeleted: $entity->dateDeleted,
            lastHit: $entity->lastHit,
            hit: $entity->hit,
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
