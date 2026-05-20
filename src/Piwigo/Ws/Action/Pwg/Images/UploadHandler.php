<?php

declare(strict_types=1);

namespace Piwigo\Ws\Action\Pwg\Images;

use Piwigo\Admin\Upload\UploadService;
use Piwigo\Category\CategoryRepository;
use Piwigo\Config\Config;
use Piwigo\Core\Filesystem;
use Piwigo\Csrf\CsrfService;
use Piwigo\Html\HtmlService;
use Piwigo\Image\DerivativeImage;
use Piwigo\Image\DerivativeSize;
use Piwigo\Image\ImageRepository;
use Piwigo\Image\ImageStdParams;
use Piwigo\Image\LoungeRepository;
use Piwigo\Image\SrcImage;
use Piwigo\Ws\PwgError;
use Piwigo\Ws\PwgServer;
use Piwigo\Ws\WsAction;
use Piwigo\Ws\WsParamException;

/** `pwg.images.upload` — plupload-style endpoint; chunked or single-shot form upload + format-of support. */
final readonly class UploadHandler implements WsAction
{
    public function __construct(
        private CategoryRepository $categoryRepository,
        private CsrfService $csrfService,
        private HtmlService $htmlService,
        private ImageRepository $imageRepository,
        private LoungeRepository $loungeRepository,
        private UploadService $uploadService,
    ) {
    }

    /** @param array<mixed> $params */
    #[\Override]
    public function __invoke(array $params, PwgServer $server): mixed
    {
        try {
            $input = UploadParams::fromArray($params);
        } catch (WsParamException $e) {
            return new PwgError(403, $e->getMessage());
        }
        $formatExt = null;
        if ($this->csrfService->getToken() !== $input->pwgToken) {
            return new PwgError(403, 'Invalid security token');
        }
        if ($input->formatOf !== null) {
            if (!Config::isFormatsEnabled()) {
                return new PwgError(401, 'formats are disabled');
            }
            $pName = $input->name ?? '';
            if (preg_match('/\.(' . implode('|', Config::formatExtensions()) . ')$/', $pName, $matches)) {
                $formatExt = $matches[1];
            }
            if ($formatExt === null || $formatExt === '') {
                return new PwgError(401, 'unexpected format extension of file "' . $pName . '"');
            }
        }
        $uploadDir = Config::uploadDir() . '/buffer';
        if (!Filesystem::mkgetdir($uploadDir, Filesystem::FLAG_DEFAULT & ~Filesystem::FLAG_DIE_ON_ERROR)) {
            return new PwgError(500, 'error during buffer directory creation');
        }
        if (isset($_REQUEST['name'])) {
            $fileName = is_string($_REQUEST['name']) ? $_REQUEST['name'] : uniqid('file_');
        } elseif (!empty($_FILES)) {
            /** @var array<string, mixed> $filesFile */
            $filesFile     = $_FILES['file'] ?? [];
            $filesFileName = $filesFile['name'] ?? null;
            $fileName      = is_string($filesFileName) ? $filesFileName : uniqid('file_');
        } else {
            $fileName = uniqid('file_');
        }
        $fileName = md5($fileName);
        $filePath = $uploadDir . DIRECTORY_SEPARATOR . $fileName;
        $chunk    = isset($_REQUEST['chunk']) ? (is_numeric($_REQUEST['chunk']) ? (int) $_REQUEST['chunk'] : 0) : 0;
        $chunks   = isset($_REQUEST['chunks']) ? (is_numeric($_REQUEST['chunks']) ? (int) $_REQUEST['chunks'] : 0) : 0;
        if (!$out = Filesystem::tryFopen("{$filePath}.part", $chunks ? 'ab' : 'wb')) {
            return new PwgError(102, 'Failed to open output stream.');
        }
        if (!empty($_FILES)) {
            /** @var array<string, mixed> $filesFile */
            $filesFile        = $_FILES['file'] ?? [];
            $filesFileErrRaw  = $filesFile['error'] ?? null;
            $filesFileError   = is_int($filesFileErrRaw) ? $filesFileErrRaw : 0;
            $filesFileTmpRaw  = $filesFile['tmp_name'] ?? null;
            $filesFileTmpName = is_string($filesFileTmpRaw) ? $filesFileTmpRaw : '';
            if ($filesFileError !== 0 || !is_uploaded_file($filesFileTmpName)) {
                return new PwgError(103, 'Failed to move uploaded file.');
            }
            if (!$in = Filesystem::tryFopen($filesFileTmpName, 'rb')) {
                return new PwgError(101, 'Failed to open input stream.');
            }
        } else {
            if (!$in = Filesystem::tryFopen('php://input', 'rb')) {
                return new PwgError(101, 'Failed to open input stream.');
            }
        }
        if (is_resource($in) && is_resource($out)) {
            while ($buff = fread($in, 4096)) {
                fwrite($out, $buff);
            }
        }
        if (is_resource($out)) {
            fclose($out);
        }
        if (is_resource($in)) {
            fclose($in);
        }
        $addStatus = 'add';
        if (!$chunks || $chunk === $chunks - 1) {
            rename("{$filePath}.part", $filePath);
            if ($input->formatOf !== null) {
                $image = $this->imageRepository->findById($input->formatOf);
                if ($image === null) {
                    return new PwgError(404, __FUNCTION__ . ' : image_id not found');
                }
                $srcImage  = SrcImage::fromImage($image);
                $addStatus = $this->uploadService->addFormat($filePath, $formatExt, (string) $image->id->value);
                return ['image_id' => $image->id->value, 'src' => DerivativeImage::thumbUrl($srcImage), 'square_src' => DerivativeImage::url(ImageStdParams::getByType(DerivativeSize::Square->value), $srcImage), 'name' => $image->name, 'add_status' => $addStatus];
            }
            $name           = stripslashes($input->name ?? '');
            $idImage        = null;
            $pCategoryInt   = $input->categoryIds;
            $pCategoryFirst = $pCategoryInt[0] ?? 0;
            if ($input->updateMode) {
                $idImage = $this->imageRepository->findIdInCategoryByFile($pCategoryFirst, $name);
                if ($idImage !== null) {
                    $addStatus = 'update';
                }
            }
            $imageId        = $this->uploadService->addUploadedFile($filePath, $name, $pCategoryInt, $input->level, $idImage);
            $imageInfos     = $this->imageRepository->findById($imageId);
            $categoryInfos  = ['nb_photos' => $this->categoryRepository->countImagesByCategoryId($pCategoryFirst)];
            $nbPhotosLounge = $this->loungeRepository->countInCategoryNotAssociated($pCategoryFirst);
            $categoryName   = $this->htmlService->getCatDisplayNameFromId($pCategoryFirst, null);
            if ($imageInfos === null) {
                return null;
            }
            $srcImage = SrcImage::fromImage($imageInfos);
            return ['image_id' => $imageId, 'src' => DerivativeImage::thumbUrl($srcImage), 'square_src' => DerivativeImage::url(ImageStdParams::getByType(DerivativeSize::Square->value), $srcImage), 'name' => $imageInfos->name, 'category' => ['id' => $pCategoryFirst, 'nb_photos' => $categoryInfos['nb_photos'] + $nbPhotosLounge, 'label' => $categoryName], 'add_status' => $addStatus];
        }
        return null;
    }
}
