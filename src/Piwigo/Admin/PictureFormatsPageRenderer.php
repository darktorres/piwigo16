<?php

declare(strict_types=1);

namespace Piwigo\Admin;

use Piwigo\Admin\Request\PictureFormatsImageIdRequest;
use Piwigo\Auth\AccessControl;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\AccessLevel;
use Piwigo\Core\HtmlRenderingInterface;
use Piwigo\Core\Lang;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Csrf\CsrfService;
use Piwigo\Db\DbConnection;
use Piwigo\Db\EntityManagerFactory;
use Piwigo\Image\DerivativeImage;
use Piwigo\Image\ImageEntity;
use Piwigo\Image\ImageStdParams;
use Piwigo\Template\CurrentTemplate;
use Piwigo\Validation\InputValidator;

/**
 * Ported from admin/picture_formats.php (page slug "picture_formats").
 */
final class PictureFormatsPageRenderer
{
    public function render(Lang $lang, AccessControl $accessControl, UrlServiceInterface $urlService, ImageStdParams $imageStdParams, CurrentTemplate $currentTemplate, HtmlRenderingInterface $htmlRenderer, InputValidator $inputValidator, CurrentConfig $currentConfig): void
    {
        $template = $currentTemplate->get();

        $accessControl->checkStatus(AccessLevel::Administrator);

        $image_id = PictureFormatsImageIdRequest::fromGlobals($inputValidator)->imageId;

        $conn = DbConnection::build();
        $imageRow = EntityManagerFactory::build($conn)->getRepository(ImageEntity::class)
            ->findById($image_id);
        if ($imageRow === null) {
            $htmlRenderer
                ->fatalError('image_id #' . $image_id . ' does not exist');
        }
        $image = $imageRow->toArray();

        $formats = [];
        foreach (EntityManagerFactory::build($conn)->getRepository(ImageEntity::class)->findFormatsForImage($image_id) as $formatRow) {
            $format = $formatRow->toArray();
            $format['download_url'] = 'action.php?format=' . $formatRow->formatId . '&amp;download';

            $format['label'] = strtoupper($formatRow->ext);
            $lang_key = 'format ' . strtoupper($formatRow->ext);
            $lang_label = $lang->has($lang_key) ? $lang->t($lang_key) : null;
            if ($lang_label !== null) {
                $format['label'] = $lang_label;
            }

            $filesize = (float) ($formatRow->filesize ?? 0);
            $format['filesize'] = round($filesize / 1024.0, 2);

            $formats[] = $format;
        }

        $template->assign([
            'ADD_FORMATS_URL' => $urlService->getRootUrl() . 'admin.php?page=photos_add&formats=' . $image_id,
            'IMG_SQUARE_SRC' => DerivativeImage::url($imageStdParams->get_by_type(ImageStdParams::SQUARE), $image),
            'FORMATS' => $formats,
            'PWG_TOKEN' => new CsrfService($currentConfig)
                ->getToken(),
        ]);

        $template->set_filename('picture_formats', 'picture_formats.tpl');

        $template->assign_var_from_handle('ADMIN_CONTENT', 'picture_formats');
    }
}
