<?php

declare(strict_types=1);

namespace Piwigo\Admin;

use Doctrine\DBAL\Connection;
use Piwigo\Activity\ActivityRepository;
use Piwigo\Activity\ActivityService;
use Piwigo\Admin\Image\PwgImage;
use Piwigo\Admin\Upload\UploadService;
use Piwigo\Core\AppInfo;
use Piwigo\Core\Env;
use Piwigo\Core\Lang;
use Piwigo\Core\RedirectServiceInterface;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Core\ValidationPattern;
use Piwigo\Db\BatchWriter;
use Piwigo\Db\DbConnection;
use Piwigo\Db\Tables;
use Piwigo\Html\HtmlService;
use Piwigo\Image\DerivativeImage;
use Piwigo\Image\ImageRepository;
use Piwigo\Image\ImageService;
use Piwigo\Image\ImageStdParams;
use Piwigo\Image\SrcImage;
use Piwigo\Users\PreferencesService;
use Piwigo\Users\UserRepository;

/**
 * Ported from admin/photos_add_direct.php (the "direct" tab of the
 * "photos_add" page slug, dispatched by PhotosAddSubController) plus
 * admin/include/photos_add_direct_prepare.inc.php's form-prep body,
 * folded in as prepareUploadForm() (zero external callers, genuinely
 * page-specific, unlike the shared admin/include/*.inc.php files this
 * project has kept as real includes elsewhere).
 *
 * P23 batch 6e fix: the batch action was reachable via a plain GET with
 * no check_pwg_token() -- unlike this project's other mutating admin
 * actions -- and the JS link that drives it (admin/themes/default/js/
 * photos_add_direct.js) carried no token either, a real CSRF gap closed
 * here the same way P23 batch 6d closed the equivalent gap in
 * PictureModifyPageRenderer's sync_metadata action.
 */
final class PhotosAddDirectPageRenderer
{
    public function __construct(
        private readonly RedirectServiceInterface $redirectService,
        private readonly UrlServiceInterface $urlService,
    ) {}

    /**
     * P23 batch 8f-1: relocated from admin/include/functions.php's
     * PHOTOS_ADD_BASE_URL define() (formerly relocated there from the
     * deleted admin/photos_add.php, P23 batch 8a) -- get_root_url() is a
     * request-time value, not a compile-time constant expression, so this
     * can't become a real class `const`; a static method is the SEC-60-
     * compliant equivalent (src/Piwigo/ forbids define()). CoreTabs
     * (the other real reader) calls this directly, supplying its own
     * UrlServiceInterface (Legacy Coupling Retirement Phase 4c).
     */
    public static function baseUrl(UrlServiceInterface $urlService): string
    {
        return $urlService->getRootUrl() . 'admin.php?page=photos_add';
    }

