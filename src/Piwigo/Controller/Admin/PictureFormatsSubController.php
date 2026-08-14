<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin;

use Doctrine\ORM\EntityManagerInterface;
use Override;
use Piwigo\Admin\PictureFormatsPageRenderer;
use Piwigo\Auth\AccessControl;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\HtmlRenderingInterface;
use Piwigo\Core\Lang;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Image\ImageStdParams;
use Piwigo\Template\CurrentTemplate;
use Piwigo\Validation\InputValidator;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Replaces admin/picture_formats.php (page slug "picture_formats") -- a
 * flat, read-only page, pure delegate. Confirmed via direct read: no write
 * logic at all.
 */
final readonly class PictureFormatsSubController implements AdminSubControllerInterface
{
    public function __construct(
        private Lang $lang,
        private AccessControl $accessControl,
        private UrlServiceInterface $urlService,
        private ImageStdParams $imageStdParams,
        private CurrentTemplate $currentTemplate,
        private HtmlRenderingInterface $htmlRenderer,
        private InputValidator $inputValidator,
        private CurrentConfig $currentConfig,
        private EntityManagerInterface $entityManager,
    ) {}

    #[Override]
    public function handle(ServerRequestInterface $request): void
    {
        new PictureFormatsPageRenderer()
            ->render($this->lang, $this->accessControl, $this->urlService, $this->imageStdParams, $this->currentTemplate, $this->htmlRenderer, $this->inputValidator, $this->currentConfig, $this->entityManager);
    }
}
