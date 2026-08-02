<?php

declare(strict_types=1);

namespace Piwigo\Admin;

use Piwigo\Admin\Extensions\ExtensionScanner;
use Piwigo\Admin\Extensions\ExtensionType;
use Piwigo\Config\ConfigService;
use Piwigo\Core\Lang;
use Piwigo\Core\RedirectServiceInterface;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Storage\StorageRegistry;
use Piwigo\Template\Template;

/**
 * Ported from admin/themes_standard_pages.php -- configures the "standard
 * pages" (logo/skin) shown when a theme lacks its own login/register/etc
 * pages. Reachable via 2 routes, both of which now call this one shared
 * render() method instead of each keeping (or including) its own copy,
 * matching the MaintenanceActionDispatcher/FilterPanelRenderer "one class,
 * multiple call sites" precedent: ThemesSubController's own "standard_pages"
 * tab, and the standalone "themes_standard_pages" page slug's
 * ThemesStandardPagesSubController.
 *
 * admin.php itself already gates every page slug behind
 * check_status(AccessLevel::Administrator) before dispatch -- both routes
 * that reach this renderer go through that same admin.php routing, so the
 * file's own (redundant) check_status() call is dropped here, same
 * precedent as MaintenanceSubController's own shell fold.
 *
 * Migrated off the legacy Piwigo\Admin\themes god-class onto
 * ExtensionScanner::scan(ExtensionType::Theme), which returns the same
 * 'name'/'use_standard_pages' fields this file needs. The themes god-class
 * itself was fully retired in Legacy Coupling Retirement Phase 8, 8j, once
 * every other real caller had migrated onto Admin\Extensions\* too.
 *
 * Legacy Coupling Retirement Phase 5: the 4 writes below now go through
 * the DI/Doctrine-backed Piwigo\Config\ConfigService instead of the
 * static Piwigo\Config\ConfigDb -- this class already receives
 * RedirectServiceInterface/UrlServiceInterface via constructor
 * injection, so adding ConfigService the same way is mechanical.
 */
final class ThemesStandardPagesPageRenderer
{
    public function __construct(
        private readonly RedirectServiceInterface $redirectService,
        private readonly UrlServiceInterface $urlService,
        private readonly ConfigService $configService,
        private readonly StorageRegistry $storageRegistry,
    ) {}

    public function render(): void
    {
        $template = \Piwigo\Template\CurrentTemplate::get();

        if (! \Piwigo\Auth\AccessControl::isWebmaster()) {
            \Piwigo\Core\PageState::current()->addWarning(str_replace('%s', Lang::t('user_status_webmaster'), Lang::t('%s status is required to edit parameters.')));
        }

        // +-----------------------------------------------------------------------+
        // | Update standard pages configuration                                   |
        // +-----------------------------------------------------------------------+

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

        $stdPagesSubmit = Request\ThemesStandardPagesSubmitRequest::fromGlobals($std_pgs_logo_options, $std_pgs_skin_options);

        if ($stdPagesSubmit->isSubmitted and \Piwigo\Auth\AccessControl::isWebmaster()) {
            new \Piwigo\Csrf\CsrfService()
                ->checkOrFail(\Piwigo\Bootstrap\PresentationAccessor::htmlService(), $this->redirectService);

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
                $template->assign(
                    [
                        'save_error' => 'Invalid image file.',
                    ]
                );
            } else {
                $upload_dir = \Piwigo\Core\CurrentPaths::get()->siteLocal . 'logo';
                if (\Piwigo\Core\FilesystemHelper::mkgetdir($upload_dir, \Piwigo\Core\FilesystemHelper::MKGETDIR_DEFAULT & ~\Piwigo\Core\FilesystemHelper::MKGETDIR_DIE_ON_ERROR)) {
                    $pathinfo = pathinfo($stdPagesSubmit->logoName);

                    $logo_filename = \Piwigo\Core\StringHelper::str2url($pathinfo['filename']) . '.' . $allowed_mimes[$mime_type];

                    // Disk-relative path (relative to the 'local' disk's own
                    // root, e.g. 'logo/mylogo.png') -- stored in config so
                    // Piwigo\Controller\CustomLogoController can resolve and
                    // stream it later. Deliberately NOT the absolute
                    // filesystem $upload_dir path: 'local/' is intentionally
                    // unreachable from public/ (Legacy Coupling Retirement's
                    // web-root isolation work), and an absolute path was
                    // never a valid URL anyway -- CustomLogoController is the
                    // one, permission-free, server-resolved way this single
                    // intentionally-public file is served.
                    $relative_path = 'logo/' . $logo_filename;

                    // Persisted only once the write below actually succeeds
                    // (real bug found and fixed while adding test coverage
                    // for the fopen()-failure branch: this used to run
                    // unconditionally *before* fopen()/writeStream() ever
                    // ran, so a failed write still left config pointing at
                    // a logo file that was never written -- breaking both
                    // Piwigo\Controller\CustomLogoController's serving of
                    // it and this method's own "existing logo preview" vs.
                    // "upload a logo" UI toggle a few lines below, which
                    // gates on this exact config value).
                    $logo_stream = fopen($std_pgs_logo_tmp_name, 'rb');
                    if ($logo_stream !== false) {
                        $this->storageRegistry->get('local')
                            ->writeStream($relative_path, $logo_stream);
                        fclose($logo_stream);
                        $this->configService->confUpdateParam('standard_pages_selected_logo_path', $relative_path, true);
                    } else {
                        $template->assign(
                            [
                                'save_error' => "{$upload_dir}/{$logo_filename} " . Lang::t('no write access'),
                            ]
                        );
                    }
                } else {
                    $template->assign(
                        [
                            'save_error' => sprintf(
                                Lang::t('Add write access to the "%s" directory'),
                                $upload_dir
                            ),
                        ]
                    );
                }
            }
        }

