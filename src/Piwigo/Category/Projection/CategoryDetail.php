<?php

declare(strict_types=1);

namespace Piwigo\Category\Projection;

use Piwigo\Common\ValueObject\CategoryId;

/**
 * `(id, name, id_uppercat, uppercats, global_rank)` projection used by
 * setCatStatus's private branch to walk the descendant tree and identify
 * the top-most categories whose parent is *not* private.
 */
final readonly class CategoryDetail
{
    public function __construct(
        public CategoryId $id,
        public string $name,
        public ?CategoryId $idUppercat,
        public string $uppercats,
        public ?string $globalRank,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        $idRaw         = $row['id'] ?? null;
        $nameRaw       = $row['name'] ?? null;
        $uppercatsRaw  = $row['uppercats'] ?? null;
        $globalRankRaw = $row['global_rank'] ?? null;
        if (!is_numeric($idRaw)) {
            throw new \InvalidArgumentException('CategoryDetail row is missing required `id` field');
        }
        return new self(
            id:         CategoryId::from((int) $idRaw),
            name:       is_string($nameRaw) ? $nameRaw : '',
            idUppercat: CategoryId::tryFrom($row['id_uppercat'] ?? null),
            uppercats:  is_string($uppercatsRaw) ? $uppercatsRaw : '',
            globalRank: is_string($globalRankRaw) ? $globalRankRaw : null,
        );
    }
}
