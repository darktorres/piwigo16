<?php

declare(strict_types=1);

namespace Piwigo\Controller\Api;

use Override;
use Piwigo\Http\ControllerInterface;
use Piwigo\Http\ResponseFactory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `GET /api/v1/session` -- `pwg.session.getStatus`'s real replacement.
 * Deliberately a fresh implementation, not a shared extraction with
 * `Ws\Session\GetStatusHandler`: that handler's own User-Agent-sniffed
 * branches (dropping `save_visits`/`connected_with`/`available_sizes` for
 * the "Piwigo Remote Sync" client) are exactly the kind of wire-format
 * compat cruft P27 has no obligation to carry (Locked Decision D5), but
 * WS's own wire contract stays untouched until it's actually deleted
 * (Numbering section) -- so the WS handler keeps its UA branches, and this
 * duplicates the small, clean remainder rather than forcing one shared
 * implementation to carry both. Auth is not gated: works the same for a
 * guest or a signed-in user, exactly like its WS predecessor. Body itself
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
        return ResponseFactory::json($this->sessionStatusPresenter->present());
    }
}
