<?php

declare(strict_types=1);

namespace Piwigo\Controller\Api\Uploads;

use Exception;
use Override;
use Piwigo\Config\CurrentConfig;
use Piwigo\Http\AdminGuard;
use Piwigo\Http\ControllerInterface;
use Piwigo\Http\ResponseFactory;
use Piwigo\Image\ImageService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `GET /api/v1/uploads/duplicates?md5sums=...&filenames=...` --
 * `pwg.images.exist`'s real replacement, admin only. A caller checks
 * candidate photos for duplicates before starting an upload; only the
 * query param matching `CurrentConfig::uniquenessMode` is consulted,
 * same as its WS predecessor.
 */
final readonly class UploadDuplicatesController implements ControllerInterface
{
    private const string SPLIT_PATTERN = '/[\s,;\|]/';

    public function __construct(
        private AdminGuard $adminGuard,
        private CurrentConfig $currentConfig,
        private ImageService $imageService,
    ) {}

    #[Override]
    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $denied = $this->adminGuard->check();
        if ($denied instanceof ResponseInterface) {
            return $denied;
        }

        $query = $request->getQueryParams();
        $result = [];

        if ($this->currentConfig->uniquenessMode === 'md5sum') {
            $md5sums = self::split($query['md5sums'] ?? null);
            $idOfMd5 = $this->imageService->getIdsByMd5sums($md5sums);
            foreach ($md5sums as $md5sum) {
                $result[$md5sum] = $idOfMd5[$md5sum] ?? null;
            }
        } elseif ($this->currentConfig->uniquenessMode === 'filename') {
            $filenames = self::split($query['filenames'] ?? null);
            $idOfFilename = $this->imageService->getIdsByFilenames($filenames);
            foreach ($filenames as $filename) {
                $result[$filename] = $idOfFilename[$filename] ?? null;
            }
        }

        return ResponseFactory::json($result);
    }

    /**
     * @return list<string>
     */
    private static function split(mixed $raw): array
    {
        $list = preg_split(self::SPLIT_PATTERN, is_string($raw) ? $raw : '', -1, PREG_SPLIT_NO_EMPTY);
        if ($list === false) {
            throw new Exception('UploadDuplicatesController: preg_split() failed');
        }

        return $list;
    }
}
