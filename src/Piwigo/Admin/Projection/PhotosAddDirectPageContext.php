<?php

declare(strict_types=1);

namespace Piwigo\Admin\Projection;

use Override;
use Piwigo\Core\TemplatePageContext;

/**
 * The template variable set assigned directly by
 * {@see \Piwigo\Admin\PhotosAddDirectPageRenderer::render()} (its own
 * final assign block, not {@see
 * \Piwigo\Admin\PhotosAddDirectPageRenderer::prepareUploadForm()}'s --
 * see {@see PhotosAddDirectUploadFormPageContext} for that one).
 * `$formatsOriginalInfo` stays a loose, nullable bag: it starts as `[]`,
 * is reassigned to `getImageInfos()`'s sealed row shape (or `null`, if
 * the image no longer exists) and then has 4 more keys (`src`,
 * `formats`, `ext`, `u_edit`) appended conditionally, so no single fixed
 * shape covers every runtime state.
 */
final readonly class PhotosAddDirectPageContext implements TemplatePageContext
{
    /**
     * @param array<array-key, mixed>|null $formatsOriginalInfo
     */
    public function __construct(
        public bool $promoteMobileApps,
        public string $phpwgUrl,
        public bool $enableFormats,
        public bool $displayFormats,
        public bool $haveFormatsOriginal,
        public ?array $formatsOriginalInfo,
        public string|false|null $formatsExtInfo,
        public string $switchFormatModeUrl,
        public string $formatExt,
        public string $strFormatExt,
    ) {}

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function toArray(): array
    {
        return [
            'PROMOTE_MOBILE_APPS' => $this->promoteMobileApps,
            'APP_URL' => $this->phpwgUrl,
            'ENABLE_FORMATS' => $this->enableFormats,
            'DISPLAY_FORMATS' => $this->displayFormats,
            'HAVE_FORMATS_ORIGINAL' => $this->haveFormatsOriginal,
            'FORMATS_ORIGINAL_INFO' => $this->formatsOriginalInfo,
            'FORMATS_EXT_INFO' => $this->formatsExtInfo,
            'SWITCH_FORMAT_MODE_URL' => $this->switchFormatModeUrl,
            'format_ext' => $this->formatExt,
            'str_format_ext' => $this->strFormatExt,
        ];
    }
}
