<?php

declare(strict_types=1);

namespace Piwigo\Controller\Api\Uploads;

use Override;
use Piwigo\Http\AdminGuard;
use Piwigo\Http\ControllerInterface;
use Piwigo\Http\CsrfGuard;
use Piwigo\Http\ResponseFactory;
use Piwigo\Users\CurrentUser;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `DELETE /api/v1/uploads/{id}` -- tus 1.0.0 Termination extension, lets
 * a client abandon an in-progress upload and free its buffer space
 * early rather than waiting for it to go stale. Admin + CSRF, scoped to
 * the session's own creator.
 */
final readonly class TusUploadDeleteController implements ControllerInterface
{
    private const string TUS_VERSION = '1.0.0';

    public function __construct(
        private AdminGuard $adminGuard,
        private CsrfGuard $csrfGuard,
        private CurrentUser $currentUser,
        private TusUploadStore $tusUploadStore,
    ) {}

    #[Override]
    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $denied = $this->adminGuard->check();
        if ($denied instanceof ResponseInterface) {
            return $denied;
        }

        $csrfDenied = $this->csrfGuard->check($request);
        if ($csrfDenied instanceof ResponseInterface) {
            return $csrfDenied;
        }

        if ($request->getHeaderLine('Tus-Resumable') !== self::TUS_VERSION) {
            return $this->problem('Precondition Failed', 412, 'Tus-Resumable: ' . self::TUS_VERSION . ' is required.');
        }

        $routeArgs = $request->getAttribute('route_args');
        $id = is_array($routeArgs) && is_string($routeArgs['id'] ?? null) ? $routeArgs['id'] : '';

        $session = $this->tusUploadStore->findOwnedBy($id, $this->currentUser->get()->id->value);
        if (! $session instanceof TusUploadSession) {
            return $this->problem('Not Found', 404, 'Unknown upload.');
        }

        $this->tusUploadStore->delete($session->id);

        return ResponseFactory::noContent()->withHeader('Tus-Resumable', self::TUS_VERSION);
    }

    private function problem(string $title, int $status, string $detail): ResponseInterface
    {
        return ResponseFactory::problem($title, $status, $detail)
            ->withHeader('Tus-Resumable', self::TUS_VERSION);
    }
}
