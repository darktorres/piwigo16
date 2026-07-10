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
/**
 * @var array<string, mixed> $conf
 * @var array<string, mixed> $page
 * @var \Template $template
 */
global $conf, $page, $template;

if (! $conf['enable_extensions_install']) {
    die('Piwigo extensions install/update system is disabled');
}

include_once PHPWG_ROOT_PATH . 'admin/include/themes.class.php';

/**
 * Append a message to a $page message bucket (e.g. 'infos'/'errors'),
 * narrowing it to an array first if it isn't provably one yet ($page itself
 * is only known as array<string, mixed>, so $page[$key] is still mixed) --
 * same pattern as
 * admin/include/functions_notification_by_mail.inc.php::push_page_message()
 * (uniquely named here, rather than that same name, since PHPStan analyzes
 * every included file together and a second top-level push_page_message()
 * declaration would collide with that one). A local closure was tried
 * first, but PHPStan does not honor a @param docblock $key on a closure
 * assigned to a variable the way it does for a real named function --
 * every by-reference call through the closure re-widened $page back to
 * mixed for all subsequent reads in this file.
 *
 * @param array<string, mixed> $page
 */
function push_themes_new_page_message(string $key, string $message, array &$page): void
{
    $list = $page[$key] ?? [];
    $list = is_array($list) ? $list : [];
    $list[] = $message;
    $page[$key] = $list;
}

// $page['page']/$page['tab'] are set as plain strings by admin.php's
// routing before this page module is included; $page itself is only known
// as array<string, mixed>, so narrow the two offsets used here.
$page_page = $page['page'] ?? null;
$page_page = is_scalar($page_page) ? (string) $page_page : '';
$page_tab = $page['tab'] ?? null;
$page_tab = is_scalar($page_tab) ? (string) $page_tab : '';
$base_url = get_root_url() . 'admin.php?page=' . $page_page . '&tab=' . $page_tab;

$themes = new themes();

// +-----------------------------------------------------------------------+
// |                           setup check                                 |
// +-----------------------------------------------------------------------+

$themes_dir = PHPWG_ROOT_PATH . 'themes';
if (! is_writable($themes_dir)) {
    push_themes_new_page_message('errors', l10n('Add write access to the "%s" directory', 'themes'), $page);
}

// +-----------------------------------------------------------------------+
// |                       perform installation                            |
// +-----------------------------------------------------------------------+

if (isset($_GET['revision']) and isset($_GET['extension'])
    and is_string($_GET['revision']) and is_string($_GET['extension'])
) {
    if (! is_webmaster()) {
        push_themes_new_page_message('errors', l10n('Webmaster status is required.'), $page);
    } else {
        check_pwg_token();

        $theme_id = null;
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
            push_themes_new_page_message('infos', l10n('Theme has been successfully installed'), $page);

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
            push_themes_new_page_message('errors', l10n('Can\'t create temporary file.'), $page);
            break;

        case 'dl_archive_error':
            push_themes_new_page_message('errors', l10n('Can\'t download archive.'), $page);
            break;

        case 'archive_error':
            push_themes_new_page_message('errors', l10n('Can\'t read or extract archive.'), $page);
            break;

        default:
            $installstatus_raw = $_GET['installstatus'];
            $installstatus_str = is_scalar($installstatus_raw) ? (string) $installstatus_raw : '';
            push_themes_new_page_message(
                'errors',
                l10n(
                    'An error occured during extraction (%s).',
                    htmlspecialchars($installstatus_str)
                ),
                $page
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
    push_themes_new_page_message('errors', l10n('Can\'t connect to server.'), $page);
}

$admin_theme_pref = userprefs_get_param('admin_theme', 'clear');
$template->assign(
    'default_screenshot',
    get_root_url() . 'admin/themes/' . (is_string($admin_theme_pref) ? $admin_theme_pref : 'clear') . '/images/missing_screenshot.png'
);
$template->assign('ADMIN_PAGE_TITLE', l10n('Themes'));

$template->assign_var_from_handle('ADMIN_CONTENT', 'themes');
