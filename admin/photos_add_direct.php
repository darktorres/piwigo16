<?php

declare(strict_types=1);

use Piwigo\Exception\AuthException;
use Piwigo\Core\ServiceLocator;
use Piwigo\Image\ImageRepository;
use Piwigo\Users\UserRepository;
use Piwigo\Category\CategoryRepository;
use Piwigo\Config\Config;
use Piwigo\Core\PageState;
use Piwigo\Image\DerivativeImage;
use Piwigo\Image\SrcImage;

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

if (!defined('PHOTOS_ADD_BASE_URL')) {
    throw new AuthException('Hacking attempt!');
}

global $template, $user, $page, $persistent_cache, $lang, $logger, $pwg_loaded_plugins;

// +-----------------------------------------------------------------------+
// |                        batch management request                       |
// +-----------------------------------------------------------------------+

if (isset($_GET['batch'])) {
    check_input_parameter('batch', $_GET, false, '/^\d+(,\d+)*$/');

    ServiceLocator::get(ImageRepository::class)
        ->deleteUserCaddie(is_numeric($user['id']) ? (int) $user['id'] : 0);

    $inserts = [];
    foreach (array_unique(explode(',', is_scalar($_GET['batch']) ? (string) $_GET['batch'] : '')) as $image_id) {
        $inserts[] = [
          'user_id' => $user['id'],
          'element_id' => $image_id,
          ];
    }
    mass_inserts(
        CADDIE_TABLE,
        array_keys($inserts[0]),
        $inserts
    );

    redirect(get_root_url().'admin.php?page=batch_manager&filter=prefilter-caddie');
}

if (userprefs_get_param('promote-mobile-apps', true)) {
    $register_date = ServiceLocator::get(UserRepository::class)
        ->findEarliestRegistrationDate();
    $nb_cats   = ServiceLocator::get(CategoryRepository::class)->countAll();
    $nb_images = ServiceLocator::get(ImageRepository::class)->countAll();

    $uagent_obj = new uagent_info();
    // To see the mobile app promote, the account must have 2 weeks ancient, 3 albums created and 30 photos uploaded
    $template->assign('PROMOTE_MOBILE_APPS', (!$uagent_obj->DetectIos() and strtotime((string) $register_date) < strtotime('2 weeks ago') and $nb_cats >= 3 and $nb_images >= 30));
} else {
    $template->assign('PROMOTE_MOBILE_APPS', false);
}

$template->assign('PHPWG_URL', PHPWG_URL);

// +-----------------------------------------------------------------------+
// |                             Formats Mode                              |
// +-----------------------------------------------------------------------+

$display_formats = Config::isFormatsEnabled() && isset($_GET['formats']);

$have_formats_original = false;
$formats_original_info = [];
$formats_ext_info = null;

// If URL parameter isn't empty
if ($display_formats && $_GET['formats']) {
    check_input_parameter('formats', $_GET, false, PATTERN_ID, false);

    $formatsId = is_scalar($_GET['formats']) ? (string) $_GET['formats'] : '';
    $formats_original_info = get_image_infos($formatsId);
    if ($formats_original_info) {
        $src_image = new SrcImage($formats_original_info);

        $formats_original_info['src'] = DerivativeImage::url(IMG_SQUARE, $src_image);

        $fmtId = is_scalar($formats_original_info['id'] ?? null) ? (string) $formats_original_info['id'] : '0';
        // Fetch actual formats
        $query = '
SELECT *
  FROM '.IMAGE_FORMAT_TABLE.'
  WHERE image_id = '.$fmtId.'
;';
        $formats = get_dbal_connection()->executeQuery($query)->fetchAllAssociative();

        if (!empty($formats)) {
            $format_strings = [];
            $formats_exts = [];

            foreach ($formats as $format) {
                $format_strings[] = sprintf('%s (%.2fMB)', is_scalar($format['ext']) ? (string) $format['ext'] : '', (is_numeric($format['filesize']) ? (int)$format['filesize'] : 0) / 1024);
                $formats_exts[] = strtolower(is_scalar($format['ext']) ? (string) $format['ext'] : '');
            }

            $formats_original_info['formats'] = l10n('Formats: %s', implode(', ', $format_strings));
            $formats_ext_info = json_encode($formats_exts);
        }

        $extTab = explode('.', is_scalar($formats_original_info['file'] ?? null) ? (string) $formats_original_info['file'] : '');

        $formats_original_info['ext'] = l10n('%s file type', strtoupper(end($extTab)));

        $formats_original_info['u_edit'] = get_root_url().'admin.php?page=photo-'.$fmtId;

        $have_formats_original = true;
    } else {
        PageState::current()->addError(l10n('The original picture selected dosen\'t exists.'));
    }

}

