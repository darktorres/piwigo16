<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin;

use Piwigo\Admin\PictureFormatsPageRenderer;
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
        private readonly UrlServiceInterface $urlService,
        private readonly \Piwigo\Image\ImageStdParams $imageStdParams,
    ) {}

    #[\Override]
    public function handle(ServerRequestInterface $request): void
    {
        new PictureFormatsPageRenderer()
            ->render($this->urlService, $this->imageStdParams);
    }
}
