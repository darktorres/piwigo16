<?php

declare(strict_types=1);

namespace Piwigo\Controller\Projection;

use Override;
use Piwigo\Core\TemplatePageContext;

/**
 * `header.latte`'s own nav/meta fields for a picture page --
 * `first`/`previous`/`next`/`last` (each `array{U_IMG?: string}`),
 * `U_UP`, `COMMENT_IMG`, `INFO_AUTHOR`, `INFO_FILE`, `U_PREFETCH`,
 * `related_tags`.
 *
 * `COMMENT_IMG`/`INFO_AUTHOR`/`related_tags` are here *because*
 * `layout.latte` builds `<meta name="description">`/`author`/`keywords`
 * out of them. They are the same three values `PictureView` carries for
 * the page body, deliberately duplicated onto this earlier context
 * rather than shared, for the parse-order reason below.
 * Same reasoning as {@see CanonicalUrlPageContext}: `header.latte`
 * parses before `PictureController`'s own `PictureView`/`SlideshowView`
 * is ever constructed, so these stay on the ambient `assignContext()`
 * mechanism instead of becoming View properties.
 */
final readonly class PictureHeaderPageContext implements TemplatePageContext
{
    /**
     * @param list<array<string, mixed>>|null $relatedTags
     */
    public function __construct(
        public ?PictureNavEntry $navFirst,
        public ?PictureNavEntry $navPrevious,
        public ?PictureNavEntry $navNext,
        public ?PictureNavEntry $navLast,
        public string $uUp,
        public ?string $commentImg,
        public ?string $infoAuthor,
        public string $infoFile,
        public ?string $uPrefetch,
        public ?array $relatedTags,
    ) {}

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function toArray(): array
    {
        $result = [
            'U_UP' => $this->uUp,
            'INFO_FILE' => $this->infoFile,
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

        if ($this->commentImg !== null) {
            $result['COMMENT_IMG'] = $this->commentImg;
        }

        if ($this->infoAuthor !== null) {
            $result['INFO_AUTHOR'] = $this->infoAuthor;
        }

        if ($this->uPrefetch !== null) {
            $result['U_PREFETCH'] = $this->uPrefetch;
        }

        if ($this->relatedTags !== null) {
            $result['related_tags'] = $this->relatedTags;
        }

        return $result;
    }
}
