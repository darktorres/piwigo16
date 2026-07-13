<?php

declare(strict_types=1);

namespace Piwigo\Controller;

use Piwigo\Core\AccessLevel;
use Piwigo\Http\ControllerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Replaces qsearch.php -- always redirects to search.php?q=..., no
 * rendering of its own. redirect() is typed `never` (calls header()+exit()
 * directly); this controller's own return type is honored by PHPStan
 * understanding that call as terminating every path, same as every other
 * controller's check_status() call this phase.
 */
final class QSearchController implements ControllerInterface
{
    #[\Override]
    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        check_status(AccessLevel::Guest);

        $q = $request->getQueryParams()['q'] ?? '';
        $q = is_string($q) ? $q : '';

        redirect(get_root_url() . 'search.php?q=' . $q);
    }
}
