<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

use Piwigo\Admin\updates;

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

// $page['warnings']/$page['errors'] are always initialized to an array by
// common.inc.php, but that isn't visible across the include() boundary --
// narrow them once here so the appends below type-check.
$page['warnings'] = is_array($page['warnings'] ?? null) ? $page['warnings'] : [];
$page['errors'] = is_array($page['errors'] ?? null) ? $page['errors'] : [];

if (! (bool) $conf['enable_extensions_install']) {
    die('Piwigo extensions install/update system is disabled');
}

if (! is_webmaster()) {
    $page['warnings'][] = str_replace('%s', l10n('user_status_webmaster'), l10n('%s status is required to edit parameters.'));
}

// unserialize() of a conf value is typed mixed by PHP's own stub -- the
// config table always stores this key as a serialized string (see the
// install-time default row in install/db/103-database.php), but that isn't
// visible across the include() boundary, so narrow before calling
// unserialize().
$updates_ignored_setting = $conf['updates_ignored'] ?? null;
$updates_ignored_raw = is_string($updates_ignored_setting) ? unserialize($updates_ignored_setting) : false;
$conf['updates_ignored'] = is_array($updates_ignored_raw) ? $updates_ignored_raw : [];

// $page['page'] is always set to a validated string page slug by admin.php
// before this file is included -- see the identical narrowing in admin.php.
$page_slug = is_string($page['page'] ?? null) ? $page['page'] : 'updates';
$autoupdate = new updates($page_slug);

$show_reset = false;
if (! $autoupdate->get_server_extensions()) {
    $page['errors'][] = l10n('Can\'t connect to server.');
    return; // TODO: remove this return and add a proper "page killer"
}

$updates_extension = []; // The array of the updates of a type of extension is stored in $updates_extension[type]

// PEM_URL is defined via define('PEM_URL', $conf['alternative_pem_url']) in
// one branch of include/common.inc.php, so PHPStan can't prove it's a
// string across that file boundary -- narrow it once here (see the
// identical narrowing in updates::get_server_extensions()).
$pem_base_url = is_string(PEM_URL) ? PEM_URL : '';

foreach ($autoupdate->types as $type) {
    $fs = 'fs_' . $type;
    $server = 'server_' . $type;
    // Dynamic property access on plugins/themes/languages -- see the
    // identical narrowing (and rationale) in updates::get_server_extensions().
    /** @var array<int|string, array<string, mixed>> $server_ext */
    $server_ext = $autoupdate->{$type}->{$server};
    /** @var array<string, array<string, mixed>> $fs_extensions */
    $fs_extensions = $autoupdate->{$type}->{$fs};

    if (empty($server_ext)) {
        continue;
    }

    $updates_extension[$type] = [];

    $ignored_ids = $conf['updates_ignored'][$type] ?? [];
    if (! is_array($ignored_ids)) {
        $ignored_ids = [];
    }

    foreach ($fs_extensions as $ext_id => $fs_ext) {
        $extension_key = $fs_ext['extension'] ?? null;
        if (! is_string($extension_key) && ! is_int($extension_key)) {
            continue;
        }
        if (! isset($server_ext[$extension_key])) {
            continue;
        }

        $fs_version_raw = $fs_ext['version'] ?? null;
        $fs_version = is_string($fs_version_raw) ? $fs_version_raw : '';

        // In dev mode, do not show update actions
        if ($fs_version === 'auto') {
            continue;
        }

        $ext_info = $server_ext[$extension_key];

        $revision_name_raw = $ext_info['revision_name'] ?? null;
        $revision_name = is_string($revision_name_raw) ? $revision_name_raw : '';

        if (! (bool) safe_version_compare($fs_version, $revision_name, '>=')) {
            $extension_id_raw = $ext_info['extension_id'] ?? null;
            $extension_id = (is_string($extension_id_raw) || is_int($extension_id_raw)) ? $extension_id_raw : '';
            $download_url_raw = $ext_info['download_url'] ?? null;
            $download_url = is_string($download_url_raw) ? $download_url_raw : '';
            $revision_description_raw = $ext_info['revision_description'] ?? null;
            $revision_description = is_string($revision_description_raw) ? $revision_description_raw : '';

            array_push(
                $updates_extension[$type],
                [
                    'ID' => $extension_id,
                    'REVISION_ID' => $ext_info['revision_id'],
                    'EXT_ID' => $ext_id,
                    'EXT_NAME' => $fs_ext['name'],
                    'EXT_URL' => $pem_base_url . '/extension_view.php?eid=' . $extension_id . '#changelog',
                    'REV_DESC' => trim($revision_description, " \n\r"),
                    'CURRENT_VERSION' => $fs_version,
                    'NEW_VERSION' => $revision_name,
                    'URL_DOWNLOAD' => $download_url . '&amp;origin=piwigo_download',
                    'IGNORED' => in_array($ext_id, $ignored_ids),
                ]
            );
        }
    }

    if (! empty($ignored_ids)) {
        $show_reset = true;
    }
}

$template->assign('UPDATES_EXTENSION', $updates_extension);
$template->assign('SHOW_RESET', $show_reset);
$template->assign('PWG_TOKEN', get_pwg_token());
$template->assign('EXT_TYPE', $page['page'] == 'updates' ? 'extensions' : $page['page']);
$template->assign('isWebmaster', (is_webmaster()) ? 1 : 0);
$template->set_filename('plugin_admin_content', 'updates_ext.tpl');
$template->assign_var_from_handle('ADMIN_CONTENT', 'plugin_admin_content');
$template->assign('ADMIN_PAGE_TITLE', l10n('Updates'));
