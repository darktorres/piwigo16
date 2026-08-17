<?php

declare(strict_types=1);

namespace Piwigo\Controller\Api\Images;

use Override;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\StringHelper;
use Piwigo\Http\AdminGuard;
use Piwigo\Http\ControllerInterface;
use Piwigo\Http\JsonBody;
use Piwigo\Http\ResponseFactory;
use Piwigo\Image\ImageService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `POST /api/v1/images/formats/actions/search` --
 * `pwg.images.formats.searchImage`'s real replacement, admin only
 * (read-only, no CSRF needed -- POST is a transport choice here, not
 * because it mutates anything). Checks, for each candidate filename supplied, whether a
 * matching photo already exists (by filename with known format
 * extensions stripped) and whether a format with that extension is
 * already associated with it.
 */
final readonly class ImageFormatSearchController implements ControllerInterface
{
    public function __construct(
        private AdminGuard $adminGuard,
        private ImageService $imageService,
        private CurrentConfig $currentConfig,
    ) {}

    #[Override]
    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $denied = $this->adminGuard->check();
        if ($denied instanceof ResponseInterface) {
            return $denied;
        }

        $input = ImageFormatSearchInput::fromArray(JsonBody::decode($request));

        $uniqueFilenamesDb = [];
        foreach ($this->imageService->getAllIdsAndFiles() as $row) {
            $filenameWoExt = StringHelper::getFilenameWoExtension($row->file);
            $uniqueFilenamesDb[$filenameWoExt][] = $row->id;
        }

        $formatExtList = $this->currentConfig->formatExtensions;
        usort($formatExtList, static fn (string $a, string $b): int => strlen($b) - strlen($a));

        $formatDb = [];
        foreach ($this->imageService->getAllImageIdsAndExts() as $row) {
            $formatDb[$row->imageId][] = $row->ext;
        }

        $result = [];
        foreach ($input->filenames as $uniqueId => $filename) {
            $candidateFilenameWoExt = null;
            if ($formatExtList !== [] && preg_match('/^(.*?)\.(' . implode('|', $formatExtList) . ')$/', $filename, $matches) === 1) {
                $candidateFilenameWoExt = $matches[1];
            }

            if ($candidateFilenameWoExt === null || $candidateFilenameWoExt === '') {
                $result[$uniqueId] = [
                    'status' => 'not found',
                ];
                continue;
            }

            if (! isset($uniqueFilenamesDb[$candidateFilenameWoExt])) {
                $result[$uniqueId] = [
                    'status' => 'not found',
                ];
                continue;
            }

            if (count($uniqueFilenamesDb[$candidateFilenameWoExt]) > 1) {
                $result[$uniqueId] = [
                    'status' => 'multiple',
                ];
                continue;
            }

            $imageId = $uniqueFilenamesDb[$candidateFilenameWoExt][0];
            $formatExt = pathinfo($filename, PATHINFO_EXTENSION);
            $formatExists = isset($formatDb[$imageId]) && in_array($formatExt, $formatDb[$imageId], true);

            $result[$uniqueId] = [
                'status' => 'found',
                'imageId' => $imageId,
                'formatExists' => $formatExists,
            ];
        }

        return ResponseFactory::json([
            'results' => $result,
        ]);
    }
}
