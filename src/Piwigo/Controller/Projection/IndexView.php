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
use Piwigo\Core\Projection\Navbar;
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
     * @param list<array<string, mixed>>|null $combinableTags
     * @param list<string>|null $categorySearchResults
     * @param list<string>|null $noSearchResults
     * @param array<int, ImageOrderOption>|null $imageOrders
     * @param list<array<string, mixed>> $relatedTags empty when the panel
     *   has nothing to show, which is the only thing its `n:if` asks
     * @param list<array<string, mixed>> $tagSearchResults
     * @param list<ImageOrderOption> $imageDerivatives
     * @param list<ButtonContribution> $pluginIndexButtons
     * @param list<ActionContribution> $pluginIndexActions
     */
    public function __construct(
        public Navbar $thumbNavbar,
        // Html, not a plain string (P59 Batch 4): see
        // Section\SectionContext::$sectionTitle's own docblock -- this is
        // that same value, under a differently-named field here.
        public Html $title,
        public int $nbItems,
        public ?string $uModeNormal,
        public ?string $uModeFlat,
        public ?string $uModeCreated,
        public ?string $uModePosted,
        public ?bool $searchInSetButton,
        public ?bool $searchInSetAction,
        public ?string $searchInSetUrl,
        public ?array $combinableTags,
        public ?string $uEdit,
        public ?string $uCaddie,
        public ?array $categorySearchResults,
        public ?array $noSearchResults,
        public ?array $imageOrders,
        // Html, not a plain string (P59 Batch 2): sourced from
        // SectionContext::$comment, itself GalleryController's own read of
        // RenderCategoryDescription's output -- safe by permission model
        // (raw HTML passes through untouched only when the admin-only
        // allowHtmlDescriptions setting is on; otherwise HtmlRenderingListener
        // still nl2br()s it but does NOT strip tags), a different, weaker
        // guarantee than CategoryThumbnail::$description's own unconditional
        // strip_tags-allowlist pass.
        public ?Html $contentDescription,
        public ?string $uSlideshow,
        public array $relatedTags,
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
            // switchbox.ts's own registration dropped (docs/PLAN.md P48,
            // switchbox.ts's own batch) -- folds into index.ts's own
            // bundle via a direct import instead. Real, accepted
            // timing change: switchbox.ts used to load at this page's
            // own Async, now runs at index.ts's own Footer instead --
            // safe since switchbox.ts's own "queue array, then live
            // handler" shape-shifting design (build/ambient-globals.d.ts's
            // own `SwitchBoxQueue` comment) is already safe regardless
            // of load order relative to its 2 real pushers.
            AssetContribution::script('index', 'themes/default/js/index.ts', loadMode: LoadMode::Footer),
            AssetContribution::css('themes/default/vendor/fontello/css/gallery-icon.css', order: -10),
        ];

        if ($this->monthCalendarActive) {
            $assets[] = AssetContribution::css('themes/default/css/pages/month_calendar.css', id: 'month_calendar');
        }

        return $assets;
    }
}
