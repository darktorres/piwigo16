<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

if (! defined('PHPWG_ROOT_PATH')) {
    die('Hacking attempt!');
}

// Bootstrap globals, set by include/common.inc.php.
/** @var \Template $template */
global $template;

// +-----------------------------------------------------------------------+
// | Check Access and exit when user status is not ok                      |
// +-----------------------------------------------------------------------+
check_status(ACCESS_ADMINISTRATOR);

if (! is_webmaster()) {
    /** @var array<string, mixed> $page */
    if (! is_array($page['warnings'] ?? null)) {
        $page['warnings'] = [];
    }

    $page['warnings'][] = str_replace('%s', l10n('user_status_webmaster'), l10n('%s status is required to edit parameters.'));
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

    // use_standard_pages or not
    conf_update_param('use_standard_pages', ! empty($_POST['use_standard_pages']), true);

    // save selected logo
    if (isset($_POST['std_pgs_display_logo']) and in_array($_POST['std_pgs_display_logo'], $std_pgs_logo_options)) {
        conf_update_param('standard_pages_selected_logo', $_POST['std_pgs_display_logo'], true);
    }

    // save selected skin
    if (isset($_POST['std_pgs_selected_skin']) and in_array($_POST['std_pgs_selected_skin'], $std_pgs_skin_options)) {
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

            if (! move_uploaded_file($std_pgs_logo_tmp_name, $file_path)) {
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
include_once PHPWG_ROOT_PATH . 'admin/include/themes.class.php';
$themes = new themes();
$themes->get_fs_themes();

$is_standard_pages_used = false;
$standard_pages_used_by = [];

foreach ($themes->fs_themes as $theme) {
    if (isset($theme['use_standard_pages']) and $theme['use_standard_pages']) {
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
