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
    if (! is_array($page['warnings'] ?? null)) {
        $page['warnings'] = [];
    }
    $page['warnings'][] = str_replace('%s', l10n('user_status_webmaster'), l10n('%s status is required to edit parameters.'));
}

// admin.php always populates $page['page'] with a string (either the
// validated $_GET['page'] or the 'intro' fallback) before including this
// file, but the shared $page array is typed array<string, mixed>.
$page_page = $page['page'] ?? null;
$page_page = is_string($page_page) ? $page_page : '';
$base_url = get_root_url() . 'admin.php?page=' . $page_page;

$extension_repository = new ExtensionRepository(DbConnection::build());
$pem_catalog = new PemCatalog(new ZipExtractor());
$extension_scanner = new ExtensionScanner();
$extension_lifecycle = new ExtensionLifecycle($extension_repository, $pem_catalog);

// +-----------------------------------------------------------------------+
// |                          perform actions                              |
// +-----------------------------------------------------------------------+

if (isset($_GET['action']) and is_string($_GET['action']) and isset($_GET['theme']) and is_string($_GET['theme'])) {
    $fs_theme_entry = $extension_scanner->scan(ExtensionType::Theme)[$_GET['theme']] ?? null;
    $page['errors'] = $extension_lifecycle->performAction(ExtensionType::Theme, $_GET['action'], $_GET['theme'], $fs_theme_entry);

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

$fs_themes = $extension_scanner->scan(ExtensionType::Theme);
uasort($fs_themes, name_compare(...));

$default_theme = get_default_theme();

$db_theme_ids = array_keys($extension_repository->findAll(ExtensionType::Theme));

$tpl_themes = [];

foreach ($fs_themes as $theme_id => $fs_theme) {
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
        'AUTHOR_URL' => @$fs_theme['author uri'],
        'PARENT' => @$fs_theme['parent'],
        'SCREENSHOT' => $fs_theme['screenshot'],
        'IS_MOBILE' => $fs_theme['mobile'],
        'ADMIN_URI' => @$fs_theme['admin_uri'],
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
        if (isset($fs_theme['activable']) and ! (bool) $fs_theme['activable']) {
            $tpl_theme['ACTIVABLE'] = false;
            $tpl_theme['ACTIVABLE_TOOLTIP'] = l10n('This theme was not designed to be directly activated');
        } else {
            $tpl_theme['ACTIVABLE'] = true;
        }

        $missing_parent = $extension_lifecycle->missingParentTheme($theme_id, $fs_theme);
        if (isset($missing_parent)) {
            $tpl_theme['ACTIVABLE'] = false;

            $tpl_theme['ACTIVABLE_TOOLTIP'] = l10n(
                'Impossible to activate this theme, the parent theme is missing: %s',
                $missing_parent
            );
        }

        // is the theme "deletable" ?
        $children = $extension_lifecycle->getChildrenThemes($theme_id);

        $tpl_theme['DELETABLE'] = true;

        if (count($children) > 0) {
            $tpl_theme['DELETABLE'] = false;

            $tpl_theme['DELETE_TOOLTIP'] = l10n(
                'Impossible to delete this theme. Other themes depends on it: %s',
                implode(', ', array_filter($children, is_string(...)))
            );
        }
    }

    $tpl_themes[] = $tpl_theme;
}

// sort themes by state then by name
// Uniquely named (not cmp()) since PHPStan analyzes every included file
// together and admin/plugins_installed.php declares its own top-level
// cmp() -- same pattern as push_plugins_new_page_message() in
// admin/plugins_new.php.
/**
 * @param array<string, mixed> $a
 * @param array<string, mixed> $b
 */
function cmp_themes(array $a, array $b): int
{
    $s = [
        'active' => 0,
        'inactive' => 1,
    ];

    if ((bool) @$a['IS_DEFAULT']) {
        return -1;
    }
    if ((bool) @$b['IS_DEFAULT']) {
        return 1;
    }

    // 'STATE' and 'NAME' are always plain strings in every $tpl_theme built
    // above ('STATE' is a string literal, 'NAME' comes from
    // themes::fs_theme_name()'s guaranteed-string invariant), but $a/$b are
    // only known as array<string, mixed> here since usort's callback
    // signature can't carry the caller's more precise shape.
    $a_state = $a['STATE'] ?? null;
    $b_state = $b['STATE'] ?? null;
    $a_state = is_string($a_state) ? $a_state : '';
    $b_state = is_string($b_state) ? $b_state : '';

    if ($a_state == $b_state) {
        $a_name = $a['NAME'] ?? null;
        $b_name = $b['NAME'] ?? null;
        $a_name = is_string($a_name) ? $a_name : '';
        $b_name = is_string($b_name) ? $b_name : '';
        return strcasecmp($a_name, $b_name);
    } else {
        return ($s[$a_state] ?? 1) >= ($s[$b_state] ?? 1) ? 1 : -1;
    }
}
usort($tpl_themes, cmp_themes(...));

$template->assign(
    [
        'activate_baseurl' => $base_url . '&amp;action=activate&amp;theme=',
        'deactivate_baseurl' => $base_url . '&amp;action=deactivate&amp;theme=',
        'set_default_baseurl' => $base_url . '&amp;action=set_default&amp;theme=',
        'delete_baseurl' => $base_url . '&amp;action=delete&amp;theme=',

        'tpl_themes' => $tpl_themes,
    ]
);

trigger_notify('loc_end_themes_installed');

$template->assign('isWebmaster', (is_webmaster()) ? 1 : 0);
$template->assign('ADMIN_PAGE_TITLE', l10n('Themes'));
$template->assign('CONF_ENABLE_EXTENSIONS_INSTALL', $conf['enable_extensions_install']);

$template->set_filenames([
    'themes' => 'themes_installed.tpl',
]);
$template->assign_var_from_handle('ADMIN_CONTENT', 'themes');
