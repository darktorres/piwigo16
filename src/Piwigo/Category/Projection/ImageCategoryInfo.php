<?php

declare(strict_types=1);

namespace Piwigo\Category\Projection;

use Piwigo\Common\ValueObject\CategoryId;

/**
 * `(category_id, uppercats, dir)` projection — what the admin image-edit /
 * batch-manager pages need to show the album associations of a single
 * image (with the joined-uppercats string for the breadcrumb and the dir
 * marker that distinguishes physical from virtual albums).
 */
final readonly class ImageCategoryInfo
{
    public function __construct(
        public CategoryId $categoryId,
        public string $uppercats,
        public ?string $dir,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        $categoryIdRaw = $row['category_id'] ?? null;
        $uppercatsRaw  = $row['uppercats'] ?? null;
        $dirRaw        = $row['dir'] ?? null;
        if (!is_numeric($categoryIdRaw)) {
            throw new \InvalidArgumentException('ImageCategoryInfo row is missing required `category_id` field');
        }
        return new self(
            categoryId: CategoryId::from((int) $categoryIdRaw),
            uppercats:  is_string($uppercatsRaw) ? $uppercatsRaw : '',
            dir:        is_string($dirRaw) ? $dirRaw : null,
        );
    }
}
