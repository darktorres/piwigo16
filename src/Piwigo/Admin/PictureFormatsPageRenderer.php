<?php

declare(strict_types=1);

namespace Piwigo\Admin;

use Piwigo\Core\AccessLevel;
use Piwigo\Core\ValidationPattern;
use Piwigo\Db\Tables;
use Piwigo\Image\DerivativeImage;
use Piwigo\Image\ImageStdParams;
use Piwigo\Template\Template;

/**
 * Ported from admin/picture_formats.php (page slug "picture_formats").
 */
final class PictureFormatsPageRenderer
{
    public function render(): void
    {
        /**
         * @var Template $template
         * @var array<string, mixed> $lang
         */
        global $template, $lang;

        \Piwigo\Auth\AccessControl::checkStatus(AccessLevel::Administrator);

        check_input_parameter('image_id', $_GET, false, ValidationPattern::ID);

        // $_GET['image_id'], when present, matches ValidationPattern::ID (digits only), but
        // that guarantee isn't visible to static analysis, hence the explicit
        // narrowing before it's used in SQL/URL concatenation below.
        $image_id = is_numeric($_GET['image_id'] ?? null) ? (int) $_GET['image_id'] : 0;

        $query = '
SELECT *
  FROM ' . Tables::images() . '
  WHERE id = ' . $image_id . '
;';
        $images = query2array($query);
        $image = $images[0];

        $query = '
SELECT
    *
  FROM ' . Tables::imageFormat() . '
  WHERE image_id = ' . $image_id . '
;';

        $formats = query2array($query);

        foreach ($formats as &$format) {
            $format['download_url'] = 'action.php?format=' . $format['format_id'] . '&amp;download';

            $format['label'] = strtoupper((string) $format['ext']);
            $lang_key = 'format ' . strtoupper((string) $format['ext']);
            $lang_label = isset($lang[$lang_key]) && is_string($lang[$lang_key]) ? $lang[$lang_key] : null;
            if ($lang_label !== null) {
                $format['label'] = $lang_label;
            }

            $filesize = is_numeric($format['filesize']) ? (float) $format['filesize'] : 0.0;
            $format['filesize'] = round($filesize / 1024, 2);
        }
        unset($format);

        $template->assign([
            'ADD_FORMATS_URL' => get_root_url() . 'admin.php?page=photos_add&formats=' . $image_id,
            'IMG_SQUARE_SRC' => DerivativeImage::url(ImageStdParams::get_by_type(IMG_SQUARE), $image),
            'FORMATS' => $formats,
            'PWG_TOKEN' => get_pwg_token(),
        ]);

        $template->set_filename('picture_formats', 'picture_formats.tpl');

        $template->assign_var_from_handle('ADMIN_CONTENT', 'picture_formats');
    }
}
