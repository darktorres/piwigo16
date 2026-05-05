<?php

declare(strict_types=1);

use Piwigo\Exception\AuthException;
use Piwigo\Core\PageState;
use Piwigo\Core\ServiceLocator;
use Piwigo\Language\LanguageRepository;
use Piwigo\Config\Config;
use Piwigo\Admin\Languages;

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

if (!defined('PHPWG_ROOT_PATH')) {
    throw new AuthException('Hacking attempt!');
}

global $template, $user, $page, $persistent_cache, $lang;


if (!is_webmaster()) {
    PageState::current()->addWarning(str_replace('%s', l10n('user_status_webmaster'), l10n('%s status is required to edit parameters.')));
}

$template->set_filenames(['languages' => 'languages_installed.tpl']);

$base_url = get_root_url().'admin.php?page='.$page['page'];

$languages = new Languages();
$languages->get_db_languages();

//--------------------------------------------------perform requested actions
check_input_parameter('action', $_GET, false, '/^(activate|deactivate|set_default|delete)$/');
check_input_parameter('language', $_GET, false, '/^('.join('|', array_keys($languages->fs_languages)).')$/');

if (isset($_GET['action']) and isset($_GET['language']) and is_webmaster()) {
    $page['errors'] = $languages->perform_action(is_string($_GET['action']) ? $_GET['action'] : '', is_string($_GET['language']) ? $_GET['language'] : '');

    if (empty($page['errors'])) {
        redirect($base_url);
    }
}

// +-----------------------------------------------------------------------+
// |                     start template output                             |
// +-----------------------------------------------------------------------+
$default_language = get_default_language();

$tpl_languages = [];

foreach ($languages->fs_languages as $language_id => $language) {
    $language['u_action'] = add_url_params($base_url, ['language' => $language_id]);

    if (in_array($language_id, array_keys($languages->db_languages))) {
        $language['state'] = 'active';
        $language['deactivable'] = true;

        if (count($languages->db_languages) <= 1) {
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
    array_keys($languages->db_languages),
    array_keys($languages->fs_languages)
);

$langRepo = ServiceLocator::get(LanguageRepository::class);
foreach ($missing_language_ids as $language_id) {
    $langRepo->reassignUsers((string) $language_id, get_default_language());
    $langRepo->deactivate((string) $language_id);
}

$template->assign('isWebmaster', (is_webmaster()) ? 1 : 0);
$template->assign('ADMIN_PAGE_TITLE', l10n('Languages'));
$template->assign('CONF_ENABLE_EXTENSIONS_INSTALL', Config::enableExtensionsInstall());
$template->assign('page_data_json', json_encode([
    'str_delete_language_confirm' => l10n('Are you sure you want to delete the language "%s"?'),
], JSON_HEX_TAG | JSON_UNESCAPED_UNICODE));

$template->assign_var_from_handle('ADMIN_CONTENT', 'languages');
