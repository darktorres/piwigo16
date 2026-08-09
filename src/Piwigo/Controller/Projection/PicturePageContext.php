<?php

declare(strict_types=1);

namespace Piwigo\Controller\Projection;

use Override;
use Piwigo\Core\TemplatePageContext;

/**
 * The template variable set assigned directly by
 * {@see \Piwigo\Controller\PictureController::__invoke()} (its own
 * assigns, not {@see \Piwigo\Controller\PictureController::
 * defaultPictureContent()}'s -- see {@see PictureContentPageContext} for
 * that one). `$navFirst`/`$navPrevious`/`$navNext`/`$navLast`/
 * `$navCurrent` mirror the original's `foreach (['first', 'previous',
 * 'next', 'last', 'current'] as $which_image)` loop -- each slot is
 * independently optional (`isset($picture[$which_image])`), and the row
 * shape itself is a loose bag (`PicturePicturesData::$picture` is
 * deliberately untyped so plugins can extend it).
 * `$uSlideshowStop`/`$slideshowNav` are only ever assigned together (the
 * `$slideshow` branch); `$uSlideshowStart` is mutually exclusive with
 * that pair (the `elseif` branch) but maps to a different template key,
 * so stays a separate field. `$infoAuthor`/`$infoCreationDate`/
 * `$infoDimensions`/`$infoFilesize` are the original's conditionally-set
 * `$infos` keys; `$infoPostedDate`/`$infoVisits`/`$infoFile` are its
 * unconditional ones -- fully enumerable, so modeled as named fields
 * rather than a loose passthrough bag. `$pdfNbPages` alone gates
 * `PDF_NB_PAGES`'s inclusion (`ImageService::countPdfPages()` never
 * returns null, only `int|false`, once the pdf branch runs) -- the
 * sibling `PDF_VIEWER_FILESIZE_THRESHOLD` key moved to {@see
 * PictureContentPageContext}, the template variable set actually live
 * when `picture_content.tpl` (its one real consumer) parses. `$relatedTags`/
 * `$relatedCategories` were formerly `Template::append()`-built lists --
 * `null` when the original loop never ran at all (matching its own
 * `isset($related_tags)`/`isset($related_categories)` template guards),
 * a real, non-empty list otherwise.
 */
final readonly class PicturePageContext implements TemplatePageContext
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
        public string $uCanonical,
        public ?array $relatedTags,
        public ?array $relatedCategories,
    ) {}

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function toArray(): array
    {
        $result = [
            'SECTION_TITLE' => $this->sectionTitle,
            'PHOTO' => $this->photo,
            'IS_HOME' => $this->isHome,
            'LEVEL_SEPARATOR' => $this->levelSeparator,
            'U_UP' => $this->uUp,
            'DISPLAY_NAV_BUTTONS' => $this->displayNavButtons,
            'DISPLAY_NAV_THUMB' => $this->displayNavThumb,
            'INFO_POSTED_DATE' => $this->infoPostedDate,
            'INFO_VISITS' => $this->infoVisits,
            'INFO_FILE' => $this->infoFile,
            'display_info' => $this->displayInfo,
            'ELEMENT_CONTENT' => $this->elementContent,
            'U_CANONICAL' => $this->uCanonical,
        ];

        if ($this->navFirst !== null) {
            $result['first'] = $this->navFirst;
        }

        if ($this->navPrevious !== null) {
            $result['previous'] = $this->navPrevious;
        }

        if ($this->navNext !== null) {
            $result['next'] = $this->navNext;
        }

        if ($this->navLast !== null) {
            $result['last'] = $this->navLast;
        }

        if ($this->navCurrent !== null) {
            $result['current'] = $this->navCurrent;
        }

        if ($this->uSlideshowStop !== null) {
            $result['U_SLIDESHOW_STOP'] = $this->uSlideshowStop;
            $result['slideshow'] = $this->slideshowNav;
        }

        if ($this->uSlideshowStart !== null) {
            $result['U_SLIDESHOW_START'] = $this->uSlideshowStart;
        }

        if ($this->uMetadata !== null) {
            $result['U_METADATA'] = $this->uMetadata;
        }

        if ($this->uSetAsRepresentative !== null) {
            $result['U_SET_AS_REPRESENTATIVE'] = $this->uSetAsRepresentative;
        }

        if ($this->uPhotoAdmin !== null) {
            $result['U_PHOTO_ADMIN'] = $this->uPhotoAdmin;
        }

        if ($this->uCaddie !== null) {
            $result['U_CADDIE'] = $this->uCaddie;
        }

        if ($this->favorite !== null) {
            $result['favorite'] = $this->favorite;
        }

        if ($this->commentImg !== null) {
            $result['COMMENT_IMG'] = $this->commentImg;
        }

        if ($this->infoAuthor !== null) {
            $result['INFO_AUTHOR'] = $this->infoAuthor;
        }

        if ($this->infoCreationDate !== null) {
            $result['INFO_CREATION_DATE'] = $this->infoCreationDate;
        }

        if ($this->infoDimensions !== null) {
            $result['INFO_DIMENSIONS'] = $this->infoDimensions;
        }

        if ($this->infoFilesize !== null) {
            $result['INFO_FILESIZE'] = $this->infoFilesize;
        }

        if ($this->pdfNbPages !== null) {
            $result['PDF_NB_PAGES'] = $this->pdfNbPages;
        }

        if ($this->uPrefetch !== null) {
            $result['U_PREFETCH'] = $this->uPrefetch;
        }

        if ($this->relatedTags !== null) {
            $result['related_tags'] = $this->relatedTags;
        }

        if ($this->relatedCategories !== null) {
            $result['related_categories'] = $this->relatedCategories;
        }

        return $result;
    }
}
