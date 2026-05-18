<?php

declare(strict_types=1);

namespace Piwigo\Admin\Upload;

use Latte\Runtime\Html;
use Piwigo\Admin\AdminService;
use Piwigo\Admin\Image\PwgImage;
use Piwigo\Category\CategoryRepository;
use Piwigo\Config\Config;
use Piwigo\Core\Lang;
use Piwigo\Core\ValidationPattern;
use Piwigo\Csrf\CsrfService;
use Piwigo\Html\HtmlService;
use Piwigo\Image\ImageRepository;
use Piwigo\Session\Session;
use Piwigo\Template\TemplateRegistry;
use Piwigo\Validation\InputValidator;

final readonly class DirectPreparer
{
    public function __construct(
        private AdminService $adminService,
        private CategoryRepository $categoryRepository,
        private HtmlService $htmlService,
        private ImageRepository $imageRepository,
        private UploadService $uploadService,
        private CsrfService $csrfService,
        private InputValidator $inputValidator,
        private Session $session,
    ) {
    }

    public function prepare(string $photosAddBaseUrl): void
    {
        $tpl = TemplateRegistry::current();

        $tpl->assign([
            'F_ADD_ACTION' => $photosAddBaseUrl,
            'chunk_size' => Config::uploadFormChunkSize(),
            'max_file_size' => Config::uploadFormMaxFileSize(),
            'ADMIN_PAGE_TITLE' => Lang::t('Upload Photos'),
        ]);

        if (PwgImage::getLibrary() == 'gd') {
            $fudge_factor = 1.7;
            $available_memory = (float) ((int) $this->uploadService->getIniSize('memory_limit') - memory_get_usage());
            $max_upload_width = round(sqrt($available_memory / (2.0 * $fudge_factor)));
            $max_upload_height = round(2.0 * $max_upload_width / 3.0);
            $max_upload_width = round($max_upload_width / 100.0) * 100.0;
            $max_upload_height = round($max_upload_height / 100.0) * 100.0;
            $max_upload_resolution = floor($max_upload_width * $max_upload_height / 1000000.0);
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
            'pwg_token' => $this->csrfService->getToken(),
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
            $this->inputValidator->check('album', $_GET, false, ValidationPattern::ID);
            $album_id_int = is_scalar($_GET['album']) ? (int) $_GET['album'] : 0;
            $cat = $this->categoryRepository->findCategoryById($album_id_int);
            if ($cat !== null) {
                $selected_category = [$_GET['album']];
                $tpl->assign('ADD_TO_ALBUM', new Html($this->htmlService->getCatDisplayNameCache(
                    is_string($cat['uppercats'] ?? null) ? $cat['uppercats'] : '',
                    null
                )));
            } else {
                $rawAlbum = $_GET['album'];
                $album_id = is_string($rawAlbum) ? $rawAlbum : '';
                HtmlService::fatalError('[Hacking attempt] the album id = "' . $album_id . '" is not valid');
            }
        } else {
            $last_cat = $this->imageRepository->findLastUploadedCategoryInfo();
            if ($last_cat !== null) {
                $selected_category = [$last_cat['category_id']];
                $selected_category_name = $this->htmlService->getCatDisplayNameCache($last_cat['uppercats'], null);
                $tpl->assign('selected_category_name', new Html($selected_category_name));
            }
        }

        $tpl->assign('selected_category', $selected_category);
        $nb_albums = $this->categoryRepository->countAll();
        $tpl->assign('NB_ALBUMS', $nb_albums);

        $selected_level = $_POST['level'] ?? 0;
        $tpl->assign([
            'level_options' => $this->htmlService->getPrivacyLevelOptions(),
            'level_options_selected' => [$selected_level],
        ]);

        $setup_errors = [];
        $error_message = $this->uploadService->readyForUploadMessage();
        if ($error_message !== null && $error_message !== '') {
            $setup_errors[] = $error_message;
        }
        if (!function_exists('gd_info')) {
            $setup_errors[] = Lang::t('GD library is missing');
        }
        $tpl->assign([
            'setup_errors' => $setup_errors,
            'CACHE_KEYS' => $this->adminService->getAdminClientCacheKeys(['categories']),
        ]);

        if (isset($_GET['hide_warnings'])) {
            $this->session->uploadHideWarnings = true;
        }
        if (!$this->session->uploadHideWarnings) {
            $setup_warnings = [];
            if (Config::useExif() && !function_exists('exif_read_data')) {
                $setup_warnings[] = Lang::t('Exif extension not available, admin should disable exif use');
            }
            if ($this->uploadService->getIniSize('upload_max_filesize') > $this->uploadService->getIniSize('post_max_size')) {
                $setup_warnings[] = Lang::t(
                    'In your php.ini file, the upload_max_filesize (%sB) is bigger than post_max_size (%sB), you should change this setting',
                    $this->uploadService->getIniSize('upload_max_filesize', false),
                    $this->uploadService->getIniSize('post_max_size', false)
                );
            }
            if ($this->uploadService->getIniSize('upload_max_filesize') < Config::uploadFormChunkSize() * 1024) {
                $setup_warnings[] = sprintf(
                    'Piwigo setting upload_form_chunk_size (%ukB) should be smaller than PHP configuration setting upload_max_filesize (%ukB)',
                    Config::uploadFormChunkSize(),
                    ceil((int) $this->uploadService->getIniSize('upload_max_filesize') / 1024)
                );
            }
            $tpl->assign([
                'setup_warnings' => $setup_warnings,
                'hide_warnings_link' => $photosAddBaseUrl . '&hide_warnings=1',
            ]);
        }
    }
}
