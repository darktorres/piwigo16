<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin;

use Piwigo\Admin\PictureFormatsPageRenderer;
use Piwigo\Auth\AccessControl;
use Piwigo\Core\Lang;
use Piwigo\Core\UrlServiceInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Replaces admin/picture_formats.php (page slug "picture_formats") -- a
 * flat, read-only page, pure delegate. Confirmed via direct read: no write
 * logic at all.
 */
final class PictureFormatsSubController implements AdminSubControllerInterface
{
    public function __construct(
        private readonly Lang $lang,
        private readonly AccessControl $accessControl,
        private readonly UrlServiceInterface $urlService,
        private readonly \Piwigo\Image\ImageStdParams $imageStdParams,
        private readonly \Piwigo\Template\CurrentTemplate $currentTemplate,
        private readonly \Piwigo\Core\HtmlRenderingInterface $htmlRenderer,
        private readonly \Piwigo\Validation\InputValidator $inputValidator,
    ) {}

    #[\Override]
    public function handle(ServerRequestInterface $request): void
    {
        new PictureFormatsPageRenderer()
            ->render($this->lang, $this->accessControl, $this->urlService, $this->imageStdParams, $this->currentTemplate, $this->htmlRenderer, $this->inputValidator);
    }
}
