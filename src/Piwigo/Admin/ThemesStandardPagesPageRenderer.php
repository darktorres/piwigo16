<?php

declare(strict_types=1);

namespace Piwigo\Admin;

use Piwigo\Admin\Extensions\ExtensionScanner;
use Piwigo\Admin\Extensions\ExtensionType;
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
 * Migrated off the legacy Piwigo\Admin\themes god-class: this was the only
 * remaining caller of `new themes(); $themes->get_fs_themes();` in this
 * cluster (confirmed via grep) -- ExtensionScanner::scan(ExtensionType::Theme)
 * already returns the same 'name'/'use_standard_pages' fields this file
 * needs. The themes class itself stays (real callers remain outside this
 * batch: admin/include/functions_install.inc.php, functions_upgrade.php).
 *
 * Investigated, not fixed: this page's writes go through the free functions
 * conf_update_param()/conf_get_param(), not Piwigo\Config\ConfigService --
 * see the original deferral rationale in this class's predecessor,
 * ThemesStandardPagesSubController (task #343's precedent, re-verified
 * during this port: all 4 conf_update_param() call sites below still pass
 * only an allowlisted or sanitized value, so still not exploitable here).
 */
final class ThemesStandardPagesPageRenderer
{
    public function render(): void
    {
        /**
         * @var array<string, mixed> $page
         * @var Template $template
         */
        global $page, $template;

        if (! is_webmaster()) {
            $page_warnings = $page['warnings'] ?? [];
            if (! is_array($page_warnings)) {
                $page_warnings = [];
            }
            $page_warnings[] = str_replace('%s', l10n('user_status_webmaster'), l10n('%s status is required to edit parameters.'));
            $page['warnings'] = $page_warnings;
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

        if (isset($_POST['submit']) and is_webmaster()) {
            check_pwg_token();

            // use_standard_pages or not -- a checkbox POST value, so "not
            // set"/''/'0' are all "unchecked", matching empty()'s own
            // semantics for this input without using empty() itself.
            $use_standard_pages_raw = $_POST['use_standard_pages'] ?? null;
            $use_standard_pages_checked = $use_standard_pages_raw !== null
                && $use_standard_pages_raw !== ''
                && $use_standard_pages_raw !== '0';
            conf_update_param('use_standard_pages', $use_standard_pages_checked, true);

            // save selected logo
            if (isset($_POST['std_pgs_display_logo']) and in_array($_POST['std_pgs_display_logo'], $std_pgs_logo_options, true)) {
                conf_update_param('standard_pages_selected_logo', $_POST['std_pgs_display_logo'], true);
            }

            // save selected skin
            if (isset($_POST['std_pgs_selected_skin']) and in_array($_POST['std_pgs_selected_skin'], $std_pgs_skin_options, true)) {
                conf_update_param('standard_pages_selected_skin', $_POST['std_pgs_selected_skin'], true);
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
                $upload_dir = PHPWG_ROOT_PATH . PWG_LOCAL_DIR . 'logo';
                if (mkgetdir($upload_dir, MKGETDIR_DEFAULT & ~MKGETDIR_DIE_ON_ERROR)) {
                    $std_pgs_logo_name = isset($std_pgs_logo_upload['name']) && is_string($std_pgs_logo_upload['name'])
                        ? $std_pgs_logo_upload['name']
                        : '';
                    $pathinfo = pathinfo($std_pgs_logo_name);

                    $file_path = $upload_dir . '/' . str2url($pathinfo['filename']) . '.' . $allowed_mimes[$mime_type];

                    conf_update_param('standard_pages_selected_logo_path', $file_path, true);

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
                                'save_error' => "{$file_path} " . l10n('no write access'),
                            ]
                        );
                    }
                } else {
                    $template->assign(
                        [
                            'save_error' => sprintf(
                                l10n('Add write access to the "%s" directory'),
                                $upload_dir
                            ),
                        ]
                    );
                }
            }
        }

        // We want to now if any themes use standard pages and which ones
        $fs_themes = (new ExtensionScanner())->scan(ExtensionType::Theme);

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
                'use_standard_pages' => conf_get_param('use_standard_pages', true),
                'std_pgs_selected_logo' => conf_get_param('standard_pages_selected_logo', 'piwigo_logo'),
                'std_pgs_logo_options' => $std_pgs_logo_options,
                'std_pgs_selected_skin' => conf_get_param('standard_pages_selected_skin', 'default'),
                'std_pgs_skin_options' => $std_pgs_skin_options,
                'is_standard_pages_used' => $is_standard_pages_used,
                'standard_pages_used_by' => $standard_pages_used_by,
                'std_pgs_selected_logo_path' => conf_get_param('standard_pages_selected_logo_path', null),
                'PWG_TOKEN' => get_pwg_token(),
            ]
        );

        $template->assign('isWebmaster', (is_webmaster()) ? 1 : 0);

        $template->set_filenames([
            'themes' => 'themes_standard_pages.tpl',
        ]);

        $template->assign('ADMIN_PAGE_TITLE', l10n('Themes'));

        $template->assign_var_from_handle('ADMIN_CONTENT', 'themes');
    }
}
