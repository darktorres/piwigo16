<?php

declare(strict_types=1);

namespace Piwigo\Controller;

use Piwigo\Auth\AccessControl;
use Piwigo\Core\AccessLevel;
use Piwigo\Core\RedirectServiceInterface;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Http\ControllerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Replaces qsearch.php -- always redirects to search.php?q=..., no
 * rendering of its own. RedirectServiceInterface::redirect() is typed
 * `never` (calls header()+exit() directly); this controller's own return
 * type is honored by PHPStan understanding that call as terminating every
 * path, same as every other controller's check_status() call this phase.
 */
final class QSearchController implements ControllerInterface
{
    public function __construct(
        private readonly AccessControl $accessControl,
        private readonly RedirectServiceInterface $redirectService,
        private readonly UrlServiceInterface $urlService,
    ) {}

    #[\Override]
    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $this->accessControl->checkStatus(AccessLevel::Guest);

        $q = $request->getQueryParams()['q'] ?? '';
        $q = is_string($q) ? $q : '';

        $this->redirectService->redirect($this->urlService->getRootUrl() . 'search.php?q=' . $q);
    }
}
