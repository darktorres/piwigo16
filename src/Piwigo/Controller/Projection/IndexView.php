<?php

declare(strict_types=1);

namespace Piwigo\Controller\Projection;

use Latte\Runtime\Html;
use Override;
use Piwigo\Asset\AssetContribution;
use Piwigo\Asset\HasPageAssets;
use Piwigo\Asset\LoadMode;
use Piwigo\Contribution\ActionContribution;
use Piwigo\Contribution\ButtonContribution;
use Piwigo\Core\View;
use Piwigo\Template\Latte\Attribute\Template;

/**
 * `index.latte`'s own typed view, constructed by {@see
 * \Piwigo\Controller\GalleryController::__invoke()} in place of its
 * former `GalleryPageContext`/`GalleryThumbnailsPageContext` pair (merged
 * into one class here -- nothing downstream of `index.latte` itself needs
 * them split). `$searchInSetButton`/`$searchInSetAction`/`$searchInSetUrl`
 * are each the "viewing a tag" value when set (the former
 * `GalleryThumbnailsPageContext` variant), else the "viewing one category"
 * value (the former `GalleryPageContext` variant) -- the same
 * last-write-wins result the two separate `assignContext()` calls used to
 * produce, resolved in PHP instead of by call order. `$uCanonical`/
 * `$useStandardPages` are NOT here: `use_standard_pages` has no real
 * template reader anywhere in the app (corpus-wide-fallback noise), and
 * `U_CANONICAL` is needed by `header.latte` before this view is ever
 * constructed -- see {@see CanonicalUrlPageContext}. `$searchId` IS here
 * (unlike `SearchFiltersView`'s own, separately-rendered `$searchId`
 * property) because `index.latte`'s own body reads it directly too --
 * the `{elseif !empty($searchId)}` "no results" branch when `$THUMBNAILS`
 * is empty on a search page, not just `SearchFiltersView`'s sidebar.
 */
#[Template('index.latte')]
final readonly class IndexView implements View, HasPageAssets
{
    /**
     * @param array{CURRENT_PAGE?: float, URL_FIRST?: string, URL_PREV?: string, URL_NEXT?: string, URL_LAST?: string, pages?: array<int, string>, NB_PAGE?: int} $thumbNavbar
     * @param list<array<string, mixed>>|null $combinableTags
     * @param list<string>|null $categorySearchResults
     * @param list<string>|null $noSearchResults
     * @param array<int, array<string, mixed>>|null $imageOrders
     * @param list<array<string, mixed>>|null $relatedTags
     * @param list<array<string, mixed>> $tagSearchResults
     * @param list<array{DISPLAY: string, URL: string, SELECTED: bool}> $imageDerivatives
     * @param list<ButtonContribution> $pluginIndexButtons
     * @param list<ActionContribution> $pluginIndexActions
     */
    public function __construct(
        public array $thumbNavbar,
        public string $title,
        public int $nbItems,
        public ?string $uModeNormal,
        public ?string $uModeFlat,
        public ?string $uModeCreated,
        public ?string $uModePosted,
        public ?bool $searchInSetButton,
        public ?string $searchInSetAction,
        public ?string $searchInSetUrl,
        public ?array $combinableTags,
        public ?string $uEdit,
        public ?string $uCaddie,
        public ?array $categorySearchResults,
        public ?array $noSearchResults,
        public ?array $imageOrders,
        public ?string $contentDescription,
        public ?string $uSlideshow,
        public ?bool $relatedTagsAction,
        public ?array $relatedTags,
        public array $tagSearchResults,
        public array $imageDerivatives,
        public Html $selectedTagsTemplate,
        public array $pluginIndexButtons,
        public array $pluginIndexActions,
        public ?string $searchId,
        public bool $monthCalendarActive,
    ) {}

    /**
     * `$monthCalendarActive` replicates `month_calendar.latte`'s own
     * former `combineCss` call -- included, when at all, via this
     * template's own bare `{include $FILE_CHRONOLOGY_VIEW}` (always the
     * literal `'month_calendar.latte'` in practice -- its one real
     * construction site, {@see \Piwigo\Calendar\CalendarRenderer::render()},
     * never passes anything else), a real derived value (not a fixed
     * literal), covered by its own unit test (docs/PLAN.md's P42-B).
     *
     * @return list<AssetContribution>
     */
    #[Override]
    public function pageAssets(): array
    {
        $assets = [
            AssetContribution::script('core.switchbox', 'themes/default/js/switchbox.ts', loadMode: LoadMode::Async, dependsOn: ['jquery']),
            AssetContribution::script('index', 'themes/default/js/index.ts', loadMode: LoadMode::Footer),
            AssetContribution::css('themes/default/vendor/fontello/css/gallery-icon.css', order: -10),
        ];

        if ($this->monthCalendarActive) {
            $assets[] = AssetContribution::css('themes/default/css/pages/month_calendar.css', id: 'month_calendar');
        }

        return $assets;
    }
}
