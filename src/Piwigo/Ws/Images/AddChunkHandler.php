<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Ws\Images;

use Override;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\CurrentLogger;
use Piwigo\Core\FilesystemHelper;
use Piwigo\Core\Paths;
use Piwigo\Ws\WsAction;
use Piwigo\Ws\WsError;
use Piwigo\Ws\WsErrorResponse;

/**
 * `pwg.images.addChunk` -- admin only. Adds a chunk of a file.
 */
final readonly class AddChunkHandler implements WsAction
{
    public function __construct(
        private CurrentConfig $currentConfig,
        private CurrentLogger $currentLogger,
        private Paths $paths,
    ) {}

    /**
     * @param array<mixed> $params
     */
    #[Override]
    public function __invoke(array $params): ?WsErrorResponse
    {
        $input = AddChunkParams::fromArray($params);

        // SEC finding 3: neither field carries a WsParamType, so
        // Server::invoke() applies no coercion beyond rejecting arrays --
        // these are the only guards standing between $input->originalSum/
        // $input->type and the filesystem path built below. Without them,
        // e.g. original_sum = "../../../.." escapes the buffer directory
        // (the "-<type>-<NNNNN>.block" suffix stays forced, so this is an
        // arbitrary-directory write with a forced extension, not
        // arbitrary-file overwrite).
        if (! (bool) preg_match('/^[a-fA-F0-9]{32}$/', $input->originalSum)) {
            return new WsErrorResponse(WsError::InvalidParam->value, 'Invalid original_sum');
        }
        if (! in_array($input->type, ['file', 'high', 'thumb'], true)) {
            return new WsErrorResponse(WsError::InvalidParam->value, 'Invalid type');
        }

        $logger = $this->currentLogger->get();

        foreach ($params as $param_key => $param_value) {
            if ($param_key === 'data') {
                continue;
            }

            $logger->debug(sprintf(
                '[ws_images_add_chunk] input param "%s" : "%s"',
                $param_key,
                is_scalar($param_value) ? (string) $param_value : 'NULL'
            ), 'WS');
        }

        $upload_dir_conf = $this->paths->root . $this->currentConfig->uploadDir;
        $upload_dir = $upload_dir_conf . '/buffer';

        // create the upload directory tree if not exists
        if (! FilesystemHelper::mkgetdir($upload_dir, $this->currentConfig, FilesystemHelper::MKGETDIR_DEFAULT & ~FilesystemHelper::MKGETDIR_DIE_ON_ERROR)) {
            return new WsErrorResponse(500, 'error during buffer directory creation');
        }

        $filename = sprintf(
            '%s-%s-%05u.block',
            $input->originalSum,
            $input->type,
            (int) $input->position
        );

        $logger->debug('[ws_images_add_chunk] data length : ' . strlen($input->data), 'WS');

        $decoded_data = base64_decode($input->data, true);
        $bytes_written = $decoded_data === false ? false : file_put_contents(
            $upload_dir . '/' . $filename,
            $decoded_data
        );

        if ($bytes_written === false) {
            return new WsErrorResponse(
                500,
                'an error has occured while writting chunk ' . $input->position . ' for ' . $input->type
            );
        }

        return null;
    }
}
