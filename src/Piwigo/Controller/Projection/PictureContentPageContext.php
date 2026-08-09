<?php

declare(strict_types=1);

namespace Piwigo\Controller\Projection;

use Override;
use Piwigo\Core\TemplatePageContext;

/**
 * The template variable set assigned by
 * {@see \Piwigo\Controller\PictureController::defaultPictureContent()}
 * -- see {@see PicturePageContext} for that controller's own
 * `__invoke()` assigns. `$uOriginal` is genuinely optional -- the
 * original code only assigns `U_ORIGINAL` when `$show_original` is true
 * and `element_url` is present, omitted here (not present as a null
 * value) to match that exact original behavior. `$pdfViewerFilesizeThreshold`
 * is `picture_content.tpl`'s own real consumer (its `{if ...
 * $current.filesize < $PDF_VIEWER_FILESIZE_THRESHOLD}` guard) -- real
 * bug found live: this used to be assigned by `PicturePageContext`
 * instead, whose own `assignContext()` call in `__invoke()` runs *after*
 * this class's, so the value was never actually live when
 * `picture_content.tpl` parsed (silently evaluated the comparison
 * against an undefined var, never showing the PDF `<embed>`). Computed
 * locally here from the same `$element_info['path_ext'] === 'pdf'`
 * condition the original outer computation used, since it's a pure
 * config lookup with no other per-request dependency. `$current` is
 * `picture_content.tpl`'s own `{$current.TITLE_ESC}`/etc. reads --
 * `defaultPictureContent()`'s own dispatch runs before `__invoke()`'s
 * later `PicturePageContext` assign, and nothing else in the codebase
 * ever writes the `'current'` template var before this point (confirmed
 * via a full-repo grep), so this is always the first and only write --
 * a plain required field, not a Template::append(..., merge: true) onto
 * a variable that's never actually pre-populated.
 */
final readonly class PictureContentPageContext implements TemplatePageContext
{
    /**
     * @param array<string, mixed> $current
     */
    public function __construct(
        public ?string $uOriginal,
        public string $altImg,
        public string $cookiePath,
        public ?int $pdfViewerFilesizeThreshold,
        public array $current,
    ) {}

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function toArray(): array
    {
        $result = [
            'ALT_IMG' => $this->altImg,
            'COOKIE_PATH' => $this->cookiePath,
            'current' => $this->current,
        ];

        if ($this->uOriginal !== null) {
            $result['U_ORIGINAL'] = $this->uOriginal;
        }

        if ($this->pdfViewerFilesizeThreshold !== null) {
            $result['PDF_VIEWER_FILESIZE_THRESHOLD'] = $this->pdfViewerFilesizeThreshold;
        }

        return $result;
    }
}
