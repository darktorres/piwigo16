<?php

declare(strict_types=1);

namespace Piwigo\Image\Entity;

use Piwigo\Common\ValueObject\RelPath;

/**
 * Narrow projection of an `images` row: path + representative_ext only
 * (no id). Used by bulk-delete-derivatives flows that iterate file paths
 * without needing image identity.
 */
final readonly class PathRepresentative
{
    public function __construct(
        public RelPath $path,
        public ?string $representativeExt,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        $pathRaw = $row['path'] ?? null;
        if (!is_string($pathRaw) || $pathRaw === '') {
            throw new \InvalidArgumentException('PathRepresentative row is missing required `path` field');
        }
        return new self(
            RelPath::from($pathRaw),
            is_string($row['representative_ext'] ?? null) ? $row['representative_ext'] : null,
        );
    }
}
