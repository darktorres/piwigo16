<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

use Piwigo\Admin\languages;
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
function push_languages_new_page_message(string $key, string $message, array &$page): void
{
    $list = $page[$key] ?? [];
    $list = is_array($list) ? $list : [];
    $list[] = $message;
    $page[$key] = $list;
}

$template->set_filenames([
    'languages' => 'languages_new.tpl',
]);

// $page['page']/$page['tab'] are set as plain strings by admin.php's
// routing before this page module is included; $page itself is only known
// as array<string, mixed>, so narrow the two offsets used here.
$page_page = $page['page'] ?? null;
$page_page = is_scalar($page_page) ? (string) $page_page : '';
$page_tab = $page['tab'] ?? null;
$page_tab = is_scalar($page_tab) ? (string) $page_tab : '';
$base_url = get_root_url() . 'admin.php?page=' . $page_page . '&tab=' . $page_tab;

$languages = new languages();
$languages->get_db_languages();

// +-----------------------------------------------------------------------+
// |                           setup check                                 |
// +-----------------------------------------------------------------------+

$languages_dir = PHPWG_ROOT_PATH . 'language';
if (! is_writable($languages_dir)) {
    push_languages_new_page_message('errors', l10n('Add write access to the "%s" directory', 'language'), $page);
}

// +-----------------------------------------------------------------------+
// |                       perform installation                            |
// +-----------------------------------------------------------------------+

if (isset($_GET['revision'])) {
    if (! is_webmaster()) {
        push_languages_new_page_message('errors', l10n('Webmaster status is required.'), $page);
    } else {
        check_pwg_token();

        // $_GET values are always string|array; 'revision' is only ever
        // built from $language['revision_id'] in this file's own template
        // link below, so it's a plain numeric string in the normal case.
        $revision = $_GET['revision'];
        $revision = is_string($revision) ? $revision : '';

        $install_status = $languages->extract_language_files('install', $revision);
        // extract_language_files() is declared "@return mixed" but every
        // internal code path assigns $status a string literal before
        // returning it; narrow defensively rather than trust the docblock.
        $install_status = is_string($install_status) ? $install_status : '';

        redirect($base_url . '&installstatus=' . $install_status);
    }
}

// +-----------------------------------------------------------------------+
// |                        installation result                            |
// +-----------------------------------------------------------------------+
if (isset($_GET['installstatus'])) {
    $installstatus = $_GET['installstatus'];
    $installstatus = is_string($installstatus) ? $installstatus : '';

    match ($installstatus) {
        'ok' => push_languages_new_page_message('infos', l10n('Language has been successfully installed'), $page),
        'temp_path_error' => push_languages_new_page_message('errors', l10n('Can\'t create temporary file.'), $page),
        'dl_archive_error' => push_languages_new_page_message('errors', l10n('Can\'t download archive.'), $page),
        'archive_error' => push_languages_new_page_message('errors', l10n('Can\'t read or extract archive.'), $page),
        default => push_languages_new_page_message('errors', l10n('An error occured during extraction (%s).', htmlspecialchars($installstatus)), $page),
    };
}

// +-----------------------------------------------------------------------+
// |                     start template output                             |
// +-----------------------------------------------------------------------+
if ($languages->get_server_languages(true)) {
    // PEM_URL is defined via define('PEM_URL', $conf['alternative_pem_url'])
    // in one branch of include/common.inc.php, so PHPStan can't prove it's
    // a string across that file boundary -- narrow it once here (same
    // pattern as languages.class.php::get_server_languages()).
    $pem_base_url = is_string(PEM_URL) ? PEM_URL : '';

    foreach ($languages->server_languages as $language) {
        // $language comes from an untyped unserialize() of a remote PEM
        // payload (see languages::get_server_languages()); only cast
        // scalars actually safe to stringify, treat anything else as empty
        // (same pattern as languages.class.php::extension_name_compare()).
        $revision_date = $language['revision_date'] ?? null;
        $revision_date = is_scalar($revision_date) ? (string) $revision_date : '';

        $revision_id = $language['revision_id'] ?? null;
        $revision_id = is_scalar($revision_id) ? (string) $revision_id : '';

        $extension_id = $language['extension_id'] ?? null;
        $extension_id = is_scalar($extension_id) ? (string) $extension_id : '';

        $download_url = $language['download_url'] ?? null;
        $download_url = is_scalar($download_url) ? (string) $download_url : '';

        [$date] = explode(' ', $revision_date);

        $url_auto_install = htmlentities($base_url)
          . '&amp;revision=' . $revision_id
          . '&amp;pwg_token=' . get_pwg_token()
        ;

        $template->append('languages', [
            'EXT_NAME' => $language['extension_name'],
            'EXT_DESC' => $language['extension_description'],
            'EXT_URL' => $pem_base_url . '/extension_view.php?eid=' . $extension_id,
            'VERSION' => $language['revision_name'],
            'VER_DESC' => $language['revision_description'],
            'DATE' => $date,
            'AUTHOR' => $language['author_name'],
            'URL_INSTALL' => $url_auto_install,
            'URL_DOWNLOAD' => $download_url . '&amp;origin=piwigo_download',
        ]);
    }
} else {
    push_languages_new_page_message('errors', l10n('Can\'t connect to server.'), $page);
}
$template->assign('ADMIN_PAGE_TITLE', l10n('Languages'));
$template->assign('isWebmaster', (is_webmaster()) ? 1 : 0);

$template->assign_var_from_handle('ADMIN_CONTENT', 'languages');
