<?php

declare(strict_types=1);

namespace Piwigo\Controller\Api;

use Override;
use Piwigo\Http\ControllerInterface;
use Piwigo\Http\ResponseFactory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `GET /api/v1/session` -- `pwg.session.getStatus`'s real replacement, with
 * no User-Agent sniffing (no need to drop `save_visits`/`connected_with`/
 * `available_sizes` for a specific client). Auth is not gated: works the
 * same for a guest or a signed-in user. Body itself
 * comes from `SessionStatusPresenter`, shared with the login/logout
 * controllers.
 */
final readonly class SessionController implements ControllerInterface
{
    public function __construct(
        private SessionStatusPresenter $sessionStatusPresenter,
    ) {}

    #[Override]
    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        return ResponseFactory::json($this->sessionStatusPresenter->present()->toArray());
    }
}
