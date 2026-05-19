<?php

declare(strict_types=1);

namespace Piwigo\Ws\Action\Pwg\Images;

use Piwigo\Admin\Upload\UploadService;
use Piwigo\Ws\PwgServer;
use Piwigo\Ws\WsAction;

/** `pwg.images.checkUpload` — readiness probe for the upload pipeline. */
final readonly class CheckUploadHandler implements WsAction
{
    public function __construct(
        private UploadService $uploadService,
    ) {
    }

    /**
     * @param  array<mixed> $params
     * @return array<string, mixed>
     */
    public function __invoke(array $params, PwgServer $server): array
    {
        $ret = [];
        $ret['message']        = $this->uploadService->readyForUploadMessage();
        $ret['ready_for_upload'] = ($ret['message'] === null || $ret['message'] === '');
        return $ret;
    }
}
