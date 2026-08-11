<?php

declare(strict_types=1);

namespace Piwigo\Category\Projection;

/**
 * {@see \Piwigo\Category\CategoryRepository::findPhotoCountAndDateRange()}'s
 * own return shape -- {@see \Piwigo\Admin\CatModifyPageRenderer}'s own
 * "this album contains N photos, added between X and Y" summary, its real
 * (and only) consumer.
 */
final readonly class PhotoCountDateRange
{
    public function __construct(
        public int $count,
        public ?string $minDate,
        public ?string $maxDate,
    ) {}
}
