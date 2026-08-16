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
use Piwigo\Cache\PermissionCacheInvalidator;
use Piwigo\Core\Paths;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Image\ImagePathHelper;
use Piwigo\Image\ImageService;
use Piwigo\Ws\WsAction;
use Piwigo\Ws\WsCsrfGuard;
use Piwigo\Ws\WsErrorResponse;

/**
 * `pwg.images.formats.delete` -- admin only. Removes a format from the
 * database and the file system.
 *
 * @since 13
 */
final readonly class FormatsDeleteHandler implements WsAction
{
    public function __construct(
        private ImageService $imageService,
        private UrlServiceInterface $urlService,
        private Paths $paths,
        private WsCsrfGuard $wsCsrfGuard,
    ) {}

    /**
     * @param array<mixed> $params
     */
    #[Override]
    public function __invoke(array $params): WsErrorResponse|bool
    {
        $input = FormatsDeleteParams::fromArray($params);

        $csrfError = $this->wsCsrfGuard->checkSecurityToken($input->pwgToken);
        if ($csrfError instanceof WsErrorResponse) {
            return $csrfError;
        }

        $format_ids = $input->formatIds;

        $image_ids = [];
        $formats_of = [];

        // Delete physical file
        $ok = true;

        foreach ($this->imageService->getImageIdsAndExtsByFormatIds($format_ids) as $row) {
            if (! isset($formats_of[$row->imageId])) {
                $image_ids[] = $row->imageId;
                $formats_of[$row->imageId] = [];
            }

            $formats_of[$row->imageId][] = $row->ext;
        }

        if (count($image_ids) === 0) {
            return new WsErrorResponse(404, 'No format found for the id(s) given');
        }

        $urlService = $this->urlService;
        foreach ($this->imageService->getPathsForFileDeletion($image_ids) as $image_row) {
            if ($urlService->urlIsRemote($image_row->path)) {
                continue;
            }

            $files = [];
            $image_path = ImagePathHelper::getElementPath($image_row->toArray(), $urlService, $this->paths);

            if (isset($formats_of[$image_row->id])) {
                foreach ($formats_of[$image_row->id] as $format_ext) {
                    $files[] = ImagePathHelper::originalToFormat($image_path, $format_ext);
                }
            }

            foreach ($files as $path) {
                if (is_file($path) and ! unlink($path)) {
                    $ok = false;
                    trigger_error('"' . $path . '" cannot be removed', E_USER_WARNING);
                    break;
                }
            }
        }

        // Delete format in the database
        $this->imageService->deleteFormatsByIds($format_ids);

        PermissionCacheInvalidator::invalidate();

        return $ok;
    }
}
