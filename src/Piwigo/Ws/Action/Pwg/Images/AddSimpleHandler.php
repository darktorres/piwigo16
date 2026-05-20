<?php

declare(strict_types=1);

namespace Piwigo\Ws\Action\Pwg\Images;

use Piwigo\Admin\Metadata\MetadataAdminService;
use Piwigo\Admin\Tag\TagAdminService;
use Piwigo\Admin\Upload\UploadService;
use Piwigo\Category\CategoryRepository;
use Piwigo\Core\LoggerRegistry;
use Piwigo\Image\ImageRepository;
use Piwigo\Url\UrlService;
use Piwigo\Ws\PwgError;
use Piwigo\Ws\PwgServer;
use Piwigo\Ws\WsAction;

/** `pwg.images.addSimple` — multipart-form image upload (used by jUpload-style clients). */
final readonly class AddSimpleHandler implements WsAction
{
    public function __construct(
        private CategoryRepository $categoryRepository,
        private ImageRepository $imageRepository,
        private MetadataAdminService $metadataAdminService,
        private TagAdminService $tagAdminService,
        private UploadService $uploadService,
        private UrlService $urlService,
    ) {
    }

    /**
     * @param  array<mixed> $params
     * @return array<string, mixed>|PwgError
     */
    #[\Override]
    public function __invoke(array $params, PwgServer $server): PwgError|array
    {
        $logger = LoggerRegistry::current();
        if (!isset($_FILES['image'])) {
            return new PwgError(405, 'The image (file) is missing');
        }
        /** @var array<string, mixed> $filesImage */
        $filesImage      = $_FILES['image'];
        $filesImageError = is_int($filesImage['error'] ?? null) ? $filesImage['error'] : 0;
        if ($filesImageError !== 0) {
            $message = match ($filesImageError) {
                UPLOAD_ERR_INI_SIZE   => 'The uploaded file exceeds the upload_max_filesize directive in php.ini.',
                UPLOAD_ERR_FORM_SIZE  => 'The uploaded file exceeds the MAX_FILE_SIZE directive.',
                UPLOAD_ERR_PARTIAL    => 'The uploaded file was only partially uploaded.',
                UPLOAD_ERR_NO_FILE    => 'No file was uploaded.',
                UPLOAD_ERR_NO_TMP_DIR => 'Missing a temporary folder.',
                UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk.',
                UPLOAD_ERR_EXTENSION  => 'A PHP extension stopped the file upload.',
                default               => "Error number {$filesImageError} occurred while uploading.",
            };
            $logger->error(__FUNCTION__ . ' ' . $message);
            return new PwgError(500, $message);
        }
        $input       = AddSimpleParams::fromArray($params);
        $pImageIdAs  = $input->imageId;
        $pCategoryAs = $input->categoryIds;
        if ($pImageIdAs > 0 && !$this->imageRepository->existsById($pImageIdAs)) {
            return new PwgError(404, 'image_id not found');
        }
        $filesTmpRaw = $filesImage['tmp_name'] ?? null;
        $filesTmp    = is_string($filesTmpRaw) ? $filesTmpRaw : '';
        $filesName   = is_string($filesImage['name'] ?? null) ? $filesImage['name'] : null;
        $imageId     = $this->uploadService->addUploadedFile($filesTmp, $filesName, $pCategoryAs, 8, $pImageIdAs > 0 ? $pImageIdAs : null);
        $update      = [];
        foreach (['name' => $input->name, 'author' => $input->author, 'comment' => $input->comment, 'level' => $input->level, 'date_creation' => $input->dateCreation] as $key => $val) {
            if ($val !== null) {
                $update[$key] = $val;
            }
        }
        if (count($update) > 0) {
            $this->imageRepository->updateById($imageId, $update);
        }
        if ($input->tags !== null && !empty($input->tags)) {
            $tagIds = [];
            if (is_array($input->tags)) {
                foreach ($input->tags as $tagName) {
                    $tagIds[] = $this->tagAdminService->tagIdFromTagName(is_scalar($tagName) ? (string) $tagName : '');
                }
            } else {
                $tagNamesSplit = preg_split('~(?<!\\\),~', is_string($input->tags) ? $input->tags : '');
                $tagNames      = $tagNamesSplit !== false ? $tagNamesSplit : [];
                foreach ($tagNames as $tagName) {
                    $tagIds[] = $this->tagAdminService->tagIdFromTagName(preg_replace('#\\\\*,#', ',', $tagName) ?? '');
                }
            }
            $this->tagAdminService->addTags($tagIds, [$imageId]);
        }
        $urlParams = ['image_id' => $imageId];
        if (!empty($pCategoryAs)) {
            $firstCatId            = $pCategoryAs[0];
            $category              = $this->categoryRepository->findCategoryById($firstCatId);
            $urlParams['section']  = 'categories';
            $urlParams['category'] = $category?->toRow();
        }
        $this->metadataAdminService->syncMetadata([$imageId]);
        return ['image_id' => $imageId, 'url' => $this->urlService->makePictureUrl($urlParams)];
    }
}
