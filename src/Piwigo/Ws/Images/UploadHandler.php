<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Ws\Images;

use Exception;
use Override;
use Piwigo\Admin\Upload\UploadService;
use Piwigo\Common\ValueObject\CategoryId;
use Piwigo\Common\ValueObject\ImageId;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\FilesystemHelper;
use Piwigo\Core\Paths;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Html\HtmlService;
use Piwigo\Image\DerivativeImage;
use Piwigo\Image\ImageRepository;
use Piwigo\Image\ImageService;
use Piwigo\Image\ImageStdParams;
use Piwigo\Image\Projection\Image;
use Piwigo\Image\Projection\UploadResultInfo;
use Piwigo\Ws\Request\ChunkedUploadRequest;
use Piwigo\Ws\Request\UploadedFileRequest;
use Piwigo\Ws\Server;
use Piwigo\Ws\WsAction;
use Piwigo\Ws\WsErrorResponse;
use Piwigo\Ws\WsHelper;

/**
 * `pwg.images.upload` -- admin only. Uploads a file, chunked or whole.
 * Uses the `$_FILES[image]` field for uploading file, form encoding
 * "form-data".
 *
 * Every registered field is always present (either a real default or
 * mandatory), matching the shape below -- this method's whole shape
 * doesn't benefit from a dedicated Params DTO the way a fixed-shape
 * method would, so this reads a local `@var`-narrowed copy directly,
 * same as `Images\AddHandler`'s own documented rationale.
 *
 * The 2 real array-literal return sites have genuinely different shapes
 * (a format_of upload returns image_id/src/square_src/name/add_status;
 * a new-photo upload adds a 'category' sub-array on top) -- left as
 * array<string, mixed> rather than an unverified 2-branch union.
 */
