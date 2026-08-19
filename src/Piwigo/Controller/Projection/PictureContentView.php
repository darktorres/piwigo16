<?php

declare(strict_types=1);

namespace Piwigo\Controller\Projection;

use Piwigo\Core\View;
use Piwigo\Template\Latte\Attribute\Template;

/**
 * `picture_content.latte`'s own typed view, constructed by {@see
 * \Piwigo\Controller\PictureController::defaultPictureContent()}.
 * Rendered as a plain string (`Renderer::render()`'s own `Html` cast)
 * into `RenderElementContent::$content`, not appended onto `Template::
 * $output` directly -- see that method's own docblock.
 */
#[Template('picture_content.latte')]
final readonly class PictureContentView implements View
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
}
