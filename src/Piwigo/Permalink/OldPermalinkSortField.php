<?php

declare(strict_types=1);

namespace Piwigo\Permalink;

/**
 * Typed replacement for `Controller\Admin\PermalinksSubController::
 * parseSortVariables()`'s own bare column-name string
 * (`$sortable_by = ['cat_id', 'permalink', 'date_deleted', 'last_hit',
 * 'hit']`, confirmed via reading that method's own real call site) --
 * same "bounded token set, not genuinely open-ended admin text" shape as
 * `Image\PhotoSortField`. Always ascending -- the real caller's own
 * `$ret[] = $field` shape carries no direction, matching
 * `PermalinkRepository::findAllOrderedBy()`'s own former no-direction
 * `$qb->orderBy($orderByColumn)` call.
 */
enum OldPermalinkSortField
{
    case CatId;
    case Permalink;
    case DateDeleted;
    case LastHit;
    case Hit;

    public static function fromToken(string $token): ?self
    {
        return match ($token) {
            'cat_id' => self::CatId,
            'permalink' => self::Permalink,
            'date_deleted' => self::DateDeleted,
            'last_hit' => self::LastHit,
            'hit' => self::Hit,
            default => null,
        };
    }

    public function dqlProperty(): string
    {
        return match ($this) {
            self::CatId => 'op.catId',
            self::Permalink => 'op.permalink',
            self::DateDeleted => 'op.dateDeleted',
            self::LastHit => 'op.lastHit',
            self::Hit => 'op.hit',
        };
    }
}
