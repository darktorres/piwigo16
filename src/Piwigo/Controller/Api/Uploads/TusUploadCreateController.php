<?php

declare(strict_types=1);

namespace Piwigo\Controller\Api\Uploads;

use Override;
use Piwigo\Admin\Upload\UploadService;
use Piwigo\Common\ValueObject\ImageId;
use Piwigo\Http\AdminGuard;
use Piwigo\Http\ControllerInterface;
use Piwigo\Http\CsrfGuard;
use Piwigo\Http\ResponseFactory;
use Piwigo\Image\ImageService;
use Piwigo\Users\CurrentUser;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `POST /api/v1/uploads` -- the tus 1.0.0 Creation extension, replacing
 * `pwg.images.{addChunk,add,addFile,addSimple,upload,uploadAsync}`'s
 * base64/multipart chunk protocols with a single binary offset-based one
 * (see `TusUploadPatchController`/`TusUploadCompletionService` for the
 * rest of the flow). Admin + CSRF (`requiresAuth: true` on every one of
 * those WS predecessors).
 *
 * `imageId`/`formatOf` existence is checked here, before any byte is
 * uploaded, rather than only at completion time the way the WS handlers
 * did -- cheap up front, and it means a caller never uploads a whole
 * file only to learn its target was invalid all along.
 */
final readonly class TusUploadCreateController implements ControllerInterface
{
    private const string TUS_VERSION = '1.0.0';

    public function __construct(
        private AdminGuard $adminGuard,
        private CsrfGuard $csrfGuard,
        private CurrentUser $currentUser,
        private TusUploadStore $tusUploadStore,
        private UploadService $uploadService,
        private ImageService $imageService,
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

        $uploadLengthHeader = $request->getHeaderLine('Upload-Length');
        if (! ctype_digit($uploadLengthHeader) || (int) $uploadLengthHeader <= 0) {
            return $this->problem('Bad Request', 400, 'A positive Upload-Length header is required (deferred length is not supported).');
        }

        $uploadLength = (int) $uploadLengthHeader;

        $maxSize = $this->uploadService->getIniSize('upload_max_filesize');
        if (is_int($maxSize) && $uploadLength > $maxSize) {
            return $this->problem('Payload Too Large', 413, 'Upload-Length exceeds the upload_max_filesize limit.');
        }

        $metadata = TusMetadataHeader::parse($request->getHeaderLine('Upload-Metadata'));
        if (($metadata['filename'] ?? '') === '') {
            return $this->problem('Bad Request', 400, 'Upload-Metadata must include a non-empty "filename" entry.');
        }

        if (isset($metadata['imageId']) && isset($metadata['formatOf'])) {
            return $this->problem('Bad Request', 400, 'imageId and formatOf are mutually exclusive.');
        }

        foreach (['imageId', 'formatOf'] as $targetKey) {
            if (! isset($metadata[$targetKey]) || ! is_numeric($metadata[$targetKey])) {
                continue;
            }

            if (! $this->imageService->existsById(ImageId::from((int) $metadata[$targetKey]))) {
                return $this->problem('Not Found', 404, $targetKey . ' does not match an existing image.');
            }
        }

        $session = $this->tusUploadStore->create($uploadLength, $this->currentUser->get()->id->value, $metadata);
        if (! $session instanceof TusUploadSession) {
            return $this->problem('Internal Server Error', 500, 'Could not create the upload buffer.');
        }

        return ResponseFactory::raw('', [
            'Tus-Resumable' => self::TUS_VERSION,
            'Location' => '/api/v1/uploads/' . $session->id,
            'Upload-Offset' => '0',
        ], 201);
    }

    private function problem(string $title, int $status, string $detail): ResponseInterface
    {
        return ResponseFactory::problem($title, $status, $detail)
            ->withHeader('Tus-Resumable', self::TUS_VERSION);
    }
}
