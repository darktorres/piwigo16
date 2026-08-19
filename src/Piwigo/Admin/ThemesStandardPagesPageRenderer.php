<?php

declare(strict_types=1);

namespace Piwigo\Admin;

use Doctrine\ORM\EntityManagerInterface;
use Piwigo\Admin\Extensions\ExtensionScanner;
use Piwigo\Admin\Extensions\ExtensionType;
use Piwigo\Admin\Projection\ThemesStandardPagesView;
use Piwigo\Admin\Request\ThemesStandardPagesSubmitRequest;
use Piwigo\Auth\AccessControl;
use Piwigo\Config\ConfigService;
use Piwigo\Config\CurrentConfig;
use Piwigo\Controller\Admin\Projection\AdminContentPageContext;
use Piwigo\Core\FilesystemHelper;
use Piwigo\Core\HtmlRenderingInterface;
use Piwigo\Core\Lang;
use Piwigo\Core\PageState;
use Piwigo\Core\Paths;
use Piwigo\Core\RedirectServiceInterface;
use Piwigo\Core\StringHelper;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Csrf\CsrfService;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Storage\StorageRegistry;
use Piwigo\Template\CurrentTemplate;
use Piwigo\Template\Renderer;
use Piwigo\Users\CurrentUser;

/**
 * Configures the "standard pages" (logo/skin) shown when a theme lacks its
 * own login/register/etc pages. Reachable via two routes that both call
 * this one shared render() method: ThemesSubController's own
 * "standard_pages" tab, and the standalone "themes_standard_pages" page
 * slug's ThemesStandardPagesSubController.
 *
 * admin.php itself already gates every page slug behind
 * check_status(AccessLevel::Administrator) before dispatch -- both routes
 * that reach this renderer go through that same admin.php routing, so this
 * class performs no check_status() call of its own.
 */
