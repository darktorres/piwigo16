<?php

declare(strict_types=1);

namespace Piwigo\Controller\Projection;

use Piwigo\Core\View;
use Piwigo\Image\DerivativeParams;
use Piwigo\Template\Latte\Attribute\Template;

/**
 * `mainpage_categories.latte`'s own typed view -- rendered by
 * {@see \Piwigo\Controller\GalleryController::__invoke()} from
 * {@see \Piwigo\Category\CategoryCatsRenderer::render()}'s own {@see
 * \Piwigo\Category\Projection\CategoryCatsResult}, same L2aCoreDomain
 * split as {@see ThumbnailsView}/`CategoryDefaultRenderer`.
 */
#[Template('mainpage_categories.latte')]
final readonly class CategoryCatsView implements View
{
    /**
     * @param array<int|string, mixed> $categoryThumbnails
     */
    public function __construct(
        public int $maxRequests,
        public array $categoryThumbnails,
        public DerivativeParams $derivativeParams,
    ) {}
}
