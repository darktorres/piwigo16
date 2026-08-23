<?php

declare(strict_types=1);

namespace Piwigo\Controller\Api\Uploads;

use Override;
use Piwigo\Http\AdminGuard;
use Piwigo\Http\ControllerInterface;
use Piwigo\Http\ResponseFactory;
use Piwigo\Users\CurrentUser;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `HEAD /api/v1/uploads/{id}` -- tus 1.0.0 Core, lets a client resume an
 * interrupted upload by asking how many bytes the server already has.
 * Admin only, and scoped to the session's own creator (`TusUploadStore::
 * findOwnedBy()`) -- an upload id is an opaque, unguessable token, but
 * this closes the gap anyway rather than relying on that alone.
 */
final readonly class TusUploadHeadController implements ControllerInterface
{
    private const string TUS_VERSION = '1.0.0';

    public function __construct(
        private AdminGuard $adminGuard,
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

        if ($request->getHeaderLine('Tus-Resumable') !== self::TUS_VERSION) {
            return $this->problem('Precondition Failed', 412, 'Tus-Resumable: ' . self::TUS_VERSION . ' is required.');
        }

        $routeArgs = $request->getAttribute('route_args');
        $id = is_array($routeArgs) && is_string($routeArgs['id'] ?? null) ? $routeArgs['id'] : '';

        $session = $this->tusUploadStore->findOwnedBy($id, $this->currentUser->get()->id->value);
        if (! $session instanceof TusUploadSession) {
            return $this->problem('Not Found', 404, 'Unknown upload.');
        }

        $offset = $this->tusUploadStore->offset($session->id) ?? 0;

        return ResponseFactory::raw('', [
            'Tus-Resumable' => self::TUS_VERSION,
            'Upload-Offset' => (string) $offset,
            'Upload-Length' => (string) $session->uploadLength,
            'Cache-Control' => 'no-store',
        ]);
    }

    private function problem(string $title, int $status, string $detail): ResponseInterface
    {
        return ResponseFactory::problem($title, $status, $detail)
            ->withHeader('Tus-Resumable', self::TUS_VERSION);
    }
}
