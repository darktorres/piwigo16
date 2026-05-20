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
use Piwigo\Ws\WsParamException;

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
        try {
            $input = FormatsDeleteParams::fromArray($params);
        } catch (WsParamException $e) {
            return new PwgError(403, $e->getMessage());
        }
        if ($this->csrfService->getToken() !== $input->pwgToken) {
            return new PwgError(403, 'Invalid security token');
        }
        $formatIds = $input->formatIds;
        /** @var array<string, list<string>> $formatsOf */
        $formatsOf = [];
        /** @var list<string> $imageIds */
        $imageIds  = [];
        foreach ($this->imageFormatRepository->findByFormatIds($formatIds) as $row) {
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
        $this->imageFormatRepository->deleteByFormatIds($formatIds);
        $this->userAdminService->invalidateUserCache();
        return true;
    }
}
