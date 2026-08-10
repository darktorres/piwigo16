<?php

declare(strict_types=1);

namespace Piwigo\Permalink\Projection;

use Piwigo\Common\ValueObject\CategoryId;
use Piwigo\Common\ValueObject\Permalink;
use Piwigo\Permalink\OldPermalinkEntity;

/**
 * Typed row shape for `old_permalinks`, built from
 * {@see \Piwigo\Permalink\OldPermalinkEntity}.
 *
 * `dateDeleted` stays `?string` here (this Projection's own convention) --
 * every real write already sets it explicitly, no 1970-01-01 sentinel
 * default. `OldPermalinkEntity::$dateDeleted` itself is `SqlDateTime`-typed
 * -- `fromEntity()` unwraps `->value`.
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
     * `OldPermalinkEntity`'s own properties are already typed, so no
     * defensive casting is needed. `catId`/`permalink` keep the entity's
     * own `CategoryId`/`Permalink` VOs -- `toArray()` is this projection's
     * real unwrap boundary.
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
