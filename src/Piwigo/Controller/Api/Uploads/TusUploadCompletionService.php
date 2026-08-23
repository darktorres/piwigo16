<?php

declare(strict_types=1);

namespace Piwigo\Controller\Api\Uploads;

use Piwigo\Admin\Upload\UnsupportedMediaTypeException;
use Piwigo\Admin\Upload\UploadService;
use Piwigo\Cache\PermissionCacheInvalidator;
use Piwigo\Common\ValueObject\ImageId;
use Piwigo\Common\ValueObject\TagId;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Http\ResponseFactory;
use Piwigo\Image\ImageRepository;
use Piwigo\Image\ImageService;
use Piwigo\Image\Projection\Image;
use Piwigo\Tag\TagService;
use Psr\Http\Message\ResponseInterface;

/**
 * The tus completion step -- runs once a `PATCH` brings an upload's
 * offset up to its full `uploadLength`. A plain photo add/replace calls
 * `UploadService::addUploadedFile()` (tags, descriptive fields,
 * permission cache invalidation); a `formatOf` upload calls
 * `UploadService::addFormat()`. Deliberately minimal on success --
 * `imageId`/`addStatus` only; a client fetches `GET /api/v1/images/{id}`
 * for the rest.
 */
final readonly class TusUploadCompletionService
{
    public function __construct(
        private UploadService $uploadService,
        private ImageService $imageService,
        private ImageRepository $imageRepository,
        private TagService $tagService,
        private CurrentConfig $currentConfig,
        private UrlServiceInterface $urlService,
        private TusUploadStore $tusUploadStore,
    ) {}

    public function complete(TusUploadSession $session): ResponseInterface|TusUploadCompletionResult
    {
        $dataFilePath = $this->tusUploadStore->dataFilePath($session->id);

        if ($session->formatOf !== null) {
            return $this->completeFormat($session, $dataFilePath);
        }

        return $this->completePhoto($session, $dataFilePath);
    }

    private function completeFormat(TusUploadSession $session, string $dataFilePath): ResponseInterface|TusUploadCompletionResult
    {
        if (! $this->currentConfig->isFormatsEnabled) {
            return ResponseFactory::problem('Forbidden', 403, 'Formats are disabled.');
        }

        $formatExt = null;
        if (preg_match('/\.(' . implode('|', $this->currentConfig->formatExtensions) . ')$/i', $session->filename, $matches) === 1) {
            $formatExt = $matches[1];
        }

        if ($formatExt === null) {
            return ResponseFactory::problem(
                'Bad Request',
                400,
                'Unexpected format extension of file "' . $session->filename . '" (authorized extensions: ' . implode(', ', $this->currentConfig->formatExtensions) . ').'
            );
        }

        $formatOfId = ImageId::tryFrom($session->formatOf ?? 0);
        $imageRow = $formatOfId instanceof ImageId ? $this->imageRepository->findById($formatOfId) : null;
        if (! $imageRow instanceof Image) {
            return ResponseFactory::problem('Not Found', 404, 'formatOf image not found.');
        }

        $addStatus = $this->uploadService->addFormat($dataFilePath, $formatExt, $imageRow->id->value);

        return new TusUploadCompletionResult(
            imageId: $imageRow->id->value,
            addStatus: $addStatus,
        );
    }

    private function completePhoto(TusUploadSession $session, string $dataFilePath): ResponseInterface|TusUploadCompletionResult
    {
        try {
            $imageId = $this->uploadService->addUploadedFile(
                $dataFilePath,
                $this->urlService,
                $session->filename,
                $session->categoryIds,
                $session->level,
                $session->imageId,
                null,
            );
        } catch (UnsupportedMediaTypeException $e) {
            return ResponseFactory::problem('Unsupported Media Type', 415, $e->getMessage());
        }

        if ($session->tagIds !== []) {
            $this->tagService->setTags(
                array_values(array_filter(array_map(TagId::tryFrom(...), $session->tagIds))),
                $imageId,
                $this->imageService,
            );
        }

        $this->imageService->updateDescriptiveFields(
            ImageId::from($imageId),
            name: $session->name,
            author: $session->author,
            comment: $session->comment,
            dateCreation: $session->dateCreation,
        );

        PermissionCacheInvalidator::invalidate();

        return new TusUploadCompletionResult(
            imageId: $imageId,
            addStatus: $session->imageId !== null ? 'update' : 'add',
        );
    }
}
