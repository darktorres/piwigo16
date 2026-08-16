<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Ws\Images;

use Doctrine\ORM\EntityManagerInterface;
use Exception;
use Override;
use Piwigo\Admin\Upload\UploadService;
use Piwigo\Category\CategoryService;
use Piwigo\Common\ValueObject\ImageId;
use Piwigo\Core\CurrentLogger;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Image\ImageService;
use Piwigo\Metadata\MetadataService;
use Piwigo\Permission\PermissionService;
use Piwigo\Tag\TagService;
use Piwigo\Ws\Request\UploadedFileRequest;
use Piwigo\Ws\Server;
use Piwigo\Ws\WsAction;
use Piwigo\Ws\WsErrorResponse;

/**
 * `pwg.images.addSimple` -- admin only. Adds an image (simple way).
 * Uses the `$_FILES[image]` field for uploading the file.
 *
 * Every registered field is always present (either a real default or
 * mandatory), matching the shape below; `date_creation` is deliberately
 * NOT a field -- it's not a registered param at all (reachable only via
 * an unregistered extra GET/POST key, same as e.g.
 * `Users\GetListHandler`'s own `max_level`). This method's whole shape
 * doesn't benefit from a dedicated Params DTO the way a fixed-shape
 * method would, so this reads a local `@var`-narrowed copy directly,
 * same as `Images\AddHandler`'s own documented rationale.
 */
final readonly class AddSimpleHandler implements WsAction
{
    public function __construct(
        private ImageService $imageService,
        private CategoryService $categoryService,
        private TagService $tagService,
        private UploadService $uploadService,
        private MetadataService $metadataService,
        private PermissionService $permissionService,
        private UrlServiceInterface $urlService,
        private CurrentLogger $currentLogger,
        private EntityManagerInterface $entityManager,
    ) {}

    /**
     * @param array<mixed> $params
     * @return WsErrorResponse|array{image_id: int|string, url: string}
     */
    #[Override]
    public function __invoke(array $params, Server $server): WsErrorResponse|array
    {
        // MethodDefinition's own registration for this method guarantees
        // this exact shape before __invoke() ever runs -- WsAction::
        // __invoke()'s own $params type can't express that (every handler
        // shares the same loose array<mixed> contract), so it's asserted
        // locally at this one call site instead.
        /** @var array{category: array<int, int>, name: string|null, author: string|null, comment: string|null, level: int, tags: string|array<array-key, string>|null, image_id: int|null, ...} */
        $params = $params;

        $logger = $this->currentLogger->get();

        $uploaded_image = UploadedFileRequest::fromFilesKey('image');
        if (! $uploaded_image->present) {
            return new WsErrorResponse(405, 'The image (file) is missing');
        }

        if ($uploaded_image->error !== null && $uploaded_image->error !== 0) {
            $upload_error = $uploaded_image->error;
            $message = match ($upload_error) {
                UPLOAD_ERR_INI_SIZE => 'The uploaded file exceeds the upload_max_filesize directive in php.ini.',
                UPLOAD_ERR_FORM_SIZE => 'The uploaded file exceeds the MAX_FILE_SIZE directive that was specified in the HTML form.',
                UPLOAD_ERR_PARTIAL => 'The uploaded file was only partially uploaded.',
                UPLOAD_ERR_NO_FILE => 'No file was uploaded.',
                UPLOAD_ERR_NO_TMP_DIR => 'Missing a temporary folder.',
                UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk.',
                UPLOAD_ERR_EXTENSION => 'A PHP extension stopped the file upload. ' .
                'PHP does not provide a way to ascertain which extension caused the file ' .
                'upload to stop; examining the list of loaded extensions with phpinfo() may help.',
                default => 'Error number ' . $upload_error . ' occurred while uploading a file.',
            };

            $logger->error('addSimple ' . $message);
            return new WsErrorResponse(500, $message);
        }

        if ($params['image_id'] > 0) {
            if (! $this->imageService->existsById(ImageId::from($params['image_id']))) {
                return new WsErrorResponse(404, 'image_id not found');
            }
        }

        $uploaded_tmp_name = $uploaded_image->tmpName;
        if ($uploaded_tmp_name === null) {
            return new WsErrorResponse(500, '[ws_images_addSimple] missing uploaded file temp name');
        }

        $image_id = $this->uploadService
            ->addUploadedFile(
                $uploaded_tmp_name,
                $this->urlService,
                $uploaded_image->name,
                $params['category'],
                8,
                $params['image_id'] > 0 ? $params['image_id'] : null,
                null,
            );

        $this->imageService->updateLevelForImages([$image_id], $params['level']);

        $this->imageService->updateDescriptiveFields(
            ImageId::from($image_id),
            name: is_string($params['name']) ? $params['name'] : null,
            author: is_string($params['author']) ? $params['author'] : null,
            comment: is_string($params['comment']) ? $params['comment'] : null,
            dateCreation: is_string($params['date_creation'] ?? null) ? $params['date_creation'] : null,
        );
        $this->entityManager->clear();

        if (isset($params['tags']) and $params['tags'] !== '' and $params['tags'] !== []) {
            $tagService = $this->tagService;

            $tag_ids = [];
            if (is_array($params['tags'])) {
                foreach ($params['tags'] as $tag_name) {
                    $tag_ids[] = $tagService->tagIdFromTagName($tag_name);
                }
            } else {
                $tag_names = preg_split('~(?<!\\\),~', $params['tags']);
                if ($tag_names === false) {
                    throw new Exception('ws_images_addSimple(): preg_split() failed');
                }
                foreach ($tag_names as $tag_name) {
                    $unescaped_tag_name = preg_replace('#\\\\*,#', ',', $tag_name);
                    assert($unescaped_tag_name !== null);
                    $tag_ids[] = $tagService->tagIdFromTagName($unescaped_tag_name);
                }
            }

            $tagService->addTags($tag_ids, [$image_id]);
        }

        $url_params = [
            'image_id' => $image_id,
        ];

        if ($params['category'] !== []) {
            $category = $this->categoryService->getIdNamePermalinkById($params['category'][0]);

            $url_params['section'] = 'categories';
            $url_params['category'] = $category;
        }

        // update metadata from the uploaded file (exif/iptc), even if the sync
        // was already performed by add_uploaded_file().
        $this->metadataService
            ->syncMetadata([$image_id], $this->permissionService, $this->entityManager);

        return [
            'image_id' => $image_id,
            'url' => $this->urlService
                ->makePictureUrl($url_params),
        ];
    }
}
