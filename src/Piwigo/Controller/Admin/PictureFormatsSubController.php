<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Replaces admin/picture_formats.php (page slug "picture_formats") -- a
 * flat, read-only page, pure delegate. Confirmed via direct read: no write
 * logic at all.
 */
final class PictureFormatsSubController implements AdminSubControllerInterface
{
    #[\Override]
    public function handle(ServerRequestInterface $request): void
    {
        include PHPWG_ROOT_PATH . 'admin/picture_formats.php';
    }
}
