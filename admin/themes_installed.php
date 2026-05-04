<?php

declare(strict_types=1);

use Piwigo\Admin\Themes;

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

if (!defined('PHPWG_ROOT_PATH')) {
    throw new \Piwigo\Exception\AuthException('Hacking attempt!');
}

global $template, $user, $page, $persistent_cache, $lang;


if (!is_webmaster()) {
    \Piwigo\Core\PageState::current()->addWarning(str_replace('%s', l10n('user_status_webmaster'), l10n('%s status is required to edit parameters.')));
}

$base_url = get_root_url().'admin.php?page='.$page['page'];

$themes = new Themes();

// +-----------------------------------------------------------------------+
// |                          perform actions                              |
// +-----------------------------------------------------------------------+

if (isset($_GET['action']) and isset($_GET['theme'])) {
    $get_action = is_string($_GET['action']) ? $_GET['action'] : '';
    $get_theme = is_string($_GET['theme']) ? $_GET['theme'] : '';
    $page['errors'] = $themes->perform_action($get_action, $get_theme);

    if (empty($page['errors'])) {
        if ($_GET['action'] == 'activate' or $_GET['action'] == 'deactivate') {
            $template->delete_compiled_templates();
        }
        redirect($base_url);
    }
}

// +-----------------------------------------------------------------------+
// |                     start template output                             |
// +-----------------------------------------------------------------------+

$themes->sort_fs_themes();

$default_theme = get_default_theme();

$db_themes = $themes->get_db_themes();
$db_theme_ids = [];
foreach ($db_themes as $db_theme) {
    $db_theme_ids[] = $db_theme['id'];
}

$tpl_themes = [];

foreach ($themes->fs_themes as $theme_id => $fs_theme) {
    if ($theme_id == 'default' or $theme_id == 'standard_pages') {
        continue;
    }

    $tpl_theme = [
      'ID' => $theme_id,
      'NAME' => $fs_theme['name'],
      'VISIT_URL' => $fs_theme['uri'],
      'VERSION' => $fs_theme['version'],
      'DESC' => $fs_theme['description'],
      'AUTHOR' => $fs_theme['author'],
      'AUTHOR_URL' => $fs_theme['author uri'] ?? null,
      'PARENT' => $fs_theme['parent'] ?? null,
      'SCREENSHOT' => $fs_theme['screenshot'],
      'IS_MOBILE' => $fs_theme['mobile'],
      'ADMIN_URI' => $fs_theme['admin_uri'] ?? null,
      ];

    if (in_array($theme_id, $db_theme_ids)) {
        $tpl_theme['STATE'] = 'active';
        $tpl_theme['IS_DEFAULT'] = ($theme_id == $default_theme);
        $tpl_theme['DEACTIVABLE'] = true;

        if (count($db_theme_ids) <= 1) {
            $tpl_theme['DEACTIVABLE'] = false;
            $tpl_theme['DEACTIVATE_TOOLTIP'] = l10n('Impossible to deactivate this theme, you need at least one theme.');
        }
        if ($tpl_theme['IS_DEFAULT']) {
            $tpl_theme['DEACTIVABLE'] = false;
            $tpl_theme['DEACTIVATE_TOOLTIP'] = l10n('Impossible to deactivate the default theme.');
        }
    } else {
        $tpl_theme['STATE'] = 'inactive';

        // is the theme "activable" ?
        if (isset($fs_theme['activable']) and !$fs_theme['activable']) {
            $tpl_theme['ACTIVABLE'] = false;
            $tpl_theme['ACTIVABLE_TOOLTIP'] = l10n('This theme was not designed to be directly activated');
        } else {
            $tpl_theme['ACTIVABLE'] = true;
        }

        $missing_parent = $themes->missing_parent_theme($theme_id);
        if (isset($missing_parent)) {
            $tpl_theme['ACTIVABLE'] = false;

            $tpl_theme['ACTIVABLE_TOOLTIP'] = l10n(
                'Impossible to activate this theme, the parent theme is missing: %s',
                $missing_parent
            );
        }

        // is the theme "deletable" ?
        $children = $themes->get_children_themes($theme_id);

        $tpl_theme['DELETABLE'] = true;

        if (count($children) > 0) {
            $tpl_theme['DELETABLE'] = false;

            $tpl_theme['DELETE_TOOLTIP'] = l10n(
                'Impossible to delete this theme. Other themes depends on it: %s',
                implode(', ', $children)
            );
        }
    }

    $tpl_themes[] = $tpl_theme;
}

// sort themes by state then by name
/**
 * @param array<mixed> $a
 * @param array<mixed> $b
 */
function cmp(array $a, array $b): int
{
    $s = ['active' => 0, 'inactive' => 1];

    if (!empty($a['IS_DEFAULT'])) {
        return -1;
    }
    if (!empty($b['IS_DEFAULT'])) {
        return 1;
    }

    $aStateRaw = $a['STATE'] ?? null;
    $bStateRaw = $b['STATE'] ?? null;
    $a_state = is_string($aStateRaw) ? $aStateRaw : '';
    $b_state = is_string($bStateRaw) ? $bStateRaw : '';
    $aNameRaw = $a['NAME'] ?? null;
    $bNameRaw = $b['NAME'] ?? null;
    $a_name = is_scalar($aNameRaw) ? (string) $aNameRaw : '';
    $b_name = is_scalar($bNameRaw) ? (string) $bNameRaw : '';
    if ($a_state == $b_state) {
        return strcasecmp($a_name, $b_name);
    } else {
        return (($s[$a_state] ?? 0) >= ($s[$b_state] ?? 0) ? 1 : -1);
    }
}
usort($tpl_themes, cmp(...));

$template->assign(
    [
      'activate_baseurl' => $base_url.'&amp;action=activate&amp;theme=',
      'deactivate_baseurl' => $base_url.'&amp;action=deactivate&amp;theme=',
      'set_default_baseurl' => $base_url.'&amp;action=set_default&amp;theme=',
      'delete_baseurl' => $base_url.'&amp;action=delete&amp;theme=',

      'tpl_themes' => $tpl_themes,
    ]
);

trigger_notify('loc_end_themes_installed');

$template->assign('isWebmaster', (is_webmaster()) ? 1 : 0);
$template->assign('ADMIN_PAGE_TITLE', l10n('Themes'));
$template->assign('CONF_ENABLE_EXTENSIONS_INSTALL', \Piwigo\Config\Config::enableExtensionsInstall());
$template->assign('page_data_json', json_encode([
    'str_delete_theme_confirm' => l10n('Are you sure you want to delete the theme "%s"?'),
], JSON_HEX_TAG | JSON_UNESCAPED_UNICODE));

$template->set_filenames(['themes' => 'themes_installed.tpl']);
$template->assign_var_from_handle('ADMIN_CONTENT', 'themes');
