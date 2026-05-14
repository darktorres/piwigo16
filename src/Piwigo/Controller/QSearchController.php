<?php

declare(strict_types=1);

namespace Piwigo\Controller;

use Piwigo\Core\AccessLevel;
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
    public function __construct(
        private readonly UrlGenerator $urlGenerator,
        private readonly PermissionService $permissionService,
        private readonly Util $util,
        private readonly UrlService $urlService,
    ) {
    }

    #[\Override]
    public function __invoke(ServerRequestInterface $request, array $args = []): ResponseInterface
    {
        $this->permissionService->checkStatus(AccessLevel::Guest);
        $body = $request->getParsedBody();
        $bodyQ = is_array($body) && is_string($body['q'] ?? null) ? $body['q'] : null;
        $queryQ = is_string($request->getQueryParams()['q'] ?? null) ? $request->getQueryParams()['q'] : null;
        $q = $bodyQ ?? $queryQ ?? '';
        $this->util->redirect($this->urlService->addUrlParams($this->urlGenerator->searchPage(), ['q' => $q]));
        return ResponseFactory::create(302);
    }
}
