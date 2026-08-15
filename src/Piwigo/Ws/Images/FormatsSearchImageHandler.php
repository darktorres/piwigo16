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
use Piwigo\Core\StringHelper;
use Piwigo\Image\ImageService;
use Piwigo\Ws\Server;
use Piwigo\Ws\WsAction;

/**
 * `pwg.images.formats.searchImage` -- admin only. Checks, for each
 * candidate filename supplied by the client, whether a matching photo
 * already exists (by filename with known format extensions stripped)
 * and whether a format with that extension is already associated with
 * it.
 *
 * @since 13
 */
final readonly class FormatsSearchImageHandler implements WsAction
{
    public function __construct(
        private ImageService $imageService,
        private CurrentConfig $currentConfig,
        private CurrentLogger $currentLogger,
    ) {}

    /**
     * Result rows are genuinely polymorphic (status: 'not found'|'multiple'
     * carry no other key, status: 'found' adds image_id/format_exist), and
     * $candidates below is arbitrary client-supplied JSON.
     *
     * @param array<mixed> $params
     * @return array<int|string, array<string, mixed>>
     */
    #[Override]
    public function __invoke(array $params, Server $server): array
    {
        $input = FormatsSearchImageParams::fromArray($params);

        $logger = $this->currentLogger->get();

        $logger->debug('formatsSearchImage', 'WS', $params);

        $candidates = json_decode($input->filenameList, true);
        if (! is_array($candidates)) {
            $candidates = [];
        }
        /** @var array<int|string, mixed> $candidates */
        $unique_filenames_db = [];

        foreach ($this->imageService->getAllIdsAndFiles() as $row) {
            $filename_wo_ext = StringHelper::getFilenameWoExtension($row->file);
            @$unique_filenames_db[$filename_wo_ext][] = $row->id;
        }

        // we want "long" format extensions first to match "cmyk.jpg" before "jpg" for example
        // (kept as a local variable, not written back to $conf -- $conf is
        // reloaded from scratch on every request, so mutating it here
        // wouldn't persist anyway)
        $format_ext_list = $this->currentConfig->formatExtensions;
        usort($format_ext_list, static fn (string $a, string $b): int => strlen($b) - strlen($a));

        $format_db = [];
        foreach ($this->imageService->getAllImageIdsAndExts() as $row) {
            $format_image_id = $row->imageId;
            @$format_db[$format_image_id][] = $row->ext;
        }

        $result = [];

        foreach ($candidates as $format_external_id => $format_filename) {
            $candidate_filename_wo_ext = null;

            if (! is_string($format_filename)) {
                $result[$format_external_id] = [
                    'status' => 'not found',
                ];
                continue;
            }

            if ((bool) preg_match('/^(.*?)\.(' . implode('|', $format_ext_list) . ')$/', $format_filename, $matches)) {
                $candidate_filename_wo_ext = $matches[1];
            }

            if (! is_string($candidate_filename_wo_ext) || $candidate_filename_wo_ext === '') {
                $result[$format_external_id] = [
                    'status' => 'not found',
                ];
                continue;
            }

            if (isset($unique_filenames_db[$candidate_filename_wo_ext])) {
                if (count($unique_filenames_db[$candidate_filename_wo_ext]) > 1) {
                    $result[$format_external_id] = [
                        'status' => 'multiple',
                    ];
                    continue;
                }
                $img_id = $unique_filenames_db[$candidate_filename_wo_ext][0];
                $mult_form = false;
                if (isset($format_db[$img_id])) {
                    $format_ext = pathinfo($format_filename, PATHINFO_EXTENSION);
                    if (array_search($format_ext, array_map(strval(...), array_filter($format_db[$img_id], is_scalar(...))), true) !== false) {
                        $mult_form = true;
                    }
                }
                $result[$format_external_id] = [
                    'status' => 'found',
                    'image_id' => $img_id,
                    'format_exist' => $mult_form,
                ];
                continue;
            }

            $result[$format_external_id] = [
                'status' => 'not found',
            ];
        }

        return $result;
    }
}
