<?php

declare(strict_types=1);

namespace Piwigo\Category\Projection;

use Piwigo\Common\ValueObject\CategoryId;

/**
 * Per-category snapshot used by the global-rank recomputation
 * (`CategoryAdminService::updateGlobalRank`). Carries (id, id_uppercat,
 * uppercats, rank, global_rank) — the minimum needed to derive new ranks
 * without re-fetching the whole row.
 */
final readonly class RankUpdateRow
{
    public function __construct(
        public CategoryId $id,
        public ?CategoryId $idUppercat,
        public string $uppercats,
        public ?int $rank,
        public ?string $globalRank,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        $idRaw         = $row['id'] ?? null;
        $uppercatsRaw  = $row['uppercats'] ?? null;
        $rankRaw       = $row['rank'] ?? null;
        $globalRankRaw = $row['global_rank'] ?? null;
        if (!is_numeric($idRaw)) {
            throw new \InvalidArgumentException('RankUpdateRow is missing required `id` field');
        }
        return new self(
            id:         CategoryId::from((int) $idRaw),
            idUppercat: CategoryId::tryFrom($row['id_uppercat'] ?? null),
            uppercats:  is_string($uppercatsRaw) ? $uppercatsRaw : '',
            rank:       is_numeric($rankRaw) ? (int) $rankRaw : null,
            globalRank: is_string($globalRankRaw) ? $globalRankRaw : null,
        );
    }
}