    public function render(): void
    {
        $template = \Piwigo\Template\CurrentTemplate::get();

        $htmlRenderer = new HtmlService();
        $conn = DbConnection::build();

        $user_id = \Piwigo\Users\CurrentUser::get()->id;

        // +-------------------------------------------------------------------+
        // |                        batch management request                   |
        // +-------------------------------------------------------------------+

        if (isset($_GET['batch'])) {
            new \Piwigo\Csrf\CsrfService()
                ->checkOrFail(new HtmlService(), $this->redirectService);
            new \Piwigo\Validation\InputValidator()
                ->validate('batch', $_GET, false, '/^\d+(,\d+)*$/');

            $query = '
DELETE FROM ' . Tables::caddie() . '
  WHERE user_id = ' . $user_id . '
;';
            $conn->executeStatement($query);

            $inserts = [];
            $batch_param = $_GET['batch'];
            foreach (array_unique(explode(',', is_string($batch_param) ? $batch_param : '')) as $image_id) {
                $inserts[] = [
                    'user_id' => $user_id,
                    'element_id' => $image_id,
                ];
            }
            new BatchWriter($conn)
                ->massInsert(
                    Tables::caddie(),
                    array_keys($inserts[0]),
                    $inserts
                );

            $this->redirectService->redirect($this->urlService->getRootUrl() . 'admin.php?page=batch_manager&filter=prefilter-caddie');
        }

        if ((bool) new PreferencesService(new UserRepository($conn))->getParam('promote-mobile-apps', true)) {
            $query = '
SELECT registration_date
  FROM ' . Tables::userInfos() . '
  WHERE registration_date IS NOT NULL
  ORDER BY user_id ASC
  LIMIT 1
;';
            $row = $conn->fetchNumeric($query);
            $register_date = $row !== false ? $row[0] : null;

            $query = '
SELECT COUNT(*)
  FROM ' . Tables::categories() . '
;';
            $row = $conn->fetchNumeric($query);
            $nb_cats = $row !== false ? $row[0] : 0;

            $query = '
SELECT COUNT(*)
  FROM ' . Tables::images() . '
;';
            $row = $conn->fetchNumeric($query);
            $nb_images = $row !== false ? $row[0] : 0;

            // To see the mobile app promote, the account must have 2 weeks
            // ancient, 3 albums created and 30 photos uploaded. Anchored on
            // Env::now() (real behavior outside test mode is unaffected) --
            // dormant right now since the fixture never has 3 albums/30
            // photos, but consistent with every other date computation in
            // this project's admin pages.
            $two_weeks_ago = (clone Env::now())
                ->modify('-2 weeks');
            $register_date_str = is_scalar($register_date) ? (string) $register_date : '';
            $template->assign('PROMOTE_MOBILE_APPS', (strtotime($register_date_str) < $two_weeks_ago->getTimestamp() and $nb_cats >= 3 and $nb_images >= 30));
        } else {
            $template->assign('PROMOTE_MOBILE_APPS', false);
        }

        $template->assign('PHPWG_URL', AppInfo::URL);

        // +-------------------------------------------------------------------+
        // |                             Formats Mode                          |
        // +-------------------------------------------------------------------+

        $display_formats = \Piwigo\Config\Config::isFormatsEnabled() && isset($_GET['formats']);

        $have_formats_original = false;
        $formats_original_info = [];
        $formats_ext_info = null;

        // If URL parameter isn't empty
        if ($display_formats && (bool) $_GET['formats']) {
            new \Piwigo\Validation\InputValidator()
                ->validate('formats', $_GET, false, ValidationPattern::ID, false);

            $formats_id_param = $_GET['formats'];
            $formats_original_info = new ImageService(new ImageRepository($conn), new ActivityService(new ActivityRepository($conn)))
                ->getImageInfos(is_string($formats_id_param) ? $formats_id_param : '', $htmlRenderer);
            if ((bool) $formats_original_info) {
                $src_image = new SrcImage($formats_original_info);

                $formats_original_info['src'] = DerivativeImage::url(ImageStdParams::SQUARE, $src_image);

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
                $formats = $conn->fetchAllAssociative($query);

                if ($formats !== []) {
                    $format_strings = [];
                    $formats_exts = [];

                    foreach ($formats as $format) {
                        $format_ext = is_scalar($format['ext']) ? (string) $format['ext'] : '';
                        $format_filesize = is_numeric($format['filesize']) ? ((float) $format['filesize']) / 1024.0 : 0.0;
                        $format_strings[] = sprintf('%s (%.2fMB)', $format_ext, $format_filesize);
                        $formats_exts[] = strtolower($format_ext);
                    }

                    $formats_original_info['formats'] = Lang::t('Formats: %s', implode(', ', $format_strings));
                    $formats_ext_info = json_encode($formats_exts);
                }

                $formats_file = $formats_original_info['file'];
                $extTab = explode('.', is_string($formats_file) ? $formats_file : '');

                $formats_original_info['ext'] = Lang::t('%s file type', strtoupper(end($extTab)));

                $formats_original_info['u_edit'] = $this->urlService->getRootUrl() . 'admin.php?page=photo-' . $formats_image_id;

                $have_formats_original = true;
            } else {
                \Piwigo\Core\PageState::current()->addError(Lang::t('The original picture selected dosen\'t exists.'));
            }
        }

        // +-------------------------------------------------------------------+
        // |                             prepare form                          |
        // +-------------------------------------------------------------------+

        $this->prepareUploadForm($conn);

        // +-------------------------------------------------------------------+
        // |                           sending html code                       |
        // +-------------------------------------------------------------------+

        \Piwigo\PluginConfig\EventDispatcher::get()->triggerNotify('loc_end_photo_add_direct');

        $conf_format_ext = \Piwigo\Config\Config::formatExtensions();

        $template->assign([
            'ENABLE_FORMATS' => \Piwigo\Config\Config::isFormatsEnabled(),
            'DISPLAY_FORMATS' => $display_formats,
            'HAVE_FORMATS_ORIGINAL' => $have_formats_original,
            'FORMATS_ORIGINAL_INFO' => $formats_original_info,
            'FORMATS_EXT_INFO' => $formats_ext_info,
            'SWITCH_FORMAT_MODE_URL' => $this->urlService->getRootUrl() . 'admin.php?page=photos_add' . ($display_formats ? '' : '&formats'),
            'format_ext' => implode(',', array_filter($conf_format_ext, is_string(...))),
            'str_format_ext' => implode(', ', array_filter($conf_format_ext, is_string(...))),
        ]);

        $template->assign_var_from_handle('ADMIN_CONTENT', 'photos_add');
    }

