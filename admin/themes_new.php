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
global $conf, $page, $template;

if (! $conf['enable_extensions_install']) {
    die('Piwigo extensions install/update system is disabled');
}

include_once PHPWG_ROOT_PATH . 'admin/include/themes.class.php';

$base_url = get_root_url() . 'admin.php?page=' . $page['page'] . '&tab=' . $page['tab'];

$themes = new themes();

// +-----------------------------------------------------------------------+
// |                           setup check                                 |
// +-----------------------------------------------------------------------+

$themes_dir = PHPWG_ROOT_PATH . 'themes';
if (! is_writable($themes_dir)) {
    $page['errors'][] = l10n('Add write access to the "%s" directory', 'themes');
}

// +-----------------------------------------------------------------------+
// |                       perform installation                            |
// +-----------------------------------------------------------------------+

if (isset($_GET['revision']) and isset($_GET['extension'])
    and is_string($_GET['revision']) and is_string($_GET['extension'])
) {
    if (! is_webmaster()) {
        $page['errors'][] = l10n('Webmaster status is required.');
    } else {
        check_pwg_token();

        $install_status = $themes->extract_theme_files(
            'install',
            $_GET['revision'],
            $_GET['extension'],
            $theme_id
        );

        redirect($base_url . '&installstatus=' . $install_status . '&theme_id=' . $theme_id);
    }
}

// +-----------------------------------------------------------------------+
// |                        installation result                            |
// +-----------------------------------------------------------------------+

if (isset($_GET['installstatus'])) {
    switch ($_GET['installstatus']) {
        case 'ok':
            $page['infos'][] = l10n('Theme has been successfully installed');

            $installed_theme_id = $_GET['theme_id'] ?? null;
            if (is_string($installed_theme_id) && isset($themes->fs_themes[$installed_theme_id])) {
                pwg_activity(
                    'system',
                    ACTIVITY_SYSTEM_THEME,
                    'install',
                    [
                        'theme_id' => $installed_theme_id,
                        'version' => $themes->fs_themes[$installed_theme_id]['version'],
                    ]
                );
            }
            break;

        case 'temp_path_error':
            $page['errors'][] = l10n('Can\'t create temporary file.');
            break;

        case 'dl_archive_error':
            $page['errors'][] = l10n('Can\'t download archive.');
            break;

        case 'archive_error':
            $page['errors'][] = l10n('Can\'t read or extract archive.');
            break;

        default:
            $installstatus_raw = $_GET['installstatus'];
            $installstatus_str = is_scalar($installstatus_raw) ? (string) $installstatus_raw : '';
            $page['errors'][] = l10n(
                'An error occured during extraction (%s).',
                htmlspecialchars($installstatus_str)
            );
    }
}

// +-----------------------------------------------------------------------+
// |                          template output                              |
// +-----------------------------------------------------------------------+

$template->set_filenames([
    'themes' => 'themes_new.tpl',
]);

if ($themes->get_server_themes(true)) { // only new themes
    foreach ($themes->server_themes as $theme) {
        // server_themes entries come from an untyped unserialize() of a
        // remote PEM payload (themes::get_server_themes()); narrow the
        // fields used in a typed context below rather than trusting the
        // external payload's shape.
        $revision_id_raw = $theme['revision_id'] ?? null;
        $revision_id = is_scalar($revision_id_raw) ? (string) $revision_id_raw : '';
        $extension_id_raw = $theme['extension_id'] ?? null;
        $extension_id = is_scalar($extension_id_raw) ? (string) $extension_id_raw : '';

        $url_auto_install = htmlentities($base_url)
          . '&amp;revision=' . $revision_id
          . '&amp;extension=' . $extension_id
          . '&amp;pwg_token=' . get_pwg_token()
        ;

        $template->append(
            'new_themes',
            [
                'name' => $theme['extension_name'],
                'thumbnail' => (key_exists('thumbnail_src', $theme)) ? $theme['thumbnail_src'] : '',
                'screenshot' => (key_exists('screenshot_url', $theme)) ? $theme['screenshot_url'] : '',
                'install_url' => $url_auto_install,
            ]
        );
    }
} else {
    $page['errors'][] = l10n('Can\'t connect to server.');
}

$admin_theme_pref = userprefs_get_param('admin_theme', 'clear');
$template->assign(
    'default_screenshot',
    get_root_url() . 'admin/themes/' . (is_string($admin_theme_pref) ? $admin_theme_pref : 'clear') . '/images/missing_screenshot.png'
);
$template->assign('ADMIN_PAGE_TITLE', l10n('Themes'));

$template->assign_var_from_handle('ADMIN_CONTENT', 'themes');
