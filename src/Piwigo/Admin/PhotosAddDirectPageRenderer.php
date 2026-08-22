<?php

declare(strict_types=1);

namespace Piwigo\Admin;

use Doctrine\ORM\EntityManagerInterface;
use Piwigo\Admin\Event\PhotosAddDirectPageRendered;
use Piwigo\Admin\Image\ImageBackend;
use Piwigo\Admin\Projection\PhotosAddDirectView;
use Piwigo\Admin\Request\PhotosAddDirectRequest;
use Piwigo\Bootstrap\AdminAccessor;
use Piwigo\Caddie\CaddieEntity;
use Piwigo\Category\CategoryRepository;
use Piwigo\Common\ValueObject\ImageId;
use Piwigo\Config\CurrentConfig;
use Piwigo\Controller\Admin\Projection\AdminPageResult;
use Piwigo\Core\AppInfo;
use Piwigo\Core\Env;
use Piwigo\Core\HtmlRenderingInterface;
use Piwigo\Core\Lang;
use Piwigo\Core\PageState;
use Piwigo\Core\Paths;
use Piwigo\Core\RedirectServiceInterface;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Csrf\CsrfService;
use Piwigo\Image\DerivativeImage;
use Piwigo\Image\ImageEntity;
use Piwigo\Image\ImageService;
use Piwigo\Image\ImageStdParams;
use Piwigo\Image\Projection\SrcImageInfo;
use Piwigo\Image\SrcImage;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Template\CurrentTemplate;
use Piwigo\Template\Renderer;
use Piwigo\Users\CurrentUser;
use Piwigo\Users\PreferencesService;
use Piwigo\Users\UserRepository;
use Piwigo\Validation\InputValidator;

/**
 * Renders the "direct" tab of the "photos_add" page slug, dispatched by
 * PhotosAddSubController. prepareUploadForm() has no external callers and
 * is genuinely page-specific, unlike the shared admin/include/*.inc.php
 * files this project has kept as real includes elsewhere.
 *
 * The batch action requires a valid CSRF token (see checkOrFail() below):
 * its JS trigger (themes/admin/default/js/photos_add_direct.js) carries
 * no token of its own, so the check must happen here.
 */
