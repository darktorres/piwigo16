<?php

declare(strict_types=1);

namespace Piwigo\Admin;

use Piwigo\Core\AccessLevel;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Core\ValidationPattern;
use Piwigo\Db\DbConnection;
use Piwigo\Db\Tables;
use Piwigo\Image\DerivativeImage;
use Piwigo\Image\ImageStdParams;

/**
 * Ported from admin/picture_formats.php (page slug "picture_formats").
 */
final class PictureFormatsPageRenderer
{
    public function render(UrlServiceInterface $urlService): void
    {
        $template = \Piwigo\Template\CurrentTemplate::get();

        \Piwigo\Auth\AccessControl::checkStatus(AccessLevel::Administrator);

        new \Piwigo\Validation\InputValidator()
            ->validate('image_id', $_GET, false, ValidationPattern::ID);

        // $_GET['image_id'], when present, matches ValidationPattern::ID (digits only), but
        // that guarantee isn't visible to static analysis, hence the explicit
        // narrowing before it's used in SQL/URL concatenation below.
        $image_id_raw = $_GET['image_id'] ?? null;
        $image_id = is_numeric($image_id_raw) ? (int) $image_id_raw : 0;

        $conn = DbConnection::build();
        $query = '
SELECT *
  FROM ' . Tables::images() . '
  WHERE id = ' . $image_id . '
;';
        $images = $conn->fetchAllAssociative($query);
        $image = $images[0];

        $query = '
SELECT
    *
  FROM ' . Tables::imageFormat() . '
  WHERE image_id = ' . $image_id . '
;';

        $formats = $conn->fetchAllAssociative($query);

        foreach ($formats as &$format) {
            $format_id_str = is_scalar($format['format_id']) ? (string) $format['format_id'] : '';
            $format['download_url'] = 'action.php?format=' . $format_id_str . '&amp;download';

            $format_ext = is_scalar($format['ext']) ? (string) $format['ext'] : '';
            $format['label'] = strtoupper($format_ext);
            $lang_key = 'format ' . strtoupper($format_ext);
            $lang_label = \Piwigo\Core\Lang::has($lang_key) ? \Piwigo\Core\Lang::t($lang_key) : null;
            if ($lang_label !== null) {
                $format['label'] = $lang_label;
            }

            $filesize = is_numeric($format['filesize']) ? (float) $format['filesize'] : 0.0;
            $format['filesize'] = round($filesize / 1024.0, 2);
        }
        unset($format);

        $template->assign([
            'ADD_FORMATS_URL' => $urlService->getRootUrl() . 'admin.php?page=photos_add&formats=' . $image_id,
            'IMG_SQUARE_SRC' => DerivativeImage::url(ImageStdParams::get_by_type(ImageStdParams::SQUARE), $image),
            'FORMATS' => $formats,
            'PWG_TOKEN' => new \Piwigo\Csrf\CsrfService()
                ->getToken(),
        ]);

        $template->set_filename('picture_formats', 'picture_formats.tpl');

        $template->assign_var_from_handle('ADMIN_CONTENT', 'picture_formats');
    }
}
