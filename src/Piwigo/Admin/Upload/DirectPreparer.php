<?php

declare(strict_types=1);

namespace Piwigo\Admin\Upload;

use Piwigo\Admin\AdminService;
use Piwigo\Admin\Image\PwgImage;
use Piwigo\Category\CategoryRepository;
use Piwigo\Config\Config;
use Piwigo\Core\ServiceLocator;
use Piwigo\Image\ImageRepository;
use Piwigo\Template\TemplateRegistry;

final class DirectPreparer
{
    public function prepare(string $photosAddBaseUrl): void
    {
        $tpl = TemplateRegistry::current();

        $tpl->assign([
            'F_ADD_ACTION' => $photosAddBaseUrl,
            'chunk_size' => Config::uploadFormChunkSize(),
            'max_file_size' => Config::uploadFormMaxFileSize(),
            'ADMIN_PAGE_TITLE' => l10n('Upload Photos'),
        ]);

        if (PwgImage::getLibrary() == 'gd') {
            $fudge_factor = 1.7;
            $available_memory = (int) ServiceLocator::get(UploadService::class)->getIniSize('memory_limit') - memory_get_usage();
            $max_upload_width = round(sqrt($available_memory / (2 * $fudge_factor)));
            $max_upload_height = round(2 * $max_upload_width / 3);
            $max_upload_width = round($max_upload_width / 100) * 100;
            $max_upload_height = round($max_upload_height / 100) * 100;
            $max_upload_resolution = floor($max_upload_width * $max_upload_height / (1000000));
            if ($max_upload_resolution < 25) {
                $tpl->assign([
                    'max_upload_width' => $max_upload_width,
                    'max_upload_height' => $max_upload_height,
                    'max_upload_resolution' => $max_upload_resolution,
                ]);
            }
        }

        if (Config::originalResize()) {
            $tpl->assign([
                'original_resize_maxwidth' => Config::originalResizeMaxwidth(),
                'original_resize_maxheight' => Config::originalResizeMaxheight(),
            ]);
        }

        $tpl->assign([
            'form_action' => $photosAddBaseUrl,
            'pwg_token' => get_pwg_token(),
        ]);

        $unique_exts = array_unique(
            array_map(
                strtolower(...),
                Config::uploadFormAllTypes()
                    ? Config::fileExtensions()
                    : Config::pictureExtensions()
            )
        );

        $tpl->assign([
            'upload_file_types' => implode(', ', $unique_exts),
            'file_exts' => implode(',', $unique_exts),
        ]);

        $selected_category = [];

        if (isset($_GET['album'])) {
            check_input_parameter('album', $_GET, false, PATTERN_ID);
            $album_id_int = is_scalar($_GET['album']) ? (int) $_GET['album'] : 0;
            $cat = ServiceLocator::get(CategoryRepository::class)->findCategoryById($album_id_int);
            if ($cat !== null) {
                $selected_category = [$_GET['album']];
                $tpl->assign('ADD_TO_ALBUM', get_cat_display_name_cache(
                    is_scalar($cat['uppercats']) ? (string) $cat['uppercats'] : '',
                    null
                ));
            } else {
                $album_id = is_scalar($_GET['album']) ? (string) $_GET['album'] : '';
                fatal_error('[Hacking attempt] the album id = "' . $album_id . '" is not valid');
            }
        } else {
            $last_cat = ServiceLocator::get(ImageRepository::class)->findLastUploadedCategoryInfo();
            if ($last_cat !== null) {
                $selected_category = [$last_cat['category_id']];
                $selected_category_name = get_cat_display_name_cache((string) $last_cat['uppercats'], null);
                $tpl->assign('selected_category_name', $selected_category_name);
            }
        }

        $tpl->assign('selected_category', $selected_category);
        $nb_albums = ServiceLocator::get(CategoryRepository::class)->countAll();
        $tpl->assign('NB_ALBUMS', $nb_albums);

        $selected_level = $_POST['level'] ?? 0;
        $tpl->assign([
            'level_options' => get_privacy_level_options(),
            'level_options_selected' => [$selected_level],
        ]);

        $setup_errors = [];
        $error_message = ServiceLocator::get(UploadService::class)->readyForUploadMessage();
        if (!empty($error_message)) {
            $setup_errors[] = $error_message;
        }
        if (!function_exists('gd_info')) {
            $setup_errors[] = l10n('GD library is missing');
        }
        $tpl->assign([
            'setup_errors' => $setup_errors,
            'CACHE_KEYS' => ServiceLocator::get(AdminService::class)->getAdminClientCacheKeys(['categories']),
        ]);

        if (isset($_GET['hide_warnings'])) {
            $_SESSION['upload_hide_warnings'] = true;
        }
        if (!isset($_SESSION['upload_hide_warnings'])) {
            $setup_warnings = [];
            if (Config::useExif() && !function_exists('exif_read_data')) {
                $setup_warnings[] = l10n('Exif extension not available, admin should disable exif use');
            }
            if (ServiceLocator::get(UploadService::class)->getIniSize('upload_max_filesize') > ServiceLocator::get(UploadService::class)->getIniSize('post_max_size')) {
                $setup_warnings[] = l10n(
                    'In your php.ini file, the upload_max_filesize (%sB) is bigger than post_max_size (%sB), you should change this setting',
                    ServiceLocator::get(UploadService::class)->getIniSize('upload_max_filesize', false),
                    ServiceLocator::get(UploadService::class)->getIniSize('post_max_size', false)
                );
            }
            if (ServiceLocator::get(UploadService::class)->getIniSize('upload_max_filesize') < Config::uploadFormChunkSize() * 1024) {
                $setup_warnings[] = sprintf(
                    'Piwigo setting upload_form_chunk_size (%ukB) should be smaller than PHP configuration setting upload_max_filesize (%ukB)',
                    Config::uploadFormChunkSize(),
                    ceil((int) ServiceLocator::get(UploadService::class)->getIniSize('upload_max_filesize') / 1024)
                );
            }
            $tpl->assign([
                'setup_warnings' => $setup_warnings,
                'hide_warnings_link' => $photosAddBaseUrl . '&amp;hide_warnings=1',
            ]);
        }
    }
}
