<?php

declare(strict_types=1);

namespace Piwigo\Ws\Action\Pwg\Images;

use Doctrine\DBAL\ParameterType;
use Piwigo\Admin\Category\CategoryAdminService;
use Piwigo\Admin\Tag\TagAdminService;
use Piwigo\Admin\Upload\UploadService;
use Piwigo\Admin\Users\UserAdminService;
use Piwigo\Category\CategoryRepository;
use Piwigo\Config\Config;
use Piwigo\Image\ImageRepository;
use Piwigo\Url\UrlService;
use Piwigo\Ws\PwgError;
use Piwigo\Ws\PwgServer;
use Piwigo\Ws\WsAction;

/** `pwg.images.add` — register a chunked-upload as a new (or replaced) image. */
final readonly class AddHandler implements WsAction
{
    public function __construct(
        private CategoryAdminService $categoryAdminService,
        private CategoryRepository $categoryRepository,
        private ImageRepository $imageRepository,
        private TagAdminService $tagAdminService,
        private UploadService $uploadService,
        private UrlService $urlService,
        private UserAdminService $userAdminService,
    ) {
    }

    /**
     * @param  array<mixed> $params
     * @return array<string, mixed>|PwgError
     */
    #[\Override]
    public function __invoke(array $params, PwgServer $server): PwgError|array
    {
        $pImageId          = is_numeric($params['image_id']) ? (int) $params['image_id'] : 0;
        $pOriginalSum      = is_string($params['original_sum'] ?? null) ? $params['original_sum'] : '';
        $pOriginalFilename = is_scalar($params['original_filename']) ? (string) $params['original_filename'] : null;
        $pLevel            = isset($params['level']) && is_numeric($params['level']) ? (int) $params['level'] : null;
        if ($pImageId > 0 && !$this->imageRepository->existsById($pImageId)) {
            return new PwgError(404, 'image_id not found');
        }
        if ($params['check_uniqueness']) {
            $counter = 0;
            if (Config::uniquenessMode() === 'md5sum') {
                $counter = $this->imageRepository->countByWhereFragment('md5sum = ?', [$pOriginalSum], [ParameterType::STRING]);
            } elseif (Config::uniquenessMode() === 'filename') {
                $counter = $this->imageRepository->countByWhereFragment('file = ?', [is_string($params['original_filename'] ?? null) ? $params['original_filename'] : ''], [ParameterType::STRING]);
            }
            if ($counter !== 0) {
                return new PwgError(500, 'file already exists');
            }
        }
        $filePath   = Config::uploadDir() . '/buffer/' . $pOriginalSum . '-original';
        $mergeError = $this->uploadService->mergeChunks($filePath, $pOriginalSum, 'file');
        if ($mergeError !== null) {
            return $mergeError;
        }
        chmod($filePath, Config::chmodValue() & 0o666);
        $imageId = $this->uploadService->addUploadedFile($filePath, $pOriginalFilename, null, $pLevel, $pImageId > 0 ? $pImageId : null, $pOriginalSum);
        $update  = [];
        foreach (['name', 'author', 'comment', 'date_creation'] as $key) {
            if (isset($params[$key])) {
                $update[$key] = $params[$key];
            }
        }
        if (count($update) > 0) {
            $this->imageRepository->updateById($imageId, $update);
        }
        $urlParams = ['image_id' => $imageId];
        if (isset($params['categories'])) {
            $pCategoriesStr = is_string($params['categories']) ? $params['categories'] : '';
            $this->categoryAdminService->addImageCategoryRelations($imageId, $pCategoriesStr);
            if (preg_match('/^\d+/', $pCategoriesStr, $matches)) {
                $category              = $this->categoryRepository->findCategoryById((int) $matches[0]);
                $urlParams['section']  = 'categories';
                $urlParams['category'] = $category?->toRow();
            }
        }
        if (isset($params['tag_ids']) && $params['tag_ids'] !== '') {
            $this->tagAdminService->setTags(explode(',', is_string($params['tag_ids']) ? $params['tag_ids'] : ''), $imageId);
        }
        $this->userAdminService->invalidateUserCache();
        return ['image_id' => $imageId, 'url' => $this->urlService->makePictureUrl($urlParams)];
    }
}
