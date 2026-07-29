<?php

declare(strict_types=1);

namespace Piwigo\Admin;

use Doctrine\DBAL\Connection;
use Piwigo\Admin\Image\PwgImage;
use Piwigo\Admin\Upload\UploadService;
use Piwigo\Core\AppInfo;
use Piwigo\Core\Env;
use Piwigo\Core\Lang;
use Piwigo\Core\RedirectServiceInterface;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Db\DbConnection;
use Piwigo\Image\DerivativeImage;
use Piwigo\Image\ImageStdParams;
use Piwigo\Image\SrcImage;

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
 * actions -- and the JS link that drives it (themes/admin/default/js/
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

        $htmlRenderer = \Piwigo\Bootstrap\PresentationAccessor::htmlService();
        $conn = DbConnection::build();

        $user_id = \Piwigo\Users\CurrentUser::get()->id->value;

        $photosAddDirectRequest = Request\PhotosAddDirectRequest::fromGlobals(\Piwigo\Config\CurrentConfig::isFormatsEnabled());

        // +-------------------------------------------------------------------+
        // |                        batch management request                   |
        // +-------------------------------------------------------------------+

        if ($photosAddDirectRequest->batchPresent) {
            new \Piwigo\Csrf\CsrfService()
                ->checkOrFail(\Piwigo\Bootstrap\PresentationAccessor::htmlService(), $this->redirectService);

            new \Piwigo\Caddie\CaddieRepository($conn)
                ->replaceForUser(
                    $user_id,
                    array_values(array_map(intval(...), array_unique(explode(',', $photosAddDirectRequest->batch))))
                );

            $this->redirectService->redirect($this->urlService->getRootUrl() . 'admin.php?page=batch_manager&filter=prefilter-caddie');
        }

        if ((bool) \Piwigo\Bootstrap\CoreDomainAccessor::preferencesService()->getParam('promote-mobile-apps', true)) {
            $register_date = \Piwigo\Db\EntityManagerFactory::build($conn)->getRepository(\Piwigo\Users\UserInfoEntity::class)
                ->findEarliestRegistrationDate();
            $nb_cats = \Piwigo\Db\EntityManagerFactory::build($conn)->getRepository(\Piwigo\Category\CategoryEntity::class)
                ->countAllCategories();
            $nb_images = \Piwigo\Db\EntityManagerFactory::build($conn)->getRepository(\Piwigo\Image\ImageEntity::class)
                ->countAllImages();

            // To see the mobile app promote, the account must have 2 weeks
            // ancient, 3 albums created and 30 photos uploaded. Anchored on
            // Env::now() (real behavior outside test mode is unaffected) --
            // dormant right now since the fixture never has 3 albums/30
            // photos, but consistent with every other date computation in
            // this project's admin pages.
            $two_weeks_ago = (clone Env::now())
                ->modify('-2 weeks');
            $register_date_str = $register_date ?? '';
            $template->assign('PROMOTE_MOBILE_APPS', (strtotime($register_date_str) < $two_weeks_ago->getTimestamp() and $nb_cats >= 3 and $nb_images >= 30));
        } else {
            $template->assign('PROMOTE_MOBILE_APPS', false);
        }

        $template->assign('PHPWG_URL', AppInfo::URL);

        // +-------------------------------------------------------------------+
        // |                             Formats Mode                          |
        // +-------------------------------------------------------------------+

        $display_formats = $photosAddDirectRequest->displayFormats;

        $have_formats_original = false;
        $formats_original_info = [];
        $formats_ext_info = null;

        // If URL parameter isn't empty
        if ($photosAddDirectRequest->formatsTruthy) {
            $formats_original_info = \Piwigo\Bootstrap\CoreDomainAccessor::imageService()
                ->getImageInfos($photosAddDirectRequest->formatsId, $htmlRenderer);
            if ((bool) $formats_original_info) {
                $src_image = new SrcImage($formats_original_info);

                $formats_original_info['src'] = DerivativeImage::url(ImageStdParams::SQUARE, $src_image);

                $formats_image_id = $formats_original_info['id'];

                $formats = \Piwigo\Db\EntityManagerFactory::build($conn)->getRepository(\Piwigo\Image\ImageEntity::class)
                    ->findFormatsForImage($formats_image_id);

                if ($formats !== []) {
                    $format_strings = [];
                    $formats_exts = [];

                    foreach ($formats as $format) {
                        $format_ext = $format->ext;
                        $format_filesize = ((float) ($format->filesize ?? 0)) / 1024.0;
                        $format_strings[] = sprintf('%s (%.2fMB)', $format_ext, $format_filesize);
                        $formats_exts[] = strtolower($format_ext);
                    }

                    $formats_original_info['formats'] = Lang::t('Formats: %s', implode(', ', $format_strings));
                    $formats_ext_info = json_encode($formats_exts);
                }

                $extTab = explode('.', $formats_original_info['file']);

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

        $this->prepareUploadForm($conn, $photosAddDirectRequest);

        // +-------------------------------------------------------------------+
        // |                           sending html code                       |
        // +-------------------------------------------------------------------+

        \Piwigo\PluginConfig\EventDispatcher::get()->triggerNotify('loc_end_photo_add_direct');

        $conf_format_ext = \Piwigo\Config\CurrentConfig::formatExtensions();

        $template->assign([
            'ENABLE_FORMATS' => \Piwigo\Config\CurrentConfig::isFormatsEnabled(),
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
    private function prepareUploadForm(Connection $conn, Request\PhotosAddDirectRequest $photosAddDirectRequest): void
    {
        $template = \Piwigo\Template\CurrentTemplate::get();

        $htmlRenderer = \Piwigo\Bootstrap\PresentationAccessor::htmlService();

        $uploadService = new UploadService();

        // +-------------------------------------------------------------------+
        // | Photo selection                                                    |
        // +-------------------------------------------------------------------+

        $template->assign(
            [
                'F_ADD_ACTION' => self::baseUrl($this->urlService),
                'chunk_size' => \Piwigo\Config\CurrentConfig::uploadFormChunkSize(),
                'max_file_size' => \Piwigo\Config\CurrentConfig::uploadFormMaxFileSize(),
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
        if (\Piwigo\Config\CurrentConfig::originalResize()) {
            $template->assign(
                [
                    'original_resize_maxwidth' => \Piwigo\Config\CurrentConfig::originalResizeMaxwidth(),
                    'original_resize_maxheight' => \Piwigo\Config\CurrentConfig::originalResizeMaxheight(),
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

        $upload_extensions = (\Piwigo\Config\CurrentConfig::uploadFormAllTypes()) ? \Piwigo\Config\CurrentConfig::fileExtensions() : \Piwigo\Config\CurrentConfig::pictureExtensions();
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

        if ($photosAddDirectRequest->albumPresent) {
            // set the category from get url or ...
            $album_id = $photosAddDirectRequest->albumId;

            // test if album really exists
            $uppercats = \Piwigo\Db\EntityManagerFactory::build($conn)->getRepository(\Piwigo\Category\CategoryEntity::class)
                ->findCategoryUppercatsById($album_id ?? 0);
            if ($album_id !== null && $uppercats !== null) {
                $selected_category = [$album_id];

                $template->assign('ADD_TO_ALBUM', $htmlRenderer->getCatDisplayNameCache($uppercats, null));
            } else {
                $htmlRenderer->fatalError('[Hacking attempt] the album id = "' . ($album_id ?? '') . '" is not valid');
            }
        } else {
            // we need to know the category in which the last photo was added
            $mostRecentCategoryInfo = \Piwigo\Db\EntityManagerFactory::build($conn)->getRepository(\Piwigo\Image\ImageEntity::class)
                ->findMostRecentImageCategoryInfo();
            if ($mostRecentCategoryInfo !== null) {
                $selected_category = [$mostRecentCategoryInfo['category_id']];
                $uppercats = $mostRecentCategoryInfo['uppercats'];
                $selected_category_name = $htmlRenderer->getCatDisplayNameCache($uppercats, null);
                $template->assign('selected_category_name', $selected_category_name);
            }
        }

        // existing album
        $template->assign('selected_category', $selected_category);

        // how many existing albums?
        $nb_albums = \Piwigo\Db\EntityManagerFactory::build($conn)->getRepository(\Piwigo\Category\CategoryEntity::class)
            ->countAllCategories();
        $template->assign('NB_ALBUMS', $nb_albums);

        // image level options
        $selected_level = $photosAddDirectRequest->postLevel;
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
        if ($photosAddDirectRequest->hideWarningsPresent) {
            $_SESSION['upload_hide_warnings'] = true;
        }

        if (! isset($_SESSION['upload_hide_warnings'])) {
            $setup_warnings = [];

            if (\Piwigo\Config\CurrentConfig::useExif() and ! function_exists('exif_read_data')) {
                $setup_warnings[] = Lang::t('Exif extension not available, admin should disable exif use');
            }

            if ($uploadService->getIniSize('upload_max_filesize') > $uploadService->getIniSize('post_max_size')) {
                $setup_warnings[] = Lang::t(
                    'In your php.ini file, the upload_max_filesize (%sB) is bigger than post_max_size (%sB), you should change this setting',
                    $uploadService->getIniSize('upload_max_filesize', false),
                    $uploadService->getIniSize('post_max_size', false)
                );
            }

            $upload_form_chunk_size = \Piwigo\Config\CurrentConfig::uploadFormChunkSize();
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
