<?php

declare(strict_types=1);

use Piwigo\Admin\languages;

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

if (!defined('PHPWG_ROOT_PATH')) {
    die('Hacking attempt!');
}

global $template, $user, $page, $persistent_cache, $lang;


if (!\Piwigo\Core\Config::enableExtensionsInstall()) {
    die('Piwigo extensions install/update system is disabled');
}

$template->set_filenames(['languages' => 'languages_new.tpl']);

$base_url = get_root_url().'admin.php?page='.$page['page'].'&tab='.$page['tab'];

$languages = new languages();
$languages->get_db_languages();

// +-----------------------------------------------------------------------+
// |                           setup check                                 |
// +-----------------------------------------------------------------------+

$languages_dir = PHPWG_ROOT_PATH.'language';
if (!is_writable($languages_dir)) {
    \Piwigo\Core\PageState::current()->addError(l10n('Add write access to the "%s" directory', 'language'));
}

// +-----------------------------------------------------------------------+
// |                       perform installation                            |
// +-----------------------------------------------------------------------+

if (isset($_GET['revision'])) {
    if (!is_webmaster()) {
        \Piwigo\Core\PageState::current()->addError(l10n('Webmaster status is required.'));
    } else {
        check_pwg_token();

        $install_status = $languages->extract_language_files('install', is_string($_GET['revision']) ? $_GET['revision'] : '');

        redirect($base_url.'&installstatus='.$install_status);
    }
}

// +-----------------------------------------------------------------------+
// |                        installation result                            |
// +-----------------------------------------------------------------------+
if (isset($_GET['installstatus'])) {
    switch ($_GET['installstatus']) {
        case 'ok':
            \Piwigo\Core\PageState::current()->addInfo(l10n('Language has been successfully installed'));
            break;

        case 'temp_path_error':
            \Piwigo\Core\PageState::current()->addError(l10n('Can\'t create temporary file.'));
            break;

        case 'dl_archive_error':
            \Piwigo\Core\PageState::current()->addError(l10n('Can\'t download archive.'));
            break;

        case 'archive_error':
            \Piwigo\Core\PageState::current()->addError(l10n('Can\'t read or extract archive.'));
            break;

        default:
            $installStatus = is_scalar($_GET['installstatus']) ? (string) $_GET['installstatus'] : '';
            \Piwigo\Core\PageState::current()->addError(l10n('An error occured during extraction (%s).', htmlspecialchars($installStatus)));
    }
}

// +-----------------------------------------------------------------------+
// |                     start template output                             |
// +-----------------------------------------------------------------------+
if ($languages->get_server_languages(true)) {
    foreach ($languages->server_languages as $language) {
        $revDate = is_scalar($language['revision_date'] ?? null) ? (string) $language['revision_date'] : '';
        list($date, ) = explode(' ', $revDate);

        $revId = is_scalar($language['revision_id'] ?? null) ? (string) $language['revision_id'] : '';
        $url_auto_install = htmlentities($base_url)
          . '&amp;revision=' . $revId
          . '&amp;pwg_token='.get_pwg_token()
        ;

        $extId = is_scalar($language['extension_id'] ?? null) ? (string) $language['extension_id'] : '';
        $dlUrl = is_scalar($language['download_url'] ?? null) ? (string) $language['download_url'] : '';
        $template->append('languages', [
          'EXT_NAME' => is_scalar($language['extension_name'] ?? null) ? (string) $language['extension_name'] : '',
          'EXT_DESC' => is_scalar($language['extension_description'] ?? null) ? (string) $language['extension_description'] : '',
          'EXT_URL' => PEM_URL.'/extension_view.php?eid='.$extId,
          'VERSION' => is_scalar($language['revision_name'] ?? null) ? (string) $language['revision_name'] : '',
          'VER_DESC' => is_scalar($language['revision_description'] ?? null) ? (string) $language['revision_description'] : '',
          'DATE' => $date,
          'AUTHOR' => is_scalar($language['author_name'] ?? null) ? (string) $language['author_name'] : '',
          'URL_INSTALL' => $url_auto_install,
          'URL_DOWNLOAD' => $dlUrl . '&amp;origin=piwigo_download']);
    }
} else {
    \Piwigo\Core\PageState::current()->addError(l10n('Can\'t connect to server.'));
}
$template->assign('ADMIN_PAGE_TITLE', l10n('Languages'));
$template->assign('isWebmaster', (is_webmaster()) ? 1 : 0);

$template->assign_var_from_handle('ADMIN_CONTENT', 'languages');
