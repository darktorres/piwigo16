<?php

declare(strict_types=1);

namespace Piwigo\Category\Projection;

/** (id, name, rank, status, visible, uppercats, lastmodified) row from CategoryRepository::findAllForAdminTreeOverview(). */
final readonly class CategoryListingRow
{
    public function __construct(
        public int $id,
        public string $name,
        public ?int $rank,
        public string $status,
        public bool $visible,
        public string $uppercats,
        public string $lastmodified,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        $idRaw      = $row['id'] ?? null;
        $rankRaw    = $row['rank'] ?? null;
        $visibleRaw = $row['visible'] ?? null;
        if (!is_numeric($idRaw)) {
            throw new \InvalidArgumentException('CategoryListingRow: missing `id`');
        }
        return new self(
            id:           (int) $idRaw,
            name:         is_string($row['name'] ?? null) ? $row['name'] : '',
            rank:         is_numeric($rankRaw) ? (int) $rankRaw : null,
            status:       is_string($row['status'] ?? null) ? $row['status'] : 'public',
            visible:      is_bool($visibleRaw) ? $visibleRaw : (is_numeric($visibleRaw) ? (int) $visibleRaw !== 0 : true),
            uppercats:    is_string($row['uppercats'] ?? null) ? $row['uppercats'] : '',
            lastmodified: is_string($row['lastmodified'] ?? null) ? $row['lastmodified'] : '',
        );
    }
}
