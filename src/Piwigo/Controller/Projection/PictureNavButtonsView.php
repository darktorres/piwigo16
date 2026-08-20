<?php

declare(strict_types=1);

namespace Piwigo\Controller\Projection;

/**
 * `{templateType}` target for `picture_nav_buttons.latte`. Never
 * rendered via `Renderer::render()` -- reached only through
 * `picture.latte`/`slideshow.latte`'s own bare `{include
 * 'picture_nav_buttons.latte'}` (full parent-scope inheritance from
 * whichever of {@see PictureView}/{@see SlideshowView} is rendering,
 * both of which already carry all 7 of these exact fields --
 * `SlideshowView`'s own docblock already documents this same
 * dependency). Contract-only conversion, same shape as {@see
 * \Piwigo\Menu\Projection\MenubarBlockView}.
 */
final readonly class PictureNavButtonsView
{
    /**
     * @param array<string, mixed>|null $navFirst
     * @param array<string, mixed>|null $navPrevious
     * @param array<string, mixed>|null $navNext
     * @param array<string, mixed>|null $navLast
     * @param array<string, string>|null $slideshowNav
     */
    public function __construct(
        public ?array $navFirst,
        public ?array $navPrevious,
        public ?array $navNext,
        public ?array $navLast,
        public string $uUp,
        public bool $displayNavButtons,
        public ?array $slideshowNav,
    ) {}
}
