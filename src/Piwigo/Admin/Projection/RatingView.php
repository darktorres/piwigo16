<?php

declare(strict_types=1);

namespace Piwigo\Admin\Projection;

use Piwigo\Core\View;
use Piwigo\Template\Latte\Attribute\Template;

/**
 * `rating.latte`'s own typed view, constructed by {@see
 * \Piwigo\Admin\RatingPageRenderer::render()}. `$orderByOptions` and
 * `$images` are both always included -- the template reads
 * `orderByOptions` via an unguarded `{=htmlOptions(...)}` and `images`
 * via an unguarded `{foreach}`.
 */
#[Template('rating.latte')]
final readonly class RatingView implements View
{
    /**
     * @param array{CURRENT_PAGE?: float, URL_FIRST?: string, URL_PREV?: string, URL_NEXT?: string, URL_LAST?: string, pages?: array<int, string>, NB_PAGE?: int} $navbar
     * @param list<mixed> $category
     * @param array<array-key, string> $cacheKeys
     * @param list<int> $orderByOptionsSelected
     * @param array<string, string> $userOptions
     * @param list<mixed> $userOptionsSelected
     * @param list<string> $orderByOptions
     * @param list<array<string, mixed>> $images
     */
    public function __construct(
        public array $navbar,
        public string $fAction,
        public int $display,
        public int $nbElements,
        public array $category,
        public array $cacheKeys,
        public array $orderByOptionsSelected,
        public array $userOptions,
        public array $userOptionsSelected,
        public array $orderByOptions,
        public array $images,
        public string $csrfToken,
    ) {}
}
