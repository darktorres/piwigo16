<?php

declare(strict_types=1);

namespace Piwigo\Category\Projection;

use Piwigo\Common\ValueObject\CategoryId;

/**
 * Narrow `(id, id_uppercat, rank)` projection returned by
 * `CategoryRepository::findIdIdUppercatRankByIds` for the
 * pwg.categories.setRank reordering logic.
 */
final readonly class CategoryRankInfo
{
    public function __construct(
        public CategoryId $id,
        public ?CategoryId $idUppercat,
        public ?int $rank,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        $idRaw   = $row['id'] ?? null;
        $rankRaw = $row['rank'] ?? null;
        if (!is_numeric($idRaw)) {
            throw new \InvalidArgumentException('CategoryRankInfo row is missing required `id` field');
        }
        return new self(
            id:         CategoryId::from((int) $idRaw),
            idUppercat: CategoryId::tryFrom($row['id_uppercat'] ?? null),
            rank:       is_numeric($rankRaw) ? (int) $rankRaw : null,
        );
    }
}
