<?php

declare(strict_types=1);

namespace Piwigo\Category\Projection;

use Piwigo\Common\ValueObject\CategoryId;

/**
 * `(id, uppercats, site_id)` projection for physical categories — used by
 * `CategoryAdminService::getFulldirs` to compose the on-disk path from the
 * ancestor chain plus the host site's galleries URL.
 */
final readonly class CategoryUppercatsSite
{
    public function __construct(
        public CategoryId $id,
        public string $uppercats,
        public ?int $siteId,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        $idRaw        = $row['id'] ?? null;
        $uppercatsRaw = $row['uppercats'] ?? null;
        $siteIdRaw    = $row['site_id'] ?? null;
        if (!is_numeric($idRaw)) {
            throw new \InvalidArgumentException('CategoryUppercatsSite row is missing required `id` field');
        }
        return new self(
            id:        CategoryId::from((int) $idRaw),
            uppercats: is_string($uppercatsRaw) ? $uppercatsRaw : '',
            siteId:    is_numeric($siteIdRaw) ? (int) $siteIdRaw : null,
        );
    }
}
