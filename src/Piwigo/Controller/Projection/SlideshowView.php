<?php

declare(strict_types=1);

namespace Piwigo\Controller\Projection;

use Piwigo\Core\View;
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
 * subset.
 */
#[Template('slideshow.latte')]
final readonly class SlideshowView implements View
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
     * @param list<string> $pluginPictureButtons
     * @param list<array{TITLE: string, lines: array<string, mixed>}>|null $metadata
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
        public ?array $metadata,
    ) {}
}
