<?php

declare(strict_types=1);

namespace Piwigo\Category\Projection;

/** (id, uppercats, counter) row from CategoryRepository::findCommonCategoriesWithPermissions(). */
final readonly class CategoryWithCounter
{
    public function __construct(
        public int $id,
        public string $uppercats,
        public int $counter,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        $idRaw = $row['id'] ?? null;
        if (!is_numeric($idRaw)) {
            throw new \InvalidArgumentException('CategoryWithCounter: missing `id`');
        }
        return new self(
            id:        (int) $idRaw,
            uppercats: is_string($row['uppercats'] ?? null) ? $row['uppercats'] : '',
            counter:   is_numeric($row['counter'] ?? null) ? (int) $row['counter'] : 0,
        );
    }
}