        // We want to now if any themes use standard pages and which ones
        $fs_themes = new ExtensionScanner()
            ->scan(ExtensionType::Theme, $this->urlService);

        $is_standard_pages_used = false;
        $standard_pages_used_by = [];

        foreach ($fs_themes as $theme) {
            if (isset($theme['use_standard_pages']) and (bool) $theme['use_standard_pages']) {
                $is_standard_pages_used = true;
                array_push($standard_pages_used_by, $theme['name']);
            }
        }

        // +-----------------------------------------------------------------------+
        // |                          template output                              |
        // +-----------------------------------------------------------------------+

        // Served by Piwigo\Controller\CustomLogoController (public/logo.php) --
        // a fixed, parameter-less, server-resolved URL, not the disk-relative
        // path stored in config. null (not the raw config value) is what
        // gates the template's own "existing logo preview" vs. "upload a
        // logo" UI toggle, so an unset/empty config value must stay null here.
        $configured_logo_path = \Piwigo\Config\CurrentConfig::standardPagesSelectedLogoPath();
        $std_pgs_selected_logo_path = is_string($configured_logo_path) && $configured_logo_path !== ''
            ? $this->urlService->getRootUrl() . 'logo.php'
            : null;

        // Send all info to template
        $template->assign(
            [
                'use_standard_pages' => \Piwigo\Config\CurrentConfig::useStandardPages(),
                'std_pgs_selected_logo' => \Piwigo\Config\CurrentConfig::standardPagesSelectedLogo(),
                'std_pgs_logo_options' => $std_pgs_logo_options,
                'std_pgs_selected_skin' => \Piwigo\Config\CurrentConfig::standardPagesSelectedSkin(),
                'std_pgs_skin_options' => $std_pgs_skin_options,
                'is_standard_pages_used' => $is_standard_pages_used,
                'standard_pages_used_by' => $standard_pages_used_by,
                'std_pgs_selected_logo_path' => $std_pgs_selected_logo_path,
                'PWG_TOKEN' => new \Piwigo\Csrf\CsrfService()
                    ->getToken(),
            ]
        );

        $template->assign('isWebmaster', (\Piwigo\Auth\AccessControl::isWebmaster()) ? 1 : 0);

        $template->set_filenames([
            'themes' => 'themes_standard_pages.tpl',
        ]);

        $template->assign('ADMIN_PAGE_TITLE', Lang::t('Themes'));

        $template->assign_var_from_handle('ADMIN_CONTENT', 'themes');
    }
}
