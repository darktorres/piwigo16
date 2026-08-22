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
 * `PATCH /api/v1/uploads/{id}` -- tus 1.0.0 Core, appends the request
 * body to the upload starting at `Upload-Offset` (rejected with 409 if
 * that doesn't match the upload's real current offset). Admin + CSRF,
 * scoped to the session's own creator.
 *
 * On the chunk that completes the upload, this deliberately deviates
 * from a bare tus server: instead of a bodyless `204`, it runs
 * `TusUploadCompletionService` and returns `200` with a small JSON body
 * (`{imageId, addStatus}`) -- a private extension a generic tus client
 * simply ignores, but the one this app's own future upload UI needs to
 * learn the new photo's id without a second round-trip.
 */
final readonly class TusUploadPatchController implements ControllerInterface
{
    private const string TUS_VERSION = '1.0.0';

    public function __construct(
        private AdminGuard $adminGuard,
        private CsrfGuard $csrfGuard,
        private CurrentUser $currentUser,
        private TusUploadStore $tusUploadStore,
        private TusUploadCompletionService $tusUploadCompletionService,
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

        if ($request->getHeaderLine('Content-Type') !== 'application/offset+octet-stream') {
            return $this->problem('Unsupported Media Type', 415, 'Content-Type must be application/offset+octet-stream.');
        }

        $routeArgs = $request->getAttribute('route_args');
        $id = is_array($routeArgs) && is_string($routeArgs['id'] ?? null) ? $routeArgs['id'] : '';

        $session = $this->tusUploadStore->findOwnedBy($id, $this->currentUser->get()->id->value);
        if (! $session instanceof TusUploadSession) {
            return $this->problem('Not Found', 404, 'Unknown upload.');
        }

        $offsetHeader = $request->getHeaderLine('Upload-Offset');
        if (! ctype_digit($offsetHeader)) {
            return $this->problem('Bad Request', 400, 'A numeric Upload-Offset header is required.');
        }

        $expectedOffset = (int) $offsetHeader;

        $stream = $request->getBody()
            ->detach();
        if ($stream === null) {
            return $this->problem('Internal Server Error', 500, 'Missing request body stream.');
        }

        $newOffset = $this->tusUploadStore->appendChunk($session->id, $expectedOffset, $stream);
        if (is_resource($stream)) {
            fclose($stream);
        }

        if ($newOffset === null) {
            return $this->problem('Conflict', 409, "Upload-Offset does not match the upload's real current offset.");
        }

        if ($newOffset < $session->uploadLength) {
            return ResponseFactory::raw('', [
                'Tus-Resumable' => self::TUS_VERSION,
                'Upload-Offset' => (string) $newOffset,
            ], 204);
        }

        $result = $this->tusUploadCompletionService->complete($session);
        $this->tusUploadStore->delete($session->id);

        if ($result instanceof ResponseInterface) {
            return $result->withHeader('Tus-Resumable', self::TUS_VERSION)
                ->withHeader('Upload-Offset', (string) $newOffset);
        }

        return ResponseFactory::json($result->toArray(), 200)
            ->withHeader('Tus-Resumable', self::TUS_VERSION)
            ->withHeader('Upload-Offset', (string) $newOffset);
    }

    private function problem(string $title, int $status, string $detail): ResponseInterface
    {
        return ResponseFactory::problem($title, $status, $detail)
            ->withHeader('Tus-Resumable', self::TUS_VERSION);
    }
}
