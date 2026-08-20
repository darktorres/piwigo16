<?php

declare(strict_types=1);

namespace Piwigo\Controller\Projection;

use Latte\Runtime\Html;
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
 * render.
 */
#[Template('picture.latte')]
final readonly class PictureView implements View
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
    ) {}
}
