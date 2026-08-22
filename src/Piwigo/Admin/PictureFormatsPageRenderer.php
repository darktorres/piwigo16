<?php

declare(strict_types=1);

namespace Piwigo\Admin;

use Doctrine\ORM\EntityManagerInterface;
use Piwigo\Admin\Projection\PictureFormatRow;
use Piwigo\Admin\Projection\PictureFormatsView;
use Piwigo\Admin\Request\PictureFormatsImageIdRequest;
use Piwigo\Auth\AccessControl;
use Piwigo\Common\ValueObject\ImageId;
use Piwigo\Controller\Admin\Projection\AdminPageResult;
use Piwigo\Core\AccessLevel;
use Piwigo\Core\HtmlRenderingInterface;
use Piwigo\Core\Lang;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Csrf\CsrfService;
use Piwigo\Image\DerivativeImage;
use Piwigo\Image\ImageEntity;
use Piwigo\Image\ImageStdParams;
use Piwigo\Image\Projection\SrcImageInfo;
use Piwigo\Template\CurrentTemplate;
use Piwigo\Template\Renderer;
use Piwigo\Validation\InputValidator;

/**
 * Ported from admin/picture_formats.php (page slug "picture_formats").
 */
final class PictureFormatsPageRenderer
{
    public function render(Lang $lang, AccessControl $accessControl, UrlServiceInterface $urlService, ImageStdParams $imageStdParams, CurrentTemplate $currentTemplate, HtmlRenderingInterface $htmlRenderer, InputValidator $inputValidator, CsrfService $csrfService, EntityManagerInterface $entityManager, Renderer $renderer): AdminPageResult
    {
        $accessControl->checkStatus(AccessLevel::Administrator);

        $image_id = PictureFormatsImageIdRequest::fromGlobals($inputValidator)->imageId;
        if (! $image_id instanceof ImageId) {
            $htmlRenderer
                ->fatalError('image_id does not exist');
        }

        $imageRow = $entityManager->getRepository(ImageEntity::class)
            ->findById($image_id);
        if ($imageRow === null) {
            $htmlRenderer
                ->fatalError('image_id #' . $image_id->value . ' does not exist');
        }
        $image = SrcImageInfo::fromRow($imageRow->toArray());

        $formats = [];
        foreach ($entityManager->getRepository(ImageEntity::class)->findFormatsForImage($image_id) as $formatRow) {
            $label = strtoupper($formatRow->ext);
            $lang_key = 'format ' . strtoupper($formatRow->ext);
            $lang_label = $lang->has($lang_key) ? $lang->t($lang_key) : null;
            if ($lang_label !== null) {
                $label = $lang_label;
            }

            $filesize = (float) ($formatRow->filesize ?? 0);

            $formats[] = new PictureFormatRow(
                formatId: $formatRow->formatId,
                imageId: $formatRow->imageId->value,
                ext: $formatRow->ext,
                filesize: round($filesize / 1024.0, 2),
                downloadUrl: 'action.php?format=' . $formatRow->formatId . '&amp;download',
                label: $label,
            );
        }

        $adminContent = $renderer->render(new PictureFormatsView(
            addFormatsUrl: $urlService->getRootUrl() . 'admin.php?page=photos_add&formats=' . $image_id,
            imgSquareSrc: DerivativeImage::url($imageStdParams->getByType(ImageStdParams::SQUARE), $image),
            formats: array_map(static fn (PictureFormatRow $format): array => $format->toArray(), $formats),
            pwgToken: $csrfService
                ->getToken(),
        ));

        return new AdminPageResult(content: $adminContent);
    }
}
