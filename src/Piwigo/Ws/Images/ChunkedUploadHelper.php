<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Ws\Images;

use Piwigo\Config\CurrentConfig;
use Piwigo\Core\CurrentLogger;
use Piwigo\Core\Paths;
use Piwigo\Ws\WsErrorResponse;

/**
 * Merges/removes `pwg.images.addChunk`-uploaded chunk files -- shared by
 * `AddFileHandler` and `AddHandler` (the god-class `Images::mergeChunks()`/
 * `removeChunks()` private helpers both used), extracted into its own
 * service since it's no longer possible for two separate `WsAction`
 * classes to share a private method on a single god-class.
 */
final readonly class ChunkedUploadHelper
{
    public function __construct(
        private CurrentConfig $currentConfig,
        private CurrentLogger $currentLogger,
        private Paths $paths,
    ) {}

    /**
     * Merge chunks added by pwg.images.addChunk
     */
    public function mergeChunks(string $output_filepath, string $original_sum, string $type): ?WsErrorResponse
    {
        $logger = $this->currentLogger->get();

        $logger->debug('[merge_chunks] input parameter $output_filepath : ' . $output_filepath, 'WS');

        if (is_file($output_filepath)) {
            unlink($output_filepath);

            if (is_file($output_filepath)) {
                return new WsErrorResponse(500, '[merge_chunks] error while trying to remove existing ' . $output_filepath);
            }
        }

        $upload_dir_conf = $this->paths->root . $this->currentConfig->uploadDir;
        $upload_dir = $upload_dir_conf . '/buffer';
        $pattern = '/' . $original_sum . '-' . $type . '/';
        $chunks = [];

        if ((bool) ($handle = opendir($upload_dir))) {
            while (false !== ($file = readdir($handle))) {
                if ((bool) preg_match($pattern, $file)) {
                    $logger->debug($file, 'WS');
                    $chunks[] = $upload_dir . '/' . $file;
                }
            }
            closedir($handle);
        }

        sort($chunks);

        if (function_exists('memory_get_usage')) {
            $logger->debug('[merge_chunks] memory_get_usage before loading chunks: ' . memory_get_usage(), 'WS');
        }

        $i = 0;

        foreach ($chunks as $chunk) {
            $string = file_get_contents($chunk);

            if (function_exists('memory_get_usage')) {
                $logger->debug('[merge_chunks] memory_get_usage on chunk ' . ++$i . ': ' . memory_get_usage(), 'WS');
            }

            if ($string === false || ! (bool) file_put_contents($output_filepath, $string, FILE_APPEND)) {
                return new WsErrorResponse(500, '[merge_chunks] error while writting chunks for ' . $output_filepath);
            }

            unlink($chunk);
        }

        if (function_exists('memory_get_usage')) {
            $logger->debug('[merge_chunks] memory_get_usage after loading chunks: ' . memory_get_usage(), 'WS');
        }

        return null;
    }

    /**
     * Deletes chunks added with pwg.images.addChunk
     * Function introduced for Piwigo 2.4 and the new "multiple size"
     * (derivatives) feature. As we only need the biggest sent photo as
     * "original", we remove chunks for smaller sizes. We can't make it earlier
     * in ws_images_add_chunk because at this moment we don't know which $type
     * will be the biggest (we could remove the thumb, but let's use the same
     * algorithm)
     */
    public function removeChunks(string $original_sum, string $type): void
    {
        $upload_dir_conf = $this->paths->root . $this->currentConfig->uploadDir;
        $upload_dir = $upload_dir_conf . '/buffer';
        $pattern = '/' . $original_sum . '-' . $type . '/';
        $chunks = [];

        if ((bool) ($handle = opendir($upload_dir))) {
            while (false !== ($file = readdir($handle))) {
                if ((bool) preg_match($pattern, $file)) {
                    $chunks[] = $upload_dir . '/' . $file;
                }
            }
            closedir($handle);
        }

        foreach ($chunks as $chunk) {
            unlink($chunk);
        }
    }
}
