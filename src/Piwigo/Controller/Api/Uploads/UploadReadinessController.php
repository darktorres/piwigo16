<?php

declare(strict_types=1);

namespace Piwigo\Controller\Api\Uploads;

use Override;
use Piwigo\Admin\Upload\UploadService;
use Piwigo\Http\AdminGuard;
use Piwigo\Http\ControllerInterface;
use Piwigo\Http\ResponseFactory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `GET /api/v1/uploads/readiness` -- `pwg.images.checkUpload`'s real
 * replacement, admin only (`requiresAuth: true` on the WS side).
 */
final readonly class UploadReadinessController implements ControllerInterface
{
    public function __construct(
        private AdminGuard $adminGuard,
        private UploadService $uploadService,
    ) {}

    #[Override]
    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $denied = $this->adminGuard->check();
        if ($denied instanceof ResponseInterface) {
            return $denied;
        }

        $message = $this->uploadService->readyForUploadMessage();

        return ResponseFactory::json([
            'message' => $message,
            'readyForUpload' => in_array($message, [null, ''], true),
        ]);
    }
}
