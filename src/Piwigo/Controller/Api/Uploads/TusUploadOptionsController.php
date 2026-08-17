<?php

declare(strict_types=1);

namespace Piwigo\Controller\Api\Uploads;

use Override;
use Piwigo\Admin\Upload\UploadService;
use Piwigo\Http\ControllerInterface;
use Piwigo\Http\ResponseFactory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `OPTIONS /api/v1/uploads` -- tus 1.0.0 protocol discovery. Deliberately
 * ungated (unlike every other endpoint in this family): a real tus
 * client library probes this before it has any admin session to send,
 * and the response reveals nothing beyond this server's supported
 * protocol version/extensions/size limit.
 */
final readonly class TusUploadOptionsController implements ControllerInterface
{
    public function __construct(
        private UploadService $uploadService,
    ) {}

    #[Override]
    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $headers = [
            'Tus-Resumable' => '1.0.0',
            'Tus-Version' => '1.0.0',
            'Tus-Extension' => 'creation,termination',
        ];

        $maxSize = $this->uploadService->getIniSize('upload_max_filesize');
        if (is_int($maxSize)) {
            $headers['Tus-Max-Size'] = (string) $maxSize;
        }

        return ResponseFactory::raw('', $headers, 204);
    }
}
