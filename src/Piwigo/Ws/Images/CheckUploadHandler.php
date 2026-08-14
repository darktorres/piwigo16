<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Ws\Images;

use Piwigo\Admin\Upload\UploadService;
use Piwigo\Ws\Server;
use Piwigo\Ws\WsAction;

/**
 * `pwg.images.checkUpload` -- checks if Piwigo is ready for upload.
 */
final readonly class CheckUploadHandler implements WsAction
{
    public function __construct(
        private UploadService $uploadService,
    ) {}

    /**
     * @param array<mixed> $params this method is registered with a null
     *   signature (zero registered params) -- $params is the raw, entirely
     *   unvalidated request array, but the body doesn't read it.
     * @return array{message: ?string, ready_for_upload: bool}
     */
    public function __invoke(array $params, Server $server): array
    {
        $ret = [];
        $ret['message'] = $this->uploadService->readyForUploadMessage();
        $ret['ready_for_upload'] = true;
        if (! in_array($ret['message'], [null, ''], true)) {
            $ret['ready_for_upload'] = false;
        }

        return $ret;
    }
}
