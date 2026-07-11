<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

use Piwigo\Core\ValidationPattern;
use Piwigo\Db\Tables;
use Piwigo\Image\DerivativeImage;
use Piwigo\Image\SrcImage;
use Piwigo\Template\Template;

if (! defined('PHOTOS_ADD_BASE_URL')) {
    die('Hacking attempt!');
}

// Bootstrap globals, set by include/common.inc.php. $page is set by
// admin.php before including this panel.
/**
 * @var array<string, mixed> $conf
 * @var array<string, mixed> $page
 * @var Template $template
 * @var array<string, mixed> $user
 */
global $conf, $page, $template, $user;

// $user['id'] (the logged in / guest user id) is always numeric here (DB
// primary key, or $conf['guest_id']); narrow once and reuse at every site
// below instead of re-reading the offset (each re-read is `mixed`), same
// pattern as admin/batch_manager.php.
$user_id = is_numeric($user['id'] ?? null) ? (int) $user['id'] : 0;

// +-----------------------------------------------------------------------+
// |                        batch management request                       |
// +-----------------------------------------------------------------------+

if (isset($_GET['batch'])) {
    check_input_parameter('batch', $_GET, false, '/^\d+(,\d+)*$/');

    $query = '
DELETE FROM ' . Tables::caddie() . '
  WHERE user_id = ' . $user_id . '
;';
    pwg_query($query);

    $inserts = [];
    $batch_param = $_GET['batch'];
    foreach (array_unique(explode(',', is_scalar($batch_param) ? (string) $batch_param : '')) as $image_id) {
        $inserts[] = [
            'user_id' => $user_id,
            'element_id' => $image_id,
        ];
    }
    mass_inserts(
        Tables::caddie(),
        array_keys($inserts[0]),
        $inserts
    );

    redirect(get_root_url() . 'admin.php?page=batch_manager&filter=prefilter-caddie');
}

if ((bool) userprefs_get_param('promote-mobile-apps', true)) {
    $query = '
SELECT registration_date
  FROM ' . Tables::userInfos() . '
  WHERE registration_date IS NOT NULL
  ORDER BY user_id ASC
  LIMIT 1
;';
    $row = pwg_db_fetch_row(pwg_query($query));
    $register_date = $row !== null ? $row[0] : null;

    $query = '
SELECT COUNT(*)
  FROM ' . Tables::categories() . '
;';
    $row = pwg_db_fetch_row(pwg_query($query));
    assert($row !== null);
    [$nb_cats] = $row;

    $query = '
SELECT COUNT(*)
  FROM ' . Tables::images() . '
;';
    $row = pwg_db_fetch_row(pwg_query($query));
    assert($row !== null);
    [$nb_images] = $row;

    // To see the mobile app promote, the account must have 2 weeks ancient, 3 albums created and 30 photos uploaded
    $template->assign('PROMOTE_MOBILE_APPS', (strtotime((string) $register_date) < strtotime('2 weeks ago') and $nb_cats >= 3 and $nb_images >= 30));
} else {
    $template->assign('PROMOTE_MOBILE_APPS', false);
}

$template->assign('PHPWG_URL', PHPWG_URL);

// +-----------------------------------------------------------------------+
// |                             Formats Mode                              |
// +-----------------------------------------------------------------------+

$display_formats = (bool) $conf['enable_formats'] && isset($_GET['formats']);

$have_formats_original = false;
$formats_original_info = [];
$formats_ext_info = null;

// If URL parameter isn't empty
if ($display_formats && (bool) $_GET['formats']) {
    check_input_parameter('formats', $_GET, false, ValidationPattern::ID, false);

    $formats_id_param = $_GET['formats'];
    $formats_original_info = get_image_infos(is_int($formats_id_param) || is_string($formats_id_param) ? $formats_id_param : '');
    if ((bool) $formats_original_info) {
        $src_image = new SrcImage($formats_original_info);

        $formats_original_info['src'] = DerivativeImage::url(IMG_SQUARE, $src_image);

        // The 'id' column is the Tables::images() primary key: always a numeric
        // value on a row fetched by get_image_infos(), just typed mixed
        // because that function's return signature is array<string, mixed>.
        $formats_image_id = is_numeric($formats_original_info['id']) ? (string) $formats_original_info['id'] : '0';

        // Fetch actual formats
        $query = '
SELECT *
  FROM ' . Tables::imageFormat() . '
  WHERE image_id = ' . $formats_image_id . '
;';
        $formats = query2array($query);

        if (! empty($formats)) {
            $format_strings = [];
            $formats_exts = [];

            foreach ($formats as $format) {
                $format_filesize = is_numeric($format['filesize']) ? ((float) $format['filesize']) / 1024 : 0.0;
                $format_strings[] = sprintf('%s (%.2fMB)', $format['ext'], $format_filesize);
                $formats_exts[] = strtolower((string) $format['ext']);
            }

            $formats_original_info['formats'] = l10n('Formats: %s', implode(', ', $format_strings));
            $formats_ext_info = json_encode($formats_exts);
        }

        $formats_file = $formats_original_info['file'];
        $extTab = explode('.', is_string($formats_file) ? $formats_file : '');

        $formats_original_info['ext'] = l10n('%s file type', strtoupper(end($extTab)));

        $formats_original_info['u_edit'] = get_root_url() . 'admin.php?page=photo-' . $formats_image_id;

        $have_formats_original = true;
    } else {
        // $page['errors'] is always initialized to an array by
        // include/common.inc.php; re-assert it here so PHPStan can prove
        // the push below is array-like (same pattern as admin/cat_list.php).
        $page['errors'] = is_array($page['errors'] ?? null) ? $page['errors'] : [];
        $page['errors'][] = l10n('The original picture selected dosen\'t exists.');
    }

}

// +-----------------------------------------------------------------------+
// |                             prepare form                              |
// +-----------------------------------------------------------------------+

include_once PHPWG_ROOT_PATH . 'admin/include/photos_add_direct_prepare.inc.php';

// +-----------------------------------------------------------------------+
// |                           sending html code                           |
// +-----------------------------------------------------------------------+

trigger_notify('loc_end_photo_add_direct');

// $conf['format_ext'] is read twice below; narrow once and reuse instead of
// re-reading the offset (each re-read is `mixed`), same pattern as
// admin/batch_manager.php.
$conf_format_ext = is_array($conf['format_ext'] ?? null) ? $conf['format_ext'] : [];

$template->assign([
    'ENABLE_FORMATS' => $conf['enable_formats'],
    'DISPLAY_FORMATS' => $display_formats,
    'HAVE_FORMATS_ORIGINAL' => $have_formats_original,
    'FORMATS_ORIGINAL_INFO' => $formats_original_info,
    'FORMATS_EXT_INFO' => $formats_ext_info,
    'SWITCH_FORMAT_MODE_URL' => get_root_url() . 'admin.php?page=photos_add' . ($display_formats ? '' : '&formats'),
    'format_ext' => implode(',', array_filter($conf_format_ext, is_string(...))),
    'str_format_ext' => implode(', ', array_filter($conf_format_ext, is_string(...))),
]);

$template->assign_var_from_handle('ADMIN_CONTENT', 'photos_add');
