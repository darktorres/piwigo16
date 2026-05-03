<?php

declare(strict_types=1);

use Piwigo\Admin\Updates;

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


if (!\Piwigo\Config\Config::enableExtensionsInstall()) {
    die('Piwigo extensions install/update system is disabled');
}

if (!is_webmaster()) {
    \Piwigo\Core\PageState::current()->addWarning(str_replace('%s', l10n('user_status_webmaster'), l10n('%s status is required to edit parameters.')));
}

$updates_ignored_raw = \Piwigo\Config\Config::raw('updates_ignored');
\Piwigo\Config\Config::override('updates_ignored', safe_unserialize(is_string($updates_ignored_raw) ? $updates_ignored_raw : ''));

$autoupdate = new Updates($page['page']);

$show_reset = false;
if (!$autoupdate->get_server_extensions()) {
    \Piwigo\Core\PageState::current()->addError(l10n('Can\'t connect to server.'));
    return; // TODO: remove this return and add a proper "page killer"
}

$updates_extension = []; //The array of the updates of a type of extension is stored in $updates_extension[type]

foreach ($autoupdate->types as $type) {
    $fs = 'fs_'.$type;
    $server = 'server_'.$type;
    $server_ext = $autoupdate->$type->$server;
    $fs_ext = $autoupdate->$type->$fs;

    if (empty($server_ext)) {
        continue;
    }

    $updates_extension[$type] = [];

    foreach ($fs_ext as $ext_id => $fs_ext) {
        if (!isset($fs_ext['extension']) or !isset($server_ext[$fs_ext['extension']])) {
            continue;
        }

        // In dev mode, do not show update actions
        if ('auto' === $fs_ext['version']) {
            continue;
        }

        $ext_info = $server_ext[$fs_ext['extension']];

        $updates_ignored = \Piwigo\Config\Config::raw('updates_ignored');
        $updates_ignored_arr = is_array($updates_ignored) ? $updates_ignored : [];
        $updates_ignored_for_type = is_array($updates_ignored_arr[$type] ?? null) ? $updates_ignored_arr[$type] : [];
        if (!safe_version_compare($fs_ext['version'], $ext_info['revision_name'], '>=')) {
            array_push(
                $updates_extension[$type],
                [
        'ID' => $ext_info['extension_id'],
        'REVISION_ID' => $ext_info['revision_id'],
        'EXT_ID' => $ext_id,
        'EXT_NAME' => $fs_ext['name'],
        'EXT_URL' => PEM_URL.'/extension_view.php?eid='.$ext_info['extension_id'].'#changelog',
        'REV_DESC' => trim((string) $ext_info['revision_description'], " \n\r"),
        'CURRENT_VERSION' => $fs_ext['version'],
        'NEW_VERSION' => $ext_info['revision_name'],
        'URL_DOWNLOAD' => $ext_info['download_url'] . '&amp;origin=piwigo_download',
        'IGNORED' => in_array($ext_id, $updates_ignored_for_type),
        ]
            );
        }
        if (!empty($updates_ignored_for_type)) {
            $show_reset = true;
        }
    }

}

$ext_type = $page['page'] == 'updates' ? 'extensions' : $page['page'];
$template->assign('UPDATES_EXTENSION', $updates_extension);
$template->assign('SHOW_RESET', $show_reset);
$template->assign('PWG_TOKEN', get_pwg_token());
$template->assign('EXT_TYPE', $ext_type);
$template->assign('isWebmaster', (is_webmaster()) ? 1 : 0);
$template->assign('page_data_json', json_encode([
    'pwg_token'      => get_pwg_token(),
    'ext_type'       => $ext_type,
    'str_error_head' => l10n('ERROR'),
    'str_error_msg'  => l10n('an error happened'),
    'str_restore'    => l10n('Reset ignored updates'),
    'str_confirm_update_all' => l10n('Are you sure you want to update all extensions?'),
], JSON_HEX_TAG | JSON_UNESCAPED_UNICODE));
$template->set_filename('plugin_admin_content', 'updates_ext.tpl');
$template->assign_var_from_handle('ADMIN_CONTENT', 'plugin_admin_content');
$template->assign('ADMIN_PAGE_TITLE', l10n('Updates'));
