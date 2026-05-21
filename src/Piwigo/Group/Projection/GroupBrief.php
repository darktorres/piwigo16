<?php

declare(strict_types=1);

namespace Piwigo\Group\Projection;

/** (id, name, is_default) row from GroupRepository::findAllOrdered(). */
final readonly class GroupBrief
{
    public function __construct(
        public int $id,
        public string $name,
        public bool $isDefault,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        $isDefaultRaw = $row['is_default'] ?? null;
        return new self(
            id:        is_numeric($row['id'] ?? null) ? (int) $row['id'] : 0,
            name:      is_string($row['name'] ?? null) ? $row['name'] : '',
            isDefault: is_bool($isDefaultRaw) ? $isDefaultRaw : (is_numeric($isDefaultRaw) ? (int) $isDefaultRaw !== 0 : false),
        );
    }
}
