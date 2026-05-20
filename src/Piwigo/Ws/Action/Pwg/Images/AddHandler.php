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
        $input             = AddParams::fromArray($params);
        $pImageId          = $input->imageId;
        $pOriginalSum      = $input->originalSum;
        $pOriginalFilename = $input->originalFilename;
        $pLevel            = $input->level;
        if ($pImageId > 0 && !$this->imageRepository->existsById($pImageId)) {
            return new PwgError(404, 'image_id not found');
        }
        if ($input->checkUniqueness) {
            $counter = 0;
            if (Config::uniquenessMode() === 'md5sum') {
                $counter = $this->imageRepository->countByWhereFragment('md5sum = ?', [$pOriginalSum], [ParameterType::STRING]);
            } elseif (Config::uniquenessMode() === 'filename') {
                $counter = $this->imageRepository->countByWhereFragment('file = ?', [$pOriginalFilename ?? ''], [ParameterType::STRING]);
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
        foreach (['name' => $input->name, 'author' => $input->author, 'comment' => $input->comment, 'date_creation' => $input->dateCreation] as $key => $val) {
            if ($val !== null) {
                $update[$key] = $val;
            }
        }
        if (count($update) > 0) {
            $this->imageRepository->updateById($imageId, $update);
        }
        $urlParams = ['image_id' => $imageId];
        if ($input->categories !== null) {
            $this->categoryAdminService->addImageCategoryRelations($imageId, $input->categories);
            if (preg_match('/^\d+/', $input->categories, $matches)) {
                $category              = $this->categoryRepository->findCategoryById((int) $matches[0]);
                $urlParams['section']  = 'categories';
                $urlParams['category'] = $category?->toRow();
            }
        }
        if ($input->tagIds !== null && $input->tagIds !== '') {
            $this->tagAdminService->setTags(explode(',', $input->tagIds), $imageId);
        }
        $this->userAdminService->invalidateUserCache();
        return ['image_id' => $imageId, 'url' => $this->urlService->makePictureUrl($urlParams)];
    }
}
