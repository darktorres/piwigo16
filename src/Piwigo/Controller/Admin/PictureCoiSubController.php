<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin;

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
    #[\Override]
    public function handle(ServerRequestInterface $request): void
    {
        include PHPWG_ROOT_PATH . 'admin/picture_coi.php';
    }
}