final readonly class UploadHandler implements WsAction
{
    public function __construct(
        private ImageService $imageService,
        private ImageRepository $imageRepository,
        private UploadService $uploadService,
        private CurrentConfig $currentConfig,
        private UrlServiceInterface $urlService,
        private Paths $paths,
        private HtmlService $htmlService,
        private ImageStdParams $imageStdParams,
        private WsHelper $wsHelper,
    ) {}

    /**
     * @param array<mixed> $params
     * @return WsErrorResponse|array<string, mixed>|null
     */
    #[Override]
    public function __invoke(array $params, Server $server): WsErrorResponse|array|null
    {
        // MethodDefinition's own registration for this method guarantees
        // this exact shape before __invoke() ever runs -- WsAction::
        // __invoke()'s own $params type can't express that (every handler
        // shares the same loose array<mixed> contract), so it's asserted
        // locally at this one call site instead.
        /** @var array{name: string|null, category: array<int, int>, level: int, format_of: int|null, update_mode: bool, pwg_token: string, ...} */
        $params = $params;

        $format_ext = null;

        $csrfError = $this->wsHelper->checkSecurityToken($params['pwg_token']);
        if ($csrfError instanceof WsErrorResponse) {
            return $csrfError;
        }

        if (isset($params['format_of'])) {
            // are formats enabled?
            if (! $this->currentConfig->isFormatsEnabled) {
                return new WsErrorResponse(401, 'formats are disabled');
            }

            $format_ext_list = $this->currentConfig->formatExtensions;

            // We must check if the extension is in the authorized list.
            if ((bool) preg_match('/\.(' . implode('|', $format_ext_list) . ')$/', (string) $params['name'], $matches)) {
                $format_ext = $matches[1];
            }

            if (! is_string($format_ext) || $format_ext === '') {
                return new WsErrorResponse(401, 'unexpected format extension of file "' . $params['name'] . '" (authorized extensions: ' . implode(', ', $format_ext_list) . ')');
            }
        }

        $upload_dir_conf = $this->paths->root . $this->currentConfig->uploadDir;
        $upload_dir = $upload_dir_conf . '/buffer';

        // create the upload directory tree if not exists
        if (! FilesystemHelper::mkgetdir($upload_dir, $this->currentConfig, FilesystemHelper::MKGETDIR_DEFAULT & ~FilesystemHelper::MKGETDIR_DIE_ON_ERROR)) {
            return new WsErrorResponse(500, 'error during buffer directory creation');
        }

        $chunkedUploadRequest = ChunkedUploadRequest::fromGlobals();
        $uploaded_file = UploadedFileRequest::fromFilesKey('file');

        // Get a file name
        if ($chunkedUploadRequest->requestNamePresent) {
            $fileName = $chunkedUploadRequest->requestName;
        } elseif ($uploaded_file->present) {
            $fileName = $uploaded_file->name;
        } else {
            $fileName = uniqid('file_');
        }

        // change the name of the file in the buffer to avoid any unexpected
        // extension. Function add_uploaded_file will eventually clean the mess.
        $fileName = md5(is_string($fileName) ? $fileName : '');

        $filePath = $upload_dir . DIRECTORY_SEPARATOR . $fileName;

        // Chunking might be enabled
        $chunk = $chunkedUploadRequest->chunk;
        $chunks = $chunkedUploadRequest->chunks;

        // Open temp file
        if (! (bool) ($out = @fopen("{$filePath}.part", ((bool) $chunks) ? 'ab' : 'wb'))) {
            return new WsErrorResponse(102, 'Failed to open output stream.');
        }

        // $_FILES having ANY entry at all (even one not named 'file')
        // already commits to the "move an uploaded file" path below,
        // rather than silently falling through to the php://input branch
        // -- a minimal, single-fact existence check, same shape as
        // Ws\Server::isPost()'s own raw $_POST read.
        if ($_FILES !== []) {
            if (! $uploaded_file->present) {
                return new WsErrorResponse(103, 'Failed to move uploaded file.');
            }
            $uploaded_file_tmp_name = $uploaded_file->tmpName;

            if (($uploaded_file->error !== null && $uploaded_file->error !== 0) || $uploaded_file_tmp_name === null || ! is_uploaded_file($uploaded_file_tmp_name)) {
                return new WsErrorResponse(103, 'Failed to move uploaded file.');
            }

            // Read binary input stream and append it to temp file
            if (! (bool) ($in = @fopen($uploaded_file_tmp_name, 'rb'))) {
                return new WsErrorResponse(101, 'Failed to open input stream.');
            }
        } else {
            if (! (bool) ($in = @fopen('php://input', 'rb'))) {
                return new WsErrorResponse(101, 'Failed to open input stream.');
            }
        }

        while ((bool) ($buff = fread($in, 4096))) {
            fwrite($out, $buff);
        }

        @fclose($out);
        @fclose($in);

        $add_status = 'add';
        // Check if file has been uploaded
        if (! (bool) $chunks || $chunk === $chunks - 1) {
            // Strip the temp .part suffix off
            rename("{$filePath}.part", $filePath);

            if (isset($params['format_of'])) {
                $formatOfId = ImageId::tryFrom($params['format_of']);
                $imageRow = $formatOfId instanceof ImageId ? $this->imageRepository->findById($formatOfId) : null;
                if (! $imageRow instanceof Image) {
                    return new WsErrorResponse(404, 'upload : image_id not found');
                }
                $image = $imageRow->toArray();

                $add_status = $this->uploadService
                    ->addFormat($filePath, $format_ext, $imageRow->id->value);

                return [
                    'image_id' => $image['id'],
                    'src' => DerivativeImage::thumbUrl($image),
                    'square_src' => DerivativeImage::url($this->imageStdParams->getByType(ImageStdParams::SQUARE), $image),
                    'name' => $image['name'],
                    'add_status' => $add_status,
                ];
            }

            $name = stripslashes((string) $params['name']);
            $id_image = null; // null by default

            if ($params['update_mode']) {
                $existing_ids = $this->imageService->getIdsByFilenameInCategory($name, CategoryId::from($params['category'][0]));
                if ($existing_ids !== []) {
                    $id_image = $existing_ids[0]; // take the id of the already existing image to replace it
                    $add_status = 'update';
                }
            }

            $image_id = $this->uploadService
                ->addUploadedFile(
                    $filePath,
                    $this->urlService,
                    $name, // function add_uploaded_file will secure before insert
                    $params['category'],
                    $params['level'],
                    $id_image,
                    null,
                    $server
                );

            $image_infos = $this->imageService->getUploadResultInfoById(ImageId::from($image_id));
            if (! $image_infos instanceof UploadResultInfo) {
                throw new Exception('ws_images_upload(): image fetch failed right after inserting it');
            }

            $categoryId = CategoryId::from($params['category'][0]);
            $nb_photos_in_category = $this->imageService->countImagesInCategory($categoryId);
            $nb_photos_lounge = $this->imageService->countLoungeImagesPendingForCategory($categoryId);

            $category_name = $this->htmlService
                ->getCatDisplayNameFromId($params['category'][0], null);

            return [
                'image_id' => $image_id,
                'src' => DerivativeImage::thumbUrl($image_infos->toArray()),
                'square_src' => DerivativeImage::url($this->imageStdParams->getByType(ImageStdParams::SQUARE), $image_infos->toArray()),
                'name' => $image_infos->name,
                'category' => [
                    'id' => $params['category'][0],
                    'nb_photos' => $nb_photos_in_category + $nb_photos_lounge,
                    'label' => $category_name,
                ],
                'add_status' => $add_status,
            ];
        }

        return null;
    }
}
