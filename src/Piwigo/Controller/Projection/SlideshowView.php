<?php

declare(strict_types=1);

namespace Piwigo\Controller\Projection;

use Latte\Runtime\Html;
use Override;
use Piwigo\Asset\HasPageAssets;
use Piwigo\Core\ExposesPageData;
use Piwigo\Core\Projection\Navbar;
use Piwigo\Core\View;
use Piwigo\Picture\Projection\CommentAddForm;
use Piwigo\Picture\Projection\CommentRow;
use Piwigo\Picture\Projection\RateSummary;
use Piwigo\Template\Latte\Attribute\Template;

/**
 * `slideshow.latte`'s own typed view -- the light-slideshow-mode sibling
 * of {@see PictureView}, constructed by {@see
 * \Piwigo\Controller\PictureController::__invoke()} from the exact same
 * data, only when `$slideshow and $this->currentConfig->lightSlideshow`.
 * Its own body only reads a handful of these fields directly
 * (`$photo`/`$elementContent`/`$uSlideshowStop`/`$commentImg`/
 * `$navCurrent`), but it also `{include}`s `picture_nav_buttons.latte`
 * with no explicit params -- which inherits this class's own full scope
 * and reads several more (`$displayNavButtons`/`$slideshowNav`/`$uUp`/
 * `$navFirst`/`$navPrevious`/`$navNext`/`$navLast`) -- so this carries
 * the same full field set as `PictureView` rather than a hand-trimmed
 * subset. `pageAssets()`/`exposedPageData()` exist solely to merge in
 * `picture_nav_buttons.latte`'s own contract-only
 * `PictureNavButtonsView` contribution -- `slideshow.latte` itself has
 * zero registration calls of its own (docs/PLAN.md's P42-B).
 *
 * No `$pluginPictureButtons`/`$pluginPictureActions` -- confirmed by
 * grep that `slideshow.latte` never reads either (P43-A, docs/PLAN.md):
 * `PictureController` feeds both views from the same
 * `$template->pictureButtons()`/`pictureActions()` calls, but only
 * `PictureView` actually renders them.
 */
#[Template('slideshow.latte')]
final readonly class SlideshowView implements View, HasPageAssets, ExposesPageData
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
     * @param list<array{TITLE: string, lines: array<string, mixed>}>|null $metadata
     * @param array{F_ACTION: string, USER_RATE: ?int, marks: list<int>}|null $rating
     * @param list<CommentRow>|null $comments
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
        public ?array $metadata,
        public ?RateSummary $rateSummary,
        public ?array $rating,
        public ?string $commentsOrderUrl,
        public ?string $commentsOrderTitle,
        public ?int $commentCount,
        public ?Navbar $commentsNavbar,
        public ?array $comments,
        public ?CommentAddForm $commentAdd,
        public ?Html $commentList,
    ) {}

    #[Override]
    public function pageAssets(): array
    {
        return $this->pictureNavButtonsView()
            ->pageAssets();
    }

    #[Override]
    public function exposedPageData(): array
    {
        return $this->pictureNavButtonsView()
            ->exposedPageData();
    }

    #[Override]
    public function exposedStrings(): array
    {
        return [];
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
