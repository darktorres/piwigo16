<?php

declare(strict_types=1);

namespace Piwigo\Tag\Projection;

use Piwigo\Common\ValueObject\TagId;

/**
 * Narrow `(id, name)` projection used by admin pages that don't need the
 * url_name / lastmodified columns (orphan listing, image-edit tag picker).
 */
final readonly class TagBrief
{
    public function __construct(
        public TagId $id,
        public string $name,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        $idRaw   = $row['id'] ?? null;
        $nameRaw = $row['name'] ?? null;
        if (!is_numeric($idRaw)) {
            throw new \InvalidArgumentException('TagBrief row is missing required `id` field');
        }
        return new self(
            id:   TagId::from((int) $idRaw),
            name: is_string($nameRaw) ? $nameRaw : '',
        );
    }

    /** @return array<string, mixed> */
    public function toRow(): array
    {
        return ['id' => $this->id->value, 'name' => $this->name];
    }
}