final readonly class PhotosAddDirectPageRenderer
{
    public function __construct(
        private Lang $lang,
        private RedirectServiceInterface $redirectService,
        private UrlServiceInterface $urlService,
        private EventDispatcher $eventDispatcher,
        private PageState $pageState,
        private CurrentUser $currentUser,
        private CurrentTemplate $currentTemplate,
        private HtmlRenderingInterface $htmlRenderer,
        private ImageService $imageService,
        private PreferencesService $preferencesService,
        private CurrentConfig $currentConfig,
        private CsrfService $csrfService,
        private InputValidator $inputValidator,
        private EntityManagerInterface $entityManager,
        private Renderer $renderer,
        private Paths $paths,
    ) {}

    /**
     * get_root_url() is a request-time value, not a compile-time constant
     * expression, so this can't become a real class `const`; a static
     * method is the SEC-60-compliant equivalent (src/Piwigo/ forbids
     * define()). CoreTabs (the other real reader) calls this directly,
     * supplying its own UrlServiceInterface.
     */
    public static function baseUrl(UrlServiceInterface $urlService): string
    {
        return $urlService->getRootUrl() . 'admin.php?page=photos_add';
    }

    public function render(): AdminPageResult
    {
        $template = $this->currentTemplate->get();

        $htmlRenderer = $this->htmlRenderer;

        $user_id = $this->currentUser->get()
            ->id->value;

        $photosAddDirectRequest = PhotosAddDirectRequest::fromGlobals($this->currentConfig->isFormatsEnabled, $this->inputValidator);

        if ($photosAddDirectRequest->batchPresent) {
            $this->csrfService
                ->checkOrFail($this->htmlRenderer, $this->redirectService);

            $this->entityManager->getRepository(CaddieEntity::class)
                ->replaceForUser(
                    $user_id,
                    array_values(array_map(intval(...), array_unique(explode(',', $photosAddDirectRequest->batch))))
                );

            $this->redirectService->redirect($this->urlService->getRootUrl() . 'admin.php?page=batch_manager&filter=prefilter-caddie');
        }

        if ($this->preferencesService->getPromoteMobileApps() ?? true) {
            $register_date = new UserRepository($this->entityManager, $this->eventDispatcher, $this->currentConfig)
                ->findEarliestRegistrationDate();
            $nb_cats = new CategoryRepository($this->entityManager, $this->currentConfig)
                ->countAllCategories();
            $nb_images = $this->entityManager->getRepository(ImageEntity::class)
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
            $promote_mobile_apps = strtotime($register_date_str) < $two_weeks_ago->getTimestamp() && $nb_cats >= 3 && $nb_images >= 30;
        } else {
            $promote_mobile_apps = false;
        }

        $display_formats = $photosAddDirectRequest->displayFormats;

        $have_formats_original = false;
        $formats_original_info = [];
        $formats_ext_info = null;

        // If URL parameter isn't empty
        if ($photosAddDirectRequest->formatsTruthy) {
            $formats_original_info = $this->imageService
                ->getImageInfos($photosAddDirectRequest->formatsId, $htmlRenderer);
            if ((bool) $formats_original_info) {
                $src_image = new SrcImage(SrcImageInfo::fromRow($formats_original_info));

                $formats_original_info['src'] = DerivativeImage::url(ImageStdParams::SQUARE, $src_image);

                $formats_image_id = $formats_original_info['id'];

                $formats = $this->entityManager->getRepository(ImageEntity::class)
                    ->findFormatsForImage(ImageId::from($formats_image_id));

                if ($formats !== []) {
                    $format_strings = [];
                    $formats_exts = [];

                    foreach ($formats as $format) {
                        $format_ext = $format->ext;
                        $format_filesize = ((float) ($format->filesize ?? 0)) / 1024.0;
                        $format_strings[] = sprintf('%s (%.2fMB)', $format_ext, $format_filesize);
                        $formats_exts[] = strtolower($format_ext);
                    }

                    $formats_original_info['formats'] = $this->lang->t('Formats: %s', implode(', ', $format_strings));
                    $formats_ext_info = json_encode($formats_exts);
                }

                $extTab = explode('.', $formats_original_info['file']);

                $formats_original_info['ext'] = $this->lang->t('%s file type', strtoupper(end($extTab)));

                $formats_original_info['u_edit'] = $this->urlService->getRootUrl() . 'admin.php?page=photo-' . $formats_image_id;

                $have_formats_original = true;
            } else {
                $this->pageState->addError($this->lang->t('The original picture selected dosen\'t exists.'));
            }
        }

        $uploadFormData = $this->prepareUploadForm($photosAddDirectRequest);

        $this->eventDispatcher->dispatch(new PhotosAddDirectPageRendered());

        $conf_format_ext = $this->currentConfig->formatExtensions;

        $adminContent = $this->renderer->render(new PhotosAddDirectView(
            ...$uploadFormData,
            promoteMobileApps: $promote_mobile_apps,
            phpwgUrl: AppInfo::URL,
            enableFormats: $this->currentConfig->isFormatsEnabled,
            displayFormats: $display_formats,
            haveFormatsOriginal: $have_formats_original,
            formatsOriginalInfo: $formats_original_info,
            formatsExtInfo: $formats_ext_info,
            switchFormatModeUrl: $this->urlService->getRootUrl() . 'admin.php?page=photos_add' . ($display_formats ? '' : '&formats'),
            formatExt: implode(',', array_filter($conf_format_ext, is_string(...))),
            strFormatExt: implode(', ', array_filter($conf_format_ext, is_string(...))),
            colorscheme: $template->themeConf('colorscheme'),
            rootPath: $this->paths->root,
            pluploadCode: is_string($this->lang->langInfo()['plupload_code'] ?? null) ? $this->lang->langInfo()['plupload_code'] : '',
        ));

        return new AdminPageResult(
            content: $adminContent,
            pageTitle: $this->lang->t('Upload Photos'),
        );
    }

    /**
     * Ported from admin/include/photos_add_direct_prepare.inc.php -- upload
     * form setup (memory-limit math, default album selection, setup
     * errors/warnings). No real external callers besides this class's own
     * render(), unlike the shared admin/include/*.inc.php files this
     * project has kept as real includes elsewhere.
     *
     * @return array{chunkSize: int, maxFileSize: int, maxUploadWidth: ?float, maxUploadHeight: ?float, maxUploadResolution: ?float, originalResizeMaxwidth: ?int, originalResizeMaxheight: ?int, formAction: string, pwgToken: string, uploadFileTypes: string, fileExts: string, addToAlbum: ?string, selectedCategoryName: ?string, selectedCategory: list<int>, nbAlbums: int, setupErrors: list<string>, setupWarnings: list<string>, hideWarningsLink: ?string}
     */
    private function prepareUploadForm(PhotosAddDirectRequest $photosAddDirectRequest): array
    {
        $htmlRenderer = $this->htmlRenderer;

        $uploadService = AdminAccessor::uploadService();

        $chunk_size = $this->currentConfig->uploadFormChunkSize;
        $max_file_size = $this->currentConfig->uploadFormMaxFileSize;

        $max_upload_width_ctx = null;
        $max_upload_height_ctx = null;
        $max_upload_resolution_ctx = null;

        // what is the maximum number of pixels permitted by the memory_limit?
        if (ImageBackend::getLibrary() === 'gd') {
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
                $max_upload_width_ctx = $max_upload_width;
                $max_upload_height_ctx = $max_upload_height;
                $max_upload_resolution_ctx = $max_upload_resolution;
            }
        }

        $original_resize_maxwidth = null;
        $original_resize_maxheight = null;

        // warn the user if the picture will be resized after upload
        if ($this->currentConfig->originalResize) {
            $original_resize_maxwidth = $this->currentConfig->originalResizeMaxwidth;
            $original_resize_maxheight = $this->currentConfig->originalResizeMaxheight;
        }

        $form_action = self::baseUrl($this->urlService);
        $pwg_token = $this->csrfService
            ->getToken();

        $upload_extensions = ($this->currentConfig->uploadFormAllTypes) ? $this->currentConfig->fileExtensions : $this->currentConfig->pictureExtensions;
        $unique_exts = array_unique(array_map(strtolower(...), $upload_extensions));

        $upload_file_types = implode(', ', $unique_exts);
        $file_exts = implode(',', $unique_exts);

        // we need to know the category in which the last photo was added
        $selected_category = [];
        $add_to_album = null;
        $selected_category_name = null;

        if ($photosAddDirectRequest->albumPresent) {
            // set the category from get url or ...
            $album_id = $photosAddDirectRequest->albumId;

            // test if album really exists
            $uppercats = new CategoryRepository($this->entityManager, $this->currentConfig)
                ->findCategoryUppercatsById($album_id ?? 0);
            if ($album_id !== null && $uppercats !== null) {
                $selected_category = [$album_id];

                $add_to_album = $htmlRenderer->getCatDisplayNameCache($uppercats, null);
            } else {
                $htmlRenderer->pageNotFound($this->redirectService, $this->lang->t('Requested album does not exist'));
            }
        } else {
            // we need to know the category in which the last photo was added
            $mostRecentCategoryInfo = $this->entityManager->getRepository(ImageEntity::class)
                ->findMostRecentImageCategoryInfo();
            if ($mostRecentCategoryInfo !== null) {
                $selected_category = [$mostRecentCategoryInfo->categoryId];
                $uppercats = $mostRecentCategoryInfo->uppercats;
                $selected_category_name = $htmlRenderer->getCatDisplayNameCache($uppercats, null);
            }
        }

        // how many existing albums?
        $nb_albums = new CategoryRepository($this->entityManager, $this->currentConfig)
            ->countAllCategories();

        // Errors
        $setup_errors = [];

        $error_message = $uploadService->readyForUploadMessage();
        if (! in_array($error_message, [null, ''], true)) {
            $setup_errors[] = $error_message;
        }

        if (! function_exists('gd_info')) {
            $setup_errors[] = $this->lang->t('GD library is missing');
        }

        // Warnings
        if ($photosAddDirectRequest->hideWarningsPresent) {
            $_SESSION['upload_hide_warnings'] = true;
        }

        $setup_warnings = [];
        $hide_warnings_link = null;

        if (! isset($_SESSION['upload_hide_warnings'])) {
            $setup_warnings = [];

            if ($this->currentConfig->useExif && ! function_exists('exif_read_data')) {
                $setup_warnings[] = $this->lang->t('Exif extension not available, admin should disable exif use');
            }

            if ($uploadService->getIniSize('upload_max_filesize') > $uploadService->getIniSize('post_max_size')) {
                $setup_warnings[] = $this->lang->t(
                    'In your php.ini file, the upload_max_filesize (%sB) is bigger than post_max_size (%sB), you should change this setting',
                    $uploadService->getIniSize('upload_max_filesize', false),
                    $uploadService->getIniSize('post_max_size', false)
                );
            }

            $upload_form_chunk_size = $this->currentConfig->uploadFormChunkSize;
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

            $hide_warnings_link = self::baseUrl($this->urlService) . '&amp;hide_warnings=1';
        }

        return [
            'chunkSize' => $chunk_size,
            'maxFileSize' => $max_file_size,
            'maxUploadWidth' => $max_upload_width_ctx,
            'maxUploadHeight' => $max_upload_height_ctx,
            'maxUploadResolution' => $max_upload_resolution_ctx,
            'originalResizeMaxwidth' => $original_resize_maxwidth,
            'originalResizeMaxheight' => $original_resize_maxheight,
            'formAction' => $form_action,
            'pwgToken' => $pwg_token,
            'uploadFileTypes' => $upload_file_types,
            'fileExts' => $file_exts,
            'addToAlbum' => $add_to_album,
            'selectedCategoryName' => $selected_category_name,
            'selectedCategory' => $selected_category,
            'nbAlbums' => $nb_albums,
            'setupErrors' => $setup_errors,
            'setupWarnings' => $setup_warnings,
            'hideWarningsLink' => $hide_warnings_link,
        ];
    }
}
