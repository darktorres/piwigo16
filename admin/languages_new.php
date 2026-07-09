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

include_once PHPWG_ROOT_PATH . 'admin/include/languages.class.php';

$template->set_filenames([
    'languages' => 'languages_new.tpl',
]);

$base_url = get_root_url() . 'admin.php?page=' . $page['page'] . '&tab=' . $page['tab'];

$languages = new languages();
$languages->get_db_languages();

// +-----------------------------------------------------------------------+
// |                           setup check                                 |
// +-----------------------------------------------------------------------+

$languages_dir = PHPWG_ROOT_PATH . 'language';
if (! is_writable($languages_dir)) {
    $page['errors'][] = l10n('Add write access to the "%s" directory', 'language');
}

// +-----------------------------------------------------------------------+
// |                       perform installation                            |
// +-----------------------------------------------------------------------+

if (isset($_GET['revision'])) {
    if (! is_webmaster()) {
        $page['errors'][] = l10n('Webmaster status is required.');
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

    switch ($installstatus) {
        case 'ok':
            $page['infos'][] = l10n('Language has been successfully installed');
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
            $page['errors'][] = l10n('An error occured during extraction (%s).', htmlspecialchars($installstatus));
    }
}

// +-----------------------------------------------------------------------+
// |                     start template output                             |
// +-----------------------------------------------------------------------+
if ($languages->get_server_languages(true)) {
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
            'EXT_URL' => PEM_URL . '/extension_view.php?eid=' . $extension_id,
            'VERSION' => $language['revision_name'],
            'VER_DESC' => $language['revision_description'],
            'DATE' => $date,
            'AUTHOR' => $language['author_name'],
            'URL_INSTALL' => $url_auto_install,
            'URL_DOWNLOAD' => $download_url . '&amp;origin=piwigo_download',
        ]);
    }
} else {
    $page['errors'][] = l10n('Can\'t connect to server.');
}
$template->assign('ADMIN_PAGE_TITLE', l10n('Languages'));
$template->assign('isWebmaster', (is_webmaster()) ? 1 : 0);

$template->assign_var_from_handle('ADMIN_CONTENT', 'languages');