final readonly class ThemesStandardPagesPageRenderer
{
    public function __construct(
        private Lang $lang,
        private AccessControl $accessControl,
        private RedirectServiceInterface $redirectService,
        private UrlServiceInterface $urlService,
        private ConfigService $configService,
        private StorageRegistry $storageRegistry,
        private PageState $pageState,
        private CurrentTemplate $currentTemplate,
        private HtmlRenderingInterface $htmlRenderer,
        private CurrentConfig $currentConfig,
        private CsrfService $csrfService,
        private Paths $paths,
        private CurrentUser $currentUser,
        private EventDispatcher $eventDispatcher,
        private EntityManagerInterface $entityManager,
        private Renderer $renderer,
    ) {}

    public function render(): void
    {
        $template = $this->currentTemplate->get();

        if (! $this->accessControl->isWebmaster()) {
            $this->pageState->addWarning(str_replace('%s', $this->lang->t('user_status_webmaster'), $this->lang->t('%s status is required to edit parameters.')));
        }

        $std_pgs_logo_options = [
            'piwigo_logo',
            'custom_logo',
            'gallery_title',
            'none',
        ];

        $std_pgs_skin_options = [
            'default',
            'cadmium',
            'cobalt',
            'fuchsia',
            'green',
            'lime',
            'purple',
            'red',
            'sienna',
            'silver',
            'teal',
        ];

        $stdPagesSubmit = ThemesStandardPagesSubmitRequest::fromGlobals($std_pgs_logo_options, $std_pgs_skin_options);

        if ($stdPagesSubmit->isSubmitted and $this->accessControl->isWebmaster()) {
            $this->csrfService
                ->checkOrFail($this->htmlRenderer, $this->redirectService);

            $this->configService->confUpdateParam('use_standard_pages', $stdPagesSubmit->useStandardPages, true);

            // save selected logo
            if ($stdPagesSubmit->selectedLogo !== null) {
                $this->configService->confUpdateParam('standard_pages_selected_logo', $stdPagesSubmit->selectedLogo, true);
            }

            // save selected skin
            if ($stdPagesSubmit->selectedSkin !== null) {
                $this->configService->confUpdateParam('standard_pages_selected_skin', $stdPagesSubmit->selectedSkin, true);
            }
        }

        // Handle logo upload, allow png, jpg and svg
        $save_error = null;
        if ($stdPagesSubmit->logoTmpName !== null) {
            $std_pgs_logo_tmp_name = $stdPagesSubmit->logoTmpName;

            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime_type = $finfo === false ? false : finfo_file($finfo, $std_pgs_logo_tmp_name);

            // Allowed MIME types
            $allowed_mimes = [
                'image/png' => 'png',
                'image/jpeg' => 'jpg',
                'image/svg+xml' => 'svg',
                'image/svg' => 'svg',
                'image/webp' => 'webp',
            ];

            if (! is_string($mime_type) || ! isset($allowed_mimes[$mime_type])) {
                $save_error = 'Invalid image file.';
            } else {
                $upload_dir = $this->paths->siteLocal . 'logo';
                if (FilesystemHelper::mkgetdir($upload_dir, $this->currentConfig, FilesystemHelper::MKGETDIR_DEFAULT & ~FilesystemHelper::MKGETDIR_DIE_ON_ERROR)) {
                    $pathinfo = pathinfo($stdPagesSubmit->logoName);

                    $logo_filename = StringHelper::str2url($pathinfo['filename']) . '.' . $allowed_mimes[$mime_type];

                    // Disk-relative path (relative to the 'local' disk's own
                    // root, e.g. 'logo/mylogo.png') -- stored in config so
                    // Piwigo\Controller\CustomLogoController can resolve and
                    // stream it later. Deliberately NOT the absolute
                    // filesystem $upload_dir path: 'local/' is intentionally
                    // unreachable from public/, and an absolute path is not
                    // a valid URL anyway -- CustomLogoController is the one,
                    // permission-free, server-resolved way this single
                    // intentionally-public file is served.
                    $relative_path = 'logo/' . $logo_filename;

                    // Persisted only once the write below actually
                    // succeeds -- a failed write must not leave config
                    // pointing at a logo file that was never written, since
                    // both Piwigo\Controller\CustomLogoController's serving
                    // of it and this method's own "existing logo preview"
                    // vs. "upload a logo" UI toggle a few lines below gate
                    // on this exact config value.
                    $logo_stream = fopen($std_pgs_logo_tmp_name, 'rb');
                    if ($logo_stream !== false) {
                        $this->storageRegistry->get('local')
                            ->writeStream($relative_path, $logo_stream);
                        fclose($logo_stream);
                        $this->configService->confUpdateParam('standard_pages_selected_logo_path', $relative_path, true);
                    } else {
                        $save_error = "{$upload_dir}/{$logo_filename} " . $this->lang->t('no write access');
                    }
                } else {
                    $save_error = sprintf(
                        $this->lang->t('Add write access to the "%s" directory'),
                        $upload_dir
                    );
                }
            }
        }

        // We want to now if any themes use standard pages and which ones
        $fs_themes = new ExtensionScanner()
            ->scan(ExtensionType::Theme, $this->urlService, $this->lang, $this->paths, $this->currentUser, $this->eventDispatcher, $this->currentConfig, $this->entityManager);

        $is_standard_pages_used = false;
        $standard_pages_used_by = [];

        foreach ($fs_themes as $theme) {
            if (isset($theme['use_standard_pages']) and (bool) $theme['use_standard_pages']) {
                $is_standard_pages_used = true;
                array_push($standard_pages_used_by, $theme['name']);
            }
        }

        // Served by Piwigo\Controller\CustomLogoController (public/logo.php) --
        // a fixed, parameter-less, server-resolved URL, not the disk-relative
        // path stored in config. null (not the raw config value) is what
        // gates the template's own "existing logo preview" vs. "upload a
        // logo" UI toggle, so an unset/empty config value must stay null here.
        $configured_logo_path = $this->currentConfig->standardPagesSelectedLogoPath;
        $std_pgs_selected_logo_path = is_string($configured_logo_path) && $configured_logo_path !== ''
            ? $this->urlService->getRootUrl() . 'logo.php'
            : null;

        // Send all info to template
        $adminContent = $this->renderer->render(new ThemesStandardPagesView(
            useStandardPages: $this->currentConfig->useStandardPages,
            stdPgsSelectedLogo: $this->currentConfig->standardPagesSelectedLogo,
            stdPgsSelectedSkin: $this->currentConfig->standardPagesSelectedSkin,
            stdPgsSkinOptions: $std_pgs_skin_options,
            isStandardPagesUsed: $is_standard_pages_used,
            standardPagesUsedBy: $standard_pages_used_by,
            stdPgsSelectedLogoPath: $std_pgs_selected_logo_path,
            csrfToken: $this->csrfService
                ->getToken(),
            isWebmaster: ($this->accessControl->isWebmaster()) ? 1 : 0,
            saveError: $save_error,
        ));

        $template->assignContext(new AdminContentPageContext(
            adminContent: $adminContent,
            adminPageTitle: $this->lang->t('Themes'),
        ));
    }
}
