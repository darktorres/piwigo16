<?php

declare(strict_types=1);

namespace Piwigo\Admin\Projection;

use Piwigo\Core\View;
use Piwigo\Template\Latte\Attribute\Template;

/**
 * `rating_user.latte`'s own typed view, constructed by {@see
 * \Piwigo\Admin\RatingUserPageRenderer::render()}. `$ratings`/
 * `$imageUrls` stay loose row shapes: `$ratings` is the same genuinely
 * dynamic, incrementally-accumulated-across-3-mutation-points shape
 * documented on `RatingUserPageRenderer`'s own `avgCompare()`/
 * `countCompare()`/etc. comparators, not a fixed structural shape worth
 * minting its own DTO for here. `$orderByOptions` is always included --
 * the template reads it via an unguarded `{=htmlOptions(...)}`, matching
 * the original code's own unconditional loop.
 */
#[Template('rating_user.latte')]
final readonly class RatingUserView implements View
{
    /**
     * @param list<int> $orderByOptionsSelected
     * @param list<int> $availableRates
     * @param array<string, array<string, mixed>> $ratings
     * @param array<int, array{tn: string, page: string}> $imageUrls
     * @param list<string> $orderByOptions
     */
    public function __construct(
        public array $orderByOptionsSelected,
        public string $formAction,
        public int $minRates,
        public int $consensusTopNumber,
        public array $availableRates,
        public array $ratings,
        public array $imageUrls,
        public int $tnWidth,
        public int $nbElements,
        public array $orderByOptions,
        public string $csrfToken,
    ) {}
}