    /**
     * Ported from admin/include/photos_add_direct_prepare.inc.php -- upload
     * form setup (memory-limit math, default album selection, setup
     * errors/warnings). No real external callers besides this class's own
     * render(), unlike the shared admin/include/*.inc.php files this
     * project has kept as real includes elsewhere.
     */
    private function prepareUploadForm(Connection $conn): void
    {
        $template = \Piwigo\Template\CurrentTemplate::get();

        $htmlRenderer = new HtmlService();

        $uploadService = new UploadService();

        // +-------------------------------------------------------------------+
        // | Photo selection                                                    |
        // +-------------------------------------------------------------------+

        $template->assign(
            [
                'F_ADD_ACTION' => self::baseUrl($this->urlService),
                'chunk_size' => \Piwigo\Config\Config::uploadFormChunkSize(),
                'max_file_size' => \Piwigo\Config\Config::uploadFormMaxFileSize(),
                'ADMIN_PAGE_TITLE' => Lang::t('Upload Photos'),
            ]
        );

        // what is the maximum number of pixels permitted by the memory_limit?
        if (PwgImage::get_library() === 'gd') {
            $fudge_factor = 1.7;
            $memory_limit = $uploadService->getIniSize('memory_limit');
            // memory_limit is a core php.ini directive, always present
            assert($memory_limit !== false);
            $available_memory = (int) $memory_limit - memory_get_usage();
            $max_upload_width = round(sqrt((float) $available_memory / (2.0 * $fudge_factor)));
            $max_upload_height = round(2.0 * $max_upload_width / 3.0);

            // we don't want dimensions like 2995x1992 but 3000x2000
            $max_upload_width = round($max_upload_width / 100.0) * 100.0;
            $max_upload_height = round($max_upload_height / 100.0) * 100.0;

            $max_upload_resolution = floor($max_upload_width * $max_upload_height / 1000000.0);

            // no need to display a limitation warning if the limitation is huge like 20MP
            if ($max_upload_resolution < 25) {
                $template->assign(
                    [
                        'max_upload_width' => $max_upload_width,
                        'max_upload_height' => $max_upload_height,
                        'max_upload_resolution' => $max_upload_resolution,
                    ]
                );
            }
        }

        // warn the user if the picture will be resized after upload
        if (\Piwigo\Config\Config::originalResize()) {
            $template->assign(
                [
                    'original_resize_maxwidth' => \Piwigo\Config\Config::originalResizeMaxwidth(),
                    'original_resize_maxheight' => \Piwigo\Config\Config::originalResizeMaxheight(),
                ]
            );
        }

        $template->assign(
            [
                'form_action' => self::baseUrl($this->urlService),
                'pwg_token' => new \Piwigo\Csrf\CsrfService()
                    ->getToken(),
            ]
        );

        $upload_extensions = (\Piwigo\Config\Config::uploadFormAllTypes()) ? \Piwigo\Config\Config::fileExtensions() : \Piwigo\Config\Config::pictureExtensions();
        $unique_exts = array_unique(array_map(strtolower(...), $upload_extensions));

        $template->assign(
            [
                'upload_file_types' => implode(', ', $unique_exts),
                'file_exts' => implode(',', $unique_exts),
            ]
        );

        // +-------------------------------------------------------------------+
        // | Categories                                                         |
        // +-------------------------------------------------------------------+

        // we need to know the category in which the last photo was added
        $selected_category = [];

        if (isset($_GET['album'])) {
            // set the category from get url or ...
            new \Piwigo\Validation\InputValidator()
                ->validate('album', $_GET, false, ValidationPattern::ID);

            // check_input_parameter() above validated (or killed the request via
            // fatal_error()) that a non-empty $_GET['album'] matches ValidationPattern::ID
            // (digits only) -- it doesn't retype the superglobal though, so we
            // narrow once here and reuse this variable below.
            $album_id = is_numeric($_GET['album']) ? (int) $_GET['album'] : null;

            // test if album really exists
            $query = '
SELECT id, uppercats
  FROM ' . Tables::categories() . '
  WHERE id = ' . ($album_id ?? 0) . '
;';
            $rows = $conn->fetchAllAssociative($query);
            if ($album_id !== null && count($rows) === 1) {
                $selected_category = [$album_id];

                $cat = $rows[0];
                $uppercats = $cat['uppercats'];
                // uppercats is a NOT NULL varchar column (install/piwigo_structure-mysql.sql)
                assert(is_string($uppercats));
                $template->assign('ADD_TO_ALBUM', $htmlRenderer->getCatDisplayNameCache($uppercats, null));
            } else {
                $htmlRenderer->fatalError('[Hacking attempt] the album id = "' . ($album_id ?? '') . '" is not valid');
            }
        } else {
            // we need to know the category in which the last photo was added
            $query = '
SELECT category_id, c.uppercats
  FROM ' . Tables::images() . ' AS i
    JOIN ' . Tables::imageCategory() . ' AS ic ON image_id = i.id
    JOIN ' . Tables::categories() . ' AS c ON category_id = c.id
  ORDER BY i.id DESC
  LIMIT 1
;
';
            $rows = $conn->fetchAllAssociative($query);
            if (count($rows) > 0) {
                $row = $rows[0];
                $selected_category = [$row['category_id']];
                $uppercats = $row['uppercats'];
                // uppercats is a NOT NULL varchar column (install/piwigo_structure-mysql.sql)
                assert(is_string($uppercats));
                $selected_category_name = $htmlRenderer->getCatDisplayNameCache($uppercats, null);
                $template->assign('selected_category_name', $selected_category_name);
            }
        }

        // existing album
        $template->assign('selected_category', $selected_category);

        // how many existing albums?
        $query = '
SELECT
    COUNT(*)
  FROM ' . Tables::categories() . '
;';
        $row = $conn->fetchNumeric($query);
        $nb_albums = $row !== false ? $row[0] : 0;
        $template->assign('NB_ALBUMS', $nb_albums);

        // image level options
        $selected_level = $_POST['level'] ?? 0;
        $template->assign(
            [
                'level_options' => \Piwigo\Permission\PermissionService::getPrivacyLevelOptions(),
                'level_options_selected' => [$selected_level],
            ]
        );

        // +-------------------------------------------------------------------+
        // | Setup errors/warnings                                              |
        // +-------------------------------------------------------------------+

        // Errors
        $setup_errors = [];

        $error_message = $uploadService->readyForUploadMessage();
        if (! in_array($error_message, [null, ''], true)) {
            $setup_errors[] = $error_message;
        }

        if (! function_exists('gd_info')) {
            $setup_errors[] = Lang::t('GD library is missing');
        }

        $template->assign([
            'setup_errors' => $setup_errors,
            'CACHE_KEYS' => AdminUiHelper::getAdminClientCacheKeys($this->urlService, ['categories']),
        ]);

        // Warnings
        if (isset($_GET['hide_warnings'])) {
            $_SESSION['upload_hide_warnings'] = true;
        }

        if (! isset($_SESSION['upload_hide_warnings'])) {
            $setup_warnings = [];

            if (\Piwigo\Config\Config::useExif() and ! function_exists('exif_read_data')) {
                $setup_warnings[] = Lang::t('Exif extension not available, admin should disable exif use');
            }

            if ($uploadService->getIniSize('upload_max_filesize') > $uploadService->getIniSize('post_max_size')) {
                $setup_warnings[] = Lang::t(
                    'In your php.ini file, the upload_max_filesize (%sB) is bigger than post_max_size (%sB), you should change this setting',
                    $uploadService->getIniSize('upload_max_filesize', false),
                    $uploadService->getIniSize('post_max_size', false)
                );
            }

            $upload_form_chunk_size = \Piwigo\Config\Config::uploadFormChunkSize();
            if ($uploadService->getIniSize('upload_max_filesize') < $upload_form_chunk_size * 1024) {
                $upload_max_filesize = $uploadService->getIniSize('upload_max_filesize');
                // upload_max_filesize is a core php.ini directive, always present
                assert($upload_max_filesize !== false);
                $setup_warnings[] = sprintf(
                    'Piwigo setting upload_form_chunk_size (%ukB) should be smaller than PHP configuration setting upload_max_filesize (%ukB)',
                    $upload_form_chunk_size,
                    (int) ceil((int) $upload_max_filesize / 1024)
                );
            }

            $template->assign(
                [
                    'setup_warnings' => $setup_warnings,
                    'hide_warnings_link' => self::baseUrl($this->urlService) . '&amp;hide_warnings=1',
                ]
            );
        }
    }
}
