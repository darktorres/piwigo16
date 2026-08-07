<?php

declare(strict_types=1);

namespace Piwigo\Permalink\Projection;

use Piwigo\Common\ValueObject\CategoryId;
use Piwigo\Common\ValueObject\Permalink;
use Piwigo\Permalink\OldPermalinkEntity;

/**
 * Typed row shape for `piwigo_old_permalinks` (P17-23 Stage 1b, Permalink
 * domain -- `docs/PLAN.md`'s own "7 Entity types, 73 projection
 * shapes" reference), built from {@see \Piwigo\Permalink\OldPermalinkEntity}.
 *
 * `dateDeleted` stays `?string` here (this Projection's own convention)
 * -- Permalink domain Stage 1a already dropped its 1970-01-01 sentinel
 * default (every real write already sets it explicitly).
 * `OldPermalinkEntity::$dateDeleted` itself is `SqlDateTime`-typed
 * (Phase 5) -- `fromEntity()` unwraps `->value`.
 */
final readonly class OldPermalink
{
    public function __construct(
        public CategoryId $catId,
        public Permalink $permalink,
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
     *
     * `catId`/`permalink` keep the entity's own `CategoryId`/`Permalink`
     * VOs (Phase 4) -- `toArray()` is this projection's real unwrap
     * boundary now.
     */
    public static function fromEntity(OldPermalinkEntity $entity): self
    {
        return new self(
            catId: $entity->catId,
            permalink: $entity->permalink,
            dateDeleted: $entity->dateDeleted?->value,
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
            'cat_id' => $this->catId->value,
            'permalink' => $this->permalink->value,
            'date_deleted' => $this->dateDeleted,
            'last_hit' => $this->lastHit,
            'hit' => $this->hit,
        ];
    }
}
