<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

use Piwigo\admin\inc\themes;
use Piwigo\inc\functions;
use Piwigo\inc\functions_plugins;
use Piwigo\inc\functions_url;
use Piwigo\inc\functions_user;

if (! defined('PHPWG_ROOT_PATH')) {
    exit('Hacking attempt!');
}

if (! functions_user::is_webmaster()) {
    $page['warnings'][] = str_replace('%s', functions::l10n('user_status_webmaster'), functions::l10n('%s status is required to edit parameters.'));
}

$base_url = functions_url::get_root_url() . 'admin.php?page=' . $page['page'];

$themes = new themes();

// +-----------------------------------------------------------------------+
// |                          perform actions                              |
// +-----------------------------------------------------------------------+

if (isset($_GET['action']) &&
    isset($_GET['theme'])
) {
    $page['errors'] = $themes->perform_action($_GET['action'], $_GET['theme']);

    if (empty($page['errors'])) {
        if ($_GET['action'] == 'activate' ||
            $_GET['action'] == 'deactivate'
        ) {
            $template->delete_compiled_templates();
        }

        functions::redirect($base_url);
    }
}

// +-----------------------------------------------------------------------+
// |                     start template output                             |
// +-----------------------------------------------------------------------+

$themes->sort_fs_themes();

$default_theme = functions_user::get_default_theme();

$db_themes = $themes->get_db_themes();
$db_theme_ids = [];

foreach ($db_themes as $db_theme) {
    $db_theme_ids[] = $db_theme['id'];
}

$tpl_themes = [];

foreach ($themes->fs_themes as $theme_id => $fs_theme) {
    if ($theme_id == 'default') {
        continue;
    }

    $tpl_theme = [
        'ID' => $theme_id,
        'NAME' => $fs_theme['name'],
        'VISIT_URL' => $fs_theme['uri'],
        'VERSION' => $fs_theme['version'],
        'DESC' => $fs_theme['description'],
        'AUTHOR' => $fs_theme['author'],
        'AUTHOR_URL' => ($fs_theme['author uri'] ?? null),
        'PARENT' => ($fs_theme['parent'] ?? null),
        'SCREENSHOT' => $fs_theme['screenshot'],
        'IS_MOBILE' => $fs_theme['mobile'],
        'ADMIN_URI' => ($fs_theme['admin_uri'] ?? null),
    ];

    if (in_array($theme_id, $db_theme_ids)) {
        $tpl_theme['STATE'] = 'active';
        $tpl_theme['IS_DEFAULT'] = ($theme_id == $default_theme);
        $tpl_theme['DEACTIVATABLE'] = true;

        if (count($db_theme_ids) <= 1) {
            $tpl_theme['DEACTIVATABLE'] = false;
            $tpl_theme['DEACTIVATE_TOOLTIP'] = functions::l10n('Impossible to deactivate this theme, you need at least one theme.');
        }

        if ($tpl_theme['IS_DEFAULT']) {
            $tpl_theme['DEACTIVATABLE'] = false;
            $tpl_theme['DEACTIVATE_TOOLTIP'] = functions::l10n('Impossible to deactivate the default theme.');
        }
    } else {
        $tpl_theme['STATE'] = 'inactive';

        // is the theme "activatable" ?
        if (isset($fs_theme['activatable']) &&
            ! $fs_theme['activatable']
        ) {
            $tpl_theme['ACTIVATABLE'] = false;
            $tpl_theme['ACTIVATABLE_TOOLTIP'] = functions::l10n('This theme was not designed to be directly activated');
        } else {
            $tpl_theme['ACTIVATABLE'] = true;
        }

        $missing_parent = $themes->missing_parent_theme($theme_id);

        if (isset($missing_parent)) {
            $tpl_theme['ACTIVATABLE'] = false;

            $tpl_theme['ACTIVATABLE_TOOLTIP'] = functions::l10n(
                'Impossible to activate this theme, the parent theme is missing: %s',
                $missing_parent
            );
        }

        // is the theme "deletable" ?
        $children = $themes->get_children_themes($theme_id);

        $tpl_theme['DELETABLE'] = true;

        if ($children !== []) {
            $tpl_theme['DELETABLE'] = false;

            $tpl_theme['DELETE_TOOLTIP'] = functions::l10n(
                'Impossible to delete this theme. Other themes depends on it: %s',
                implode(', ', $children)
            );
        }
    }

    $tpl_themes[] = $tpl_theme;
}

usort($tpl_themes, function (
    array $a,
    array $b
): int {
    // sort themes by state then by name
    $s = [
        'active' => 0,
        'inactive' => 1,
    ];

    if (($a['IS_DEFAULT'] ?? null)) {
        return -1;
    }

    if (($b['IS_DEFAULT'] ?? null)) {
        return 1;
    }

    if ($a['STATE'] === $b['STATE']) {
        return strcasecmp($a['NAME'], $b['NAME']);
    }

    return $s[$a['STATE']] >= $s[$b['STATE']] ? 1 : -1;
});

$template->assign(
    [
        'activate_baseurl' => $base_url . '&amp;action=activate&amp;theme=',
        'deactivate_baseurl' => $base_url . '&amp;action=deactivate&amp;theme=',
        'set_default_baseurl' => $base_url . '&amp;action=set_default&amp;theme=',
        'delete_baseurl' => $base_url . '&amp;action=delete&amp;theme=',

        'tpl_themes' => $tpl_themes,
    ]
);

functions_plugins::trigger_notify('loc_end_themes_installed');

$template->assign('isWebmaster', (functions_user::is_webmaster()) ? 1 : 0);
$template->assign('ADMIN_PAGE_TITLE', functions::l10n('Themes'));
$template->assign('CONF_ENABLE_EXTENSIONS_INSTALL', $conf->enable_extensions_install);

$template->set_filenames([
    'themes' => 'themes_installed.tpl',
]);

$page_data = [
    'strDeleteTheme' => functions::l10n('Are you sure you want to delete this theme?'),
    'strYesImSure' => functions::l10n('Yes, I am sure'),
    'strIChangedMyMind' => functions::l10n('No, I have changed my mind'),
    'strDeleteThemeMsg' => functions::l10n('Are you sure you want to delete the theme "%s"?'),
];
$template->assign('page_data_json', json_encode($page_data));

require_once __DIR__ . '/../inc/vite_helper.php';
\Piwigo\Vite\vite_assign_modules($template, ['themes_installed']);

$template->assign_var_from_handle('ADMIN_CONTENT', 'themes');
