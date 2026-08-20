<?php

declare(strict_types=1);

namespace Piwigo\Controller\Projection;

use Override;
use Piwigo\Asset\AssetContribution;
use Piwigo\Asset\HasPageAssets;
use Piwigo\Asset\LoadMode;
use Piwigo\Core\ExposesPageData;

/**
 * `{templateType}` target for `picture_nav_buttons.latte`. Never
 * rendered via `Renderer::render()` -- reached only through
 * `picture.latte`/`slideshow.latte`'s own bare `{include
 * 'picture_nav_buttons.latte'}` (full parent-scope inheritance from
 * whichever of {@see PictureView}/{@see SlideshowView} is rendering,
 * both of which already carry all 7 of these exact fields --
 * `SlideshowView`'s own docblock already documents this same
 * dependency). Contract-only conversion, same shape as {@see
 * \Piwigo\Menu\Projection\MenubarBlockView} -- deliberately not
 * implementing `Piwigo\Core\View` (no `#[Template]` attribute, never a
 * `Renderer::render()` target), but `HasPageAssets`/`ExposesPageData`
 * are plain standalone interfaces with no such requirement: each real
 * parent (`PictureView` today; `SlideshowView` in a future batch)
 * constructs an instance of this class and manually merges its
 * `pageAssets()`/`exposedPageData()` into its own (docs/PLAN.md's
 * P42-B).
 */
final readonly class PictureNavButtonsView implements HasPageAssets, ExposesPageData
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

    /**
     * `picture_nav_buttons.latte`'s own unconditional
     * `{do combineScript(...)}` (docs/PLAN.md's P42-B).
     */
    #[Override]
    public function pageAssets(): array
    {
        return [
            AssetContribution::script('picture_nav_buttons', 'themes/default/js/picture_nav_buttons.js', loadMode: LoadMode::Footer, dependsOn: ['page-data']),
        ];
    }

    /**
     * `picture_nav_buttons.latte`'s own `{if isset(...)}{do
     * exposeData(...)}{/if}`x7 (docs/PLAN.md's P42-B) -- every entry is
     * genuinely conditional, since each nav direction is independently
     * nullable.
     */
    #[Override]
    public function exposedPageData(): array
    {
        $data = [];

        if ($this->navNext !== null) {
            $data['nav_next_url'] = is_string($this->navNext['U_IMG'] ?? null) ? $this->navNext['U_IMG'] : '';
        }
        if ($this->navPrevious !== null) {
            $data['nav_previous_url'] = is_string($this->navPrevious['U_IMG'] ?? null) ? $this->navPrevious['U_IMG'] : '';
        }
        if ($this->navFirst !== null) {
            $data['nav_first_url'] = is_string($this->navFirst['U_IMG'] ?? null) ? $this->navFirst['U_IMG'] : '';
        }
        if ($this->navLast !== null) {
            $data['nav_last_url'] = is_string($this->navLast['U_IMG'] ?? null) ? $this->navLast['U_IMG'] : '';
        }
        if ($this->slideshowNav === null) {
            $data['nav_up_url'] = $this->uUp;
        }
        if (isset($this->slideshowNav['U_START_PLAY'])) {
            $data['nav_slideshow_start_url'] = $this->slideshowNav['U_START_PLAY'];
        }
        if (isset($this->slideshowNav['U_STOP_PLAY'])) {
            $data['nav_slideshow_stop_url'] = $this->slideshowNav['U_STOP_PLAY'];
        }

        return $data;
    }

    #[Override]
    public function exposedStrings(): array
    {
        return [];
    }
}
