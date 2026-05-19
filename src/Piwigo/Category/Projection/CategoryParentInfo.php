<?php

declare(strict_types=1);

namespace Piwigo\Category\Projection;

use Piwigo\Common\ValueObject\CategoryId;

/**
 * `(id, id_uppercat, uppercats)` projection used by the uppercats-rebuild
 * walk in `CategoryAdminService::updateUppercats`. Holds only what the
 * algorithm needs to climb the parent chain and rewrite the joined string.
 */
final readonly class CategoryParentInfo
{
    public function __construct(
        public CategoryId $id,
        public ?CategoryId $idUppercat,
        public string $uppercats,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        $idRaw        = $row['id'] ?? null;
        $uppercatsRaw = $row['uppercats'] ?? null;
        if (!is_numeric($idRaw)) {
            throw new \InvalidArgumentException('CategoryParentInfo row is missing required `id` field');
        }
        return new self(
            id:         CategoryId::from((int) $idRaw),
            idUppercat: CategoryId::tryFrom($row['id_uppercat'] ?? null),
            uppercats:  is_string($uppercatsRaw) ? $uppercatsRaw : '',
        );
    }
}
