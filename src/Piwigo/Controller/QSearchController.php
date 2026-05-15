<?php

declare(strict_types=1);

namespace Piwigo\Controller;

use Piwigo\Core\AccessLevel;
use Piwigo\Http\RedirectResponder;
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
final readonly class QSearchController implements ControllerInterface
{
    public function __construct(
        private UrlGenerator $urlGenerator,
        private PermissionService $permissionService,
        private RedirectResponder $redirectResponder,
        private UrlService $urlService,
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
        $this->redirectResponder->redirect($this->urlService->addUrlParams($this->urlGenerator->searchPage(), ['q' => $q]));
        return ResponseFactory::create(302);
    }
}
