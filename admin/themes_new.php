<?php

declare(strict_types=1);

use Piwigo\Core\ServiceLocator;
use Piwigo\Url\UrlGenerator;
use Piwigo\Admin\Themes;
use Piwigo\Config\Config;
use Piwigo\Core\PageState;
use Piwigo\Exception\AuthException;
use Piwigo\Exception\ConfigException;

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


if (!Config::enableExtensionsInstall()) {
    throw new ConfigException('Piwigo extensions install/update system is disabled');
}

$base_url = ServiceLocator::get(UrlGenerator::class)->admin($page['page']) . '&tab='.$page['tab'];

$themes = new Themes();

// +-----------------------------------------------------------------------+
// |                           setup check                                 |
// +-----------------------------------------------------------------------+

$themes_dir = PHPWG_ROOT_PATH.'themes';
if (!is_writable($themes_dir)) {
    PageState::current()->addError(l10n('Add write access to the "%s" directory', 'themes'));
}

// +-----------------------------------------------------------------------+
// |                       perform installation                            |
// +-----------------------------------------------------------------------+

if (isset($_GET['revision']) and isset($_GET['extension'])) {
    if (!is_webmaster()) {
        PageState::current()->addError(l10n('Webmaster status is required.'));
    } else {
        check_pwg_token();

        $install_status = $themes->extract_theme_files(
            'install',
            is_string($_GET['revision']) ? $_GET['revision'] : '',
            is_string($_GET['extension']) ? $_GET['extension'] : '',
            $theme_id
        );

        redirect($base_url.'&installstatus='.$install_status.'&theme_id='.$theme_id);
    }
}

// +-----------------------------------------------------------------------+
// |                        installation result                            |
// +-----------------------------------------------------------------------+

if (isset($_GET['installstatus'])) {
    switch ($_GET['installstatus']) {
        case 'ok':
            PageState::current()->addInfo(l10n('Theme has been successfully installed'));

            $theme_id_str = is_string($_GET['theme_id'] ?? null) ? $_GET['theme_id'] : '';
            if ($theme_id_str !== '' && isset($themes->fs_themes[$theme_id_str])) {
                pwg_activity(
                    'system',
                    ACTIVITY_SYSTEM_THEME,
                    'install',
                    [
                    'theme_id' => $theme_id_str,
                    'version' => $themes->fs_themes[$theme_id_str]['version'],
          ]
                );
            }
            break;

        case 'temp_path_error':
            PageState::current()->addError(l10n('Can\'t create temporary file.'));
            break;

        case 'dl_archive_error':
            PageState::current()->addError(l10n('Can\'t download archive.'));
            break;

        case 'archive_error':
            PageState::current()->addError(l10n('Can\'t read or extract archive.'));
            break;

        default:
            PageState::current()->addError(l10n(
                'An error occured during extraction (%s).',
                htmlspecialchars(is_scalar($_GET['installstatus']) ? (string) $_GET['installstatus'] : '')
            ));
    }
}

// +-----------------------------------------------------------------------+
// |                          template output                              |
// +-----------------------------------------------------------------------+

$template->set_filenames(['themes' => 'themes_new.tpl']);

if ($themes->get_server_themes(true)) { // only new Themes
    foreach ($themes->server_themes as $theme) {
        $theme_revision_id = is_scalar($theme['revision_id'] ?? null) ? (string) $theme['revision_id'] : '';
        $theme_extension_id = is_scalar($theme['extension_id'] ?? null) ? (string) $theme['extension_id'] : '';
        $url_auto_install = htmlentities($base_url)
          . '&amp;revision=' . $theme_revision_id
          . '&amp;extension=' . $theme_extension_id
          . '&amp;pwg_token='.get_pwg_token()
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
    PageState::current()->addError(l10n('Can\'t connect to server.'));
}

$template->assign(
    'default_screenshot',
    get_root_url().'admin/themes/'.(is_scalar(userprefs_get_param('admin_theme', 'roma')) ? (string) userprefs_get_param('admin_theme', 'roma') : 'roma').'/images/missing_screenshot.png'
);
$template->assign('ADMIN_PAGE_TITLE', l10n('Themes'));

$template->assign_var_from_handle('ADMIN_CONTENT', 'themes');
