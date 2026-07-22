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

        if (isset($_POST['submit']) and \Piwigo\Auth\AccessControl::isWebmaster()) {
            new \Piwigo\Csrf\CsrfService()
                ->checkOrFail(new \Piwigo\Html\HtmlService(), $this->redirectService);

            // use_standard_pages or not -- a checkbox POST value, so "not
            // set"/''/'0' are all "unchecked", matching empty()'s own
            // semantics for this input without using empty() itself.
            $use_standard_pages_raw = $_POST['use_standard_pages'] ?? null;
            $use_standard_pages_checked = $use_standard_pages_raw !== null
                && $use_standard_pages_raw !== ''
                && $use_standard_pages_raw !== '0';
            $this->configService->confUpdateParam('use_standard_pages', $use_standard_pages_checked, true);

            // save selected logo
            if (isset($_POST['std_pgs_display_logo']) and in_array($_POST['std_pgs_display_logo'], $std_pgs_logo_options, true)) {
                $this->configService->confUpdateParam('standard_pages_selected_logo', $_POST['std_pgs_display_logo'], true);
            }

            // save selected skin
            if (isset($_POST['std_pgs_selected_skin']) and in_array($_POST['std_pgs_selected_skin'], $std_pgs_skin_options, true)) {
                $this->configService->confUpdateParam('standard_pages_selected_skin', $_POST['std_pgs_selected_skin'], true);
            }
        }

        // Handle logo upload, allow png, jpg and svg
        $std_pgs_logo_upload = $_FILES['std_pgs_logo'] ?? null;
        if (
            is_array($std_pgs_logo_upload)
            and isset($std_pgs_logo_upload['tmp_name'])
            and is_string($std_pgs_logo_upload['tmp_name'])
            and $std_pgs_logo_upload['tmp_name'] !== ''
        ) {
            $std_pgs_logo_tmp_name = $std_pgs_logo_upload['tmp_name'];

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
                    $std_pgs_logo_name = isset($std_pgs_logo_upload['name']) && is_string($std_pgs_logo_upload['name'])
                        ? $std_pgs_logo_upload['name']
                        : '';
                    $pathinfo = pathinfo($std_pgs_logo_name);

                    $file_path = $upload_dir . '/' . \Piwigo\Core\StringHelper::str2url($pathinfo['filename']) . '.' . $allowed_mimes[$mime_type];

                    $this->configService->confUpdateParam('standard_pages_selected_logo_path', $file_path, true);

                    // $upload_dir is PWG_LOCAL_DIR . 'logo', a subdirectory of the
                    // 'local' disk's own root -- the disk-relative path needs the
                    // 'logo/' prefix back on (unlike the watermarks disk, whose
                    // root IS its upload dir).
                    $logo_stream = fopen($std_pgs_logo_tmp_name, 'rb');
                    if ($logo_stream !== false) {
                        StorageRegistry::disk('local')->writeStream('logo/' . basename($file_path), $logo_stream);
                        fclose($logo_stream);
                    } else {
                        $template->assign(
                            [
                                'save_error' => "{$file_path} " . Lang::t('no write access'),
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

        // Send all info to template
        $template->assign(
            [
                'use_standard_pages' => \Piwigo\Config\Config::all()['use_standard_pages'] ?? true,
                'std_pgs_selected_logo' => \Piwigo\Config\Config::all()['standard_pages_selected_logo'] ?? 'piwigo_logo',
                'std_pgs_logo_options' => $std_pgs_logo_options,
                'std_pgs_selected_skin' => \Piwigo\Config\Config::all()['standard_pages_selected_skin'] ?? 'default',
                'std_pgs_skin_options' => $std_pgs_skin_options,
                'is_standard_pages_used' => $is_standard_pages_used,
                'standard_pages_used_by' => $standard_pages_used_by,
                'std_pgs_selected_logo_path' => \Piwigo\Config\Config::all()['standard_pages_selected_logo_path'] ?? null,
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
