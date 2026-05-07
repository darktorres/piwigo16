<?php

declare(strict_types=1);

namespace Piwigo\Controller;

use Piwigo\Core\AccessLevel;
use Piwigo\Core\ServiceLocator;
use Piwigo\Core\Util;
use Piwigo\Http\ResponseFactory;
use Piwigo\Url\UrlGenerator;
use Piwigo\Url\UrlService;
use Piwigo\Users\PermissionService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Quick-search redirect: ?q=term → /search page.
 * Corresponds to the former qsearch.php entry-point.
 */
final class QSearchController implements ControllerInterface
{
    public function __invoke(ServerRequestInterface $request, array $args = []): ResponseInterface
    {
        PermissionService::get()->checkStatus(AccessLevel::Guest);
        $q = is_string($request->getQueryParams()['q'] ?? null) ? $request->getQueryParams()['q'] : '';
        Util::get()->redirect(UrlService::get()->addUrlParams(ServiceLocator::get(UrlGenerator::class)->searchPage(), ['q' => $q]));
        return ResponseFactory::create(302);
    }
}
