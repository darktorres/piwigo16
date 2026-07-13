<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

use Piwigo\Admin\Extensions\ExtensionLifecycle;
use Piwigo\Admin\Extensions\ExtensionRepository;
use Piwigo\Admin\Extensions\ExtensionScanner;
use Piwigo\Admin\Extensions\ExtensionType;
use Piwigo\Admin\Extensions\PemCatalog;
use Piwigo\Admin\Extensions\ZipExtractor;
use Piwigo\Db\DbConnection;
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

if (! is_webmaster()) {
    // include/common.inc.php seeds $page['warnings'] as [] -- always an
    // array; defensively re-initialized here in case that invariant is
    // ever broken by a prior include.
    $page_warnings = $page['warnings'] ?? [];
    if (! is_array($page_warnings)) {
        $page_warnings = [];
    }
    $page_warnings[] = str_replace('%s', l10n('user_status_webmaster'), l10n('%s status is required to edit parameters.'));
    $page['warnings'] = $page_warnings;
}

$template->set_filenames([
    'languages' => 'languages_installed.tpl',
]);

// admin.php sets $page['page'] to the requested admin page slug (a string)
// before including this script; narrow the mixed read-back from the
// array<string, mixed>-typed $page global before interpolating into the URL.
$page_id = $page['page'] ?? null;
$page_id = is_scalar($page_id) ? (string) $page_id : '';
$base_url = get_root_url() . 'admin.php?page=' . $page_id;

$extension_repository = new ExtensionRepository(DbConnection::build());
$pem_catalog = new PemCatalog(new ZipExtractor());
$extension_scanner = new ExtensionScanner();
$extension_lifecycle = new ExtensionLifecycle($extension_repository, $pem_catalog);

$fs_languages = $extension_scanner->scan(ExtensionType::Language);
$db_languages = $extension_repository->findAll(ExtensionType::Language);

// --------------------------------------------------perform requested actions
check_input_parameter('action', $_GET, false, '/^(activate|deactivate|set_default|delete)$/');
check_input_parameter('language', $_GET, false, '/^(' . join('|', array_keys($fs_languages)) . ')$/');

if (isset($_GET['action']) and isset($_GET['language']) and is_webmaster()) {
    // check_input_parameter() above already fatal_error()s if either value
    // is non-scalar or doesn't match the expected pattern; query string
    // values reaching this point are always plain strings in practice, but
    // narrow explicitly for perform_action()'s string parameters.
    $action = $_GET['action'];
    $language_id = $_GET['language'];

    if (is_string($action) and is_string($language_id)) {
        $fs_language_entry = $fs_languages[$language_id] ?? null;
        $page['errors'] = $extension_lifecycle->performAction(ExtensionType::Language, $action, $language_id, $fs_language_entry);

        if (empty($page['errors'])) {
            redirect($base_url);
        }
    }
}

// +-----------------------------------------------------------------------+
// |                     start template output                             |
// +-----------------------------------------------------------------------+
$default_language = get_default_language();

$tpl_languages = [];

foreach ($fs_languages as $language_id => $language) {
    $language['u_action'] = add_url_params($base_url, [
        'language' => $language_id,
    ]);

    if (in_array($language_id, array_keys($db_languages))) {
        $language['state'] = 'active';
        $language['deactivable'] = true;

        if (count($db_languages) <= 1) {
            $language['deactivable'] = false;
            $language['deactivate_tooltip'] = l10n('Impossible to deactivate this language, you need at least one language.');
        }

        if ($language_id == $default_language) {
            $language['deactivable'] = false;
            $language['deactivate_tooltip'] = l10n('Impossible to deactivate this language, first set another language as default.');
        }
    } else {
        $language['state'] = 'inactive';
    }

    if ($language_id == $default_language) {
        $language['is_default'] = true;
        array_unshift($tpl_languages, $language);
    } else {
        $language['is_default'] = false;
        $tpl_languages[] = $language;
    }
}

$template->assign(
    [
        'languages' => $tpl_languages,
    ]
);
$template->append('language_states', 'active');
$template->append('language_states', 'inactive');

$missing_language_ids = array_diff(
    array_keys($db_languages),
    array_keys($fs_languages)
);

foreach ($missing_language_ids as $language_id) {
    $extension_repository->reassignUsersFromLanguage($language_id, get_default_language());
    $extension_repository->delete(ExtensionType::Language, $language_id);
}

$template->assign('isWebmaster', (is_webmaster()) ? 1 : 0);
$template->assign('ADMIN_PAGE_TITLE', l10n('Languages'));
$template->assign('CONF_ENABLE_EXTENSIONS_INSTALL', $conf['enable_extensions_install']);

$template->assign_var_from_handle('ADMIN_CONTENT', 'languages');
