<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin;

use Override;
use Piwigo\Admin\PictureCoiPageRenderer;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Replaces admin/picture_coi.php (page slug "picture_coi") -- a flat page,
 * pure delegate. This batch extracted its raw `UPDATE images SET coi=...`
 * into Piwigo\Image\ImageRepository::updateCoi(), matching
 * incrementVisitCounter()'s own existing precedent of a single-purpose,
 * directly-instantiated (no service layer) image write method on this
 * repository.
 */
final class PictureCoiSubController implements AdminSubControllerInterface
{
    public function __construct(
        private readonly PictureCoiPageRenderer $pictureCoiPageRenderer,
    ) {}

    #[Override]
    public function handle(ServerRequestInterface $request): void
    {
        $this->pictureCoiPageRenderer
            ->render();
    }
}
