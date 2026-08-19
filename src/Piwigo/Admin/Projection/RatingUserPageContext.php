<?php

declare(strict_types=1);

namespace Piwigo\Admin\Projection;

use Override;
use Piwigo\Core\TemplatePageContext;
use Piwigo\Rate\Projection\ImageThumbUrl;

/**
 * The template variable set assigned by
 * {@see \Piwigo\Admin\RatingUserPageRenderer::render()}. `$ratings` stays
 * a loose row shape -- the same genuinely dynamic,
 * incrementally-accumulated-across-3-mutation-points shape documented on
 * this file's own `avgCompare()`/`countCompare()`/etc. comparators, not a
 * fixed structural shape worth minting its own DTO for here. `$imageUrls`
 * is a clean, independently-built `{tn, page}` pair per image id -- see
 * {@see \Piwigo\Rate\Projection\ImageThumbUrl}. `$orderByOptions` is
 * always included -- `rating_user.latte` reads `order_by_options` via an
 * unguarded `{html_options}`, matching the original code's own
 * unconditional loop.
 */
final readonly class RatingUserPageContext implements TemplatePageContext
{
    /**
     * @param list<int> $availableRates
     * @param array<string, array<string, mixed>> $ratings
     * @param array<int, ImageThumbUrl> $imageUrls
     * @param list<string> $orderByOptions
     */
    public function __construct(
        public int $orderByIndex,
        public string $formAction,
        public int $minRates,
        public int $consensusTopNumber,
        public array $availableRates,
        public array $ratings,
        public array $imageUrls,
        public int $tnWidth,
        public int $nbElements,
        public string $adminPageTitle,
        public array $orderByOptions,
    ) {}

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function toArray(): array
    {
        return [
            'order_by_options_selected' => [$this->orderByIndex],
            'F_ACTION' => $this->formAction,
            'F_MIN_RATES' => $this->minRates,
            'CONSENSUS_TOP_NUMBER' => $this->consensusTopNumber,
            'available_rates' => $this->availableRates,
            'ratings' => $this->ratings,
            'image_urls' => array_map(static fn (ImageThumbUrl $imageUrl): array => $imageUrl->toArray(), $this->imageUrls),
            'TN_WIDTH' => $this->tnWidth,
            'NB_ELEMENTS' => $this->nbElements,
            'ADMIN_PAGE_TITLE' => $this->adminPageTitle,
            'order_by_options' => $this->orderByOptions,
        ];
    }
}
