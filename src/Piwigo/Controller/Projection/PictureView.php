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
use Piwigo\Contribution\PictureInfoRow;
use Piwigo\Core\ExposesPageData;
use Piwigo\Core\View;
use Piwigo\Template\Latte\Attribute\Template;

/**
 * `picture.latte`'s own typed view, constructed by {@see
 * \Piwigo\Controller\PictureController::__invoke()}. `PictureController`
 * picks between this and {@see SlideshowView} by which one it
 * constructs (light-slideshow mode renders `slideshow.latte` instead) --
 * the two share the same field set (and the same real caller data) since
 * `#[Template]` binds one class to exactly one file. `$uCanonical` stays
 * off this class: `header.latte`'s own `<link rel="canonical">` renders
 * while `PageHeaderRenderer::render()` parses `header.latte`, before
 * this view is ever constructed -- see {@see CanonicalUrlPageContext}.
 * `$cookiePath`/`$uOriginal` are computed directly here, from the same
 * `$picture['current']` data `defaultPictureContent()` also reads for
 * its own `PictureContentView` -- a `Renderer::render()` call never
 * mutates `Template::$vars`, so a `View` only ever sees the properties
 * it declares itself, nothing implicitly carried over from a sibling
 * render. `$rootUrl` is the ambient `$ROOT_URL` the template's own
 * `exposeData` call reads -- resolved by the controller via
 * `$urlService->getRootUrl()`, kept off `SlideshowView`'s own shared
 * `$commonPictureViewArgs` spread since `slideshow.latte` never
 * references it.
 */
