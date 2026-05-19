<?php

declare(strict_types=1);

namespace Piwigo\Ws\Action\Pwg\Images;

use Piwigo\Admin\Users\UserAdminService;
use Piwigo\Core\StringUtil;
use Piwigo\Csrf\CsrfService;
use Piwigo\Image\ImageFormatRepository;
use Piwigo\Image\ImageRepository;
use Piwigo\Url\UrlService;
use Piwigo\Ws\PwgError;
use Piwigo\Ws\PwgServer;
use Piwigo\Ws\WsAction;

/** `pwg.images.formats.delete` — unlink format files + drop their rows. */
final readonly class FormatsDeleteHandler implements WsAction
{
    public function __construct(
        private CsrfService $csrfService,
        private ImageFormatRepository $imageFormatRepository,
        private ImageRepository $imageRepository,
        private UserAdminService $userAdminService,
    ) {
    }

    /** @param array<mixed> $params */
    #[\Override]
    public function __invoke(array $params, PwgServer $server): PwgError|bool
    {
        if ($this->csrfService->getToken() !== $params['pwg_token']) {
            return new PwgError(403, 'Invalid security token');
        }
        if (!is_array($params['format_id'])) {
            $params['format_id'] = (($fmtSplit = preg_split('/[\s,;\|]/', is_string($params['format_id']) ? $params['format_id'] : '', -1, PREG_SPLIT_NO_EMPTY)) !== false ? $fmtSplit : []);
        }
        $params['format_id'] = array_map(fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $params['format_id']);
        $formatIds = array_filter($params['format_id'], fn (int $v): bool => $v >= 0);
        /** @var array<string, list<string>> $formatsOf */
        $formatsOf = [];
        /** @var list<string> $imageIds */
        $imageIds  = [];
        foreach ($this->imageFormatRepository->findByFormatIds(array_map(intval(...), $formatIds)) as $row) {
            $rowImageId = is_scalar($row['image_id'] ?? null) ? (string) $row['image_id'] : '';
            $rowExt     = is_string($row['ext'] ?? null) ? $row['ext'] : '';
            if (!isset($formatsOf[$rowImageId])) {
                $imageIds[] = $rowImageId;
                $formatsOf[$rowImageId] = [];
            }
            $formatsOf[$rowImageId][] = $rowExt;
        }
        if (count($imageIds) === 0) {
            return new PwgError(404, 'No format found for the id(s) given');
        }
        foreach ($this->imageRepository->findByIds(array_map(intval(...), $imageIds)) as $img) {
            $rowPath = $img->path->value;
            $rowId   = (string) $img->id->value;
            if (UrlService::urlIsRemote($rowPath)) {
                continue;
            }
            $imagePath = StringUtil::getElementPath(['path' => $rowPath]);
            $files     = [];
            if (isset($formatsOf[$rowId])) {
                foreach ($formatsOf[$rowId] as $formatExt) {
                    $files[] = StringUtil::originalToFormat($imagePath, $formatExt);
                }
            }
            foreach ($files as $path) {
                if (is_file($path) && !unlink($path)) {
                    throw new \RuntimeException('"' . $path . '" cannot be removed');
                }
            }
        }
        $this->imageFormatRepository->deleteByFormatIds(array_map(intval(...), $formatIds));
        $this->userAdminService->invalidateUserCache();
        return true;
    }
}
