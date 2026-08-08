<?php

declare(strict_types=1);

namespace Piwigo\Category\Projection;

/**
 * {@see \Piwigo\Category\CategoryRepository::findSyncCandidatesForSite()}'s
 * own row shape -- {@see \Piwigo\Controller\Admin\SiteUpdateSubController}'s
 * own "which categories to update" filesystem-sync step, its real (and
 * only) consumer (through {@see \Piwigo\Category\CategoryService::
 * getSyncCandidatesForSite()}'s pass-through).
 *
 * `toArray()` exists for that consumer: it keeps its existing plain-array
 * contract, since the same array is later reused (by that controller's own
 * code, not this DTO) to also hold freshly-inserted categories with
 * additional fields no real row here carries.
 */
final readonly class CategorySyncCandidateRow
{
    public function __construct(
        public int $id,
        public string $uppercats,
        public ?string $globalRank,
        public string $status,
        public bool $visible,
    ) {}

    /**
     * @return array{id: int, uppercats: string, global_rank: ?string, status: string, visible: bool}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'uppercats' => $this->uppercats,
            'global_rank' => $this->globalRank,
            'status' => $this->status,
            'visible' => $this->visible,
        ];
    }
}
