<?php

declare(strict_types=1);

namespace Piwigo\Permission\Projection;

/**
 * {@see \Piwigo\Permission\EffectiveForbiddenCategoriesCache}'s own fixed
 * per-user result shape.
 */
final readonly class EffectivePermissionsSnapshot
{
    public function __construct(
        public string $forbiddenCategories,
        public string $imageAccessType,
        public string $imageAccessList,
        public string $nbTotalImages,
        public ?string $lastPhotoDate,
    ) {}
}
