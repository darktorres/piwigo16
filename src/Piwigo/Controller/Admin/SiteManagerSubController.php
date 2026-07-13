<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Replaces admin/site_manager.php (page slug "site_manager") -- a flat
 * page, pure delegate. Its write paths already go through
 * Piwigo\Site\SiteRepository::insert()/countByUrl()/findGalleriesUrlById()/
 * findAll() (P17); nothing left to extract.
 */
final class SiteManagerSubController implements AdminSubControllerInterface
{
    #[\Override]
    public function handle(ServerRequestInterface $request): void
    {
        include PHPWG_ROOT_PATH . 'admin/site_manager.php';
    }
}
