<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

use Piwigo\Admin\Extensions\ExtensionScanner;
use Piwigo\Admin\Extensions\ExtensionType;
use Piwigo\Admin\Extensions\PemCatalog;
use Piwigo\Admin\Extensions\ZipExtractor;
use Piwigo\Core\ActivitySystem;
use Piwigo\Template\Template;

if (! defined('PHPWG_ROOT_PATH')) {
    die('Hacking attempt!');
}

// Bootstrap globals, set by include/common.inc.php.
/**
 * @var array<string, mixed> $conf
 * @var array<string, mixed> $page
 * @var Template $template
 */
global $conf, $page, $template;

if (! (bool) $conf['enable_extensions_install']) {
    die('Piwigo extensions install/update system is disabled');
}

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

$pem_catalog = new PemCatalog(new ZipExtractor());
$extension_scanner = new ExtensionScanner();

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

        $extraction = $pem_catalog->extractArchive(ExtensionType::Theme, 'install', $_GET['revision'], $_GET['extension']);
        $install_status = $extraction['status'];
        $theme_id = $extraction['id'];

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
            $installed_fs_theme = is_string($installed_theme_id) ? ($extension_scanner->scan(ExtensionType::Theme)[$installed_theme_id] ?? null) : null;
            if ($installed_fs_theme !== null) {
                pwg_activity(
                    'system',
                    ActivitySystem::Theme,
                    'install',
                    [
                        'theme_id' => $installed_theme_id,
                        'version' => $installed_fs_theme['version'],
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

$fs_theme_ids = [];
foreach ($extension_scanner->scan(ExtensionType::Theme) as $fs_theme) {
    $extension = $fs_theme['extension'] ?? null;
    if (is_scalar($extension)) {
        $fs_theme_ids[] = (string) $extension;
    }
}
$server_themes = $pem_catalog->getServerExtensions(ExtensionType::Theme, $fs_theme_ids, true);

if ($server_themes !== null) { // only new themes
    foreach ($server_themes as $theme) {
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