// +-----------------------------------------------------------------------+
// |                             prepare form                              |
// +-----------------------------------------------------------------------+

require_once(PHPWG_ROOT_PATH.'admin/include/photos_add_direct_prepare.inc.php');

// +-----------------------------------------------------------------------+
// |                           sending html code                           |
// +-----------------------------------------------------------------------+

trigger_notify('loc_end_photo_add_direct');

$unique_exts_for_json = array_unique(
    array_map(
        strtolower(...),
        Config::uploadFormAllTypes() ? Config::fileExtensions() : Config::pictureExtensions()
    )
);

$template->assign([
  'ENABLE_FORMATS' => Config::isFormatsEnabled(),
  'DISPLAY_FORMATS' => $display_formats,
  'HAVE_FORMATS_ORIGINAL' => $have_formats_original,
  'FORMATS_ORIGINAL_INFO' => $formats_original_info,
  'FORMATS_EXT_INFO' => $formats_ext_info,
  'SWITCH_FORMAT_MODE_URL' => get_root_url().'admin.php?page=photos_add'.($display_formats ? '' : '&formats'),
  'format_ext' =>  implode(',', Config::formatExtensions()),
  'str_format_ext' =>  implode(', ', Config::formatExtensions()),
  'page_data_json' => json_encode([
      'pwg_token' => get_pwg_token(),
      'chunk_size' => Config::uploadFormChunkSize().'kb',
      'max_file_size' => Config::uploadFormMaxFileSize().'mb',
      'albumSummary_label' => l10n('Album "%s" now contains %d photos'),
      'batch_Label' => l10n('Manage this set of %d photos'),
      'file_ext' => implode(',', $unique_exts_for_json),
      'formatMode' => $display_formats,
      'format_ext' => implode(',', Config::formatExtensions()),
      'format_remove' => l10n('Remove'),
      'format_update_warning' => l10n('This format already exists, it will be overwritten !'),
      'formatsAdded_label' => l10n('%d formats added for %d photos'),
      'formatsUpdated_label' => l10n('%d formats updated for %d photos'),
      'haveFormatsOriginal' => $have_formats_original,
      'imageFormatsExtensions' => $formats_ext_info ?? '',
      'nb_albums' => (int) ($nb_albums ?? 0),
      'originalImageId' => $have_formats_original ? (is_numeric($formats_original_info['id'] ?? null) ? (int) $formats_original_info['id'] : -1) : -1,
      'photosAdded_label' => l10n('%d photos uploaded'),
      'photosUpdated_label' => l10n('%d photos updated'),
      'related_categories_ids' => $selected_category ?? [],
      'str_and_X_others' => l10n('and %d more'),
      'str_drop_album_ab' => l10n('Drop into album'),
      'str_format_warning' => l10n('Error when trying to detect formats'),
      'str_format_warning_multiple' => l10n('There is multiple image in the database with the following names : %s.'),
      'str_format_warning_notFound' => l10n('No picture found with the following name : %s.'),
      'str_upload_in_progress' => l10n('Upload in progress'),
  ], JSON_HEX_TAG | JSON_UNESCAPED_UNICODE),
]);

$template->assign_var_from_handle('ADMIN_CONTENT', 'photos_add');