#[Template('picture.latte')]
final readonly class PictureView implements View, HasPageAssets, ExposesPageData
{
    /**
     * @param array<string, mixed>|null $navFirst
     * @param array<string, mixed>|null $navPrevious
     * @param array<string, mixed>|null $navNext
     * @param array<string, mixed>|null $navLast
     * @param array<string, mixed>|null $navCurrent
     * @param array<string, string>|null $slideshowNav
     * @param array<string, bool> $displayInfo
     * @param array{IS_FAVORITE: bool, U_FAVORITE: string}|null $favorite
     * @param list<array<string, mixed>>|null $relatedTags
     * @param list<string>|null $relatedCategories
     * @param list<ButtonContribution> $pluginPictureButtons
     * @param list<ActionContribution> $pluginPictureActions
     * @param list<PictureInfoRow> $pluginPictureInfoRows
     * @param list<array{TITLE: string, lines: array<string, mixed>}>|null $metadata
     * @param array<string, mixed>|null $rateSummary
     * @param array{F_ACTION: string, USER_RATE: ?int, marks: list<int>}|null $rating
     * @param array{CURRENT_PAGE?: float, URL_FIRST?: string, URL_PREV?: string, URL_NEXT?: string, URL_LAST?: string, pages?: array<int, string>, NB_PAGE?: int}|null $commentsNavbar
     * @param list<array<string, mixed>>|null $comments
     * @param array<string, mixed>|null $commentAdd
     */
    public function __construct(
        public ?array $navFirst,
        public ?array $navPrevious,
        public ?array $navNext,
        public ?array $navLast,
        public ?array $navCurrent,
        public ?string $uSlideshowStop,
        public ?array $slideshowNav,
        public ?string $uSlideshowStart,
        public string $sectionTitle,
        public string $photo,
        public bool $isHome,
        public string $levelSeparator,
        public string $uUp,
        public bool $displayNavButtons,
        public bool $displayNavThumb,
        public ?string $uMetadata,
        public ?string $uSetAsRepresentative,
        public ?string $uPhotoAdmin,
        public ?string $uCaddie,
        public ?array $favorite,
        public ?string $commentImg,
        public ?string $infoAuthor,
        public ?string $infoCreationDate,
        public string $infoPostedDate,
        public ?string $infoDimensions,
        public ?string $infoFilesize,
        public string $infoVisits,
        public string $infoFile,
        public array $displayInfo,
        public int|false|null $pdfNbPages,
        public string $elementContent,
        public ?string $uPrefetch,
        public ?array $relatedTags,
        public ?array $relatedCategories,
        public string $csrfToken,
        public string $cookiePath,
        public ?string $uOriginal,
        public array $pluginPictureButtons,
        public array $pluginPictureActions,
        public array $pluginPictureInfoRows,
        public ?array $metadata,
        public ?array $rateSummary,
        public ?array $rating,
        public ?string $commentsOrderUrl,
        public ?string $commentsOrderTitle,
        public ?int $commentCount,
        public ?array $commentsNavbar,
        public ?array $comments,
        public ?array $commentAdd,
        public ?Html $commentList,
        public string $rootUrl,
    ) {}

    /**
     * `picture.latte`'s own unconditional `{do combineScript(...)}`x2/
     * `{do combineCss(...)}`, its two conditional `{if isset($uOriginal)}`/
     * `{if isset($rating)}` `combineScript('core.scripts', ...)` blocks,
     * and its `{include 'picture_nav_buttons.latte'}`'s own contract-only
     * `PictureNavButtonsView::pageAssets()`, manually merged in since
     * `Renderer::render()` never runs for an `{include}`-only partial
     * (docs/PLAN.md's P42-B).
     */
    #[Override]
    public function pageAssets(): array
    {
        $assets = [
            // switchbox.ts's own registration dropped (docs/PLAN.md P48,
            // switchbox.ts's own batch) -- folds into picture.ts's own
            // bundle via a direct import instead, same real,
            // accepted Async-to-Footer timing change as IndexView's own
            // identical copy of this comment.
            AssetContribution::css('themes/default/css/pages/picture.css', id: 'picture'),
            // 'picture' imports scripts.ts directly now (docs/PLAN.md
            // P48) -- both of this method's own
            // former conditional `core.scripts` registrations below are
            // dropped, since 'picture' is unconditional and already
            // covers them regardless of $uOriginal/$rating.
            AssetContribution::script('picture', 'themes/default/js/picture.ts', loadMode: LoadMode::Footer, dependsOn: ['jquery']),
        ];

        if ($this->rating !== null) {
            // 'rating' folds scripts.ts's own code in via its own real
            // direct import too (docs/PLAN.md P48) -- no more
            // `dependsOn: ['core.scripts']`, that id no longer exists as
            // a separate registration.
            $assets[] = AssetContribution::script('rating', 'themes/default/js/rating.ts', loadMode: LoadMode::Async);
        }

        return [...$assets, ...$this->pictureNavButtonsView()->pageAssets()];
    }

    #[Override]
    public function exposedPageData(): array
    {
        $navCurrentIdRaw = $this->navCurrent['id'] ?? null;
        $navCurrentId = is_string($navCurrentIdRaw) || is_int($navCurrentIdRaw) ? $navCurrentIdRaw : '';

        return [
            'cookie_path' => $this->cookiePath,
            'root_url' => $this->rootUrl,
            'image_id' => $navCurrentId,
            'csrf_token' => $this->csrfToken,
            ...$this->pictureNavButtonsView()
                ->exposedPageData(),
        ];
    }

    /**
     * `picture.latte`'s own unconditional `{do exposeString(...)}`x3
     * (docs/PLAN.md's P42-B).
     */
    #[Override]
    public function exposedStrings(): array
    {
        return [
            'Update your rating',
            '%d rate',
            '%d rates',
        ];
    }

    private function pictureNavButtonsView(): PictureNavButtonsView
    {
        return new PictureNavButtonsView(
            navFirst: $this->navFirst,
            navPrevious: $this->navPrevious,
            navNext: $this->navNext,
            navLast: $this->navLast,
            uUp: $this->uUp,
            displayNavButtons: $this->displayNavButtons,
            slideshowNav: $this->slideshowNav,
        );
    }
}
