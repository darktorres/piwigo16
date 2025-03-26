<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | Piwigo - a PHP based photo gallery                                    |
// +-----------------------------------------------------------------------+
// | Copyright(C) 2008-2014 Piwigo Team                  http://piwigo.org |
// | Copyright(C) 2003-2008 PhpWebGallery Team    http://phpwebgallery.net |
// | Copyright(C) 2002-2003 Pierrick LE GALL   http://le-gall.net/pierrick |
// +-----------------------------------------------------------------------+
// | This program is free software; you can redistribute it and/or modify  |
// | it under the terms of the GNU General Public License as published by  |
// | the Free Software Foundation                                          |
// |                                                                       |
// | This program is distributed in the hope that it will be useful, but   |
// | WITHOUT ANY WARRANTY; without even the implied warranty of            |
// | MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the GNU      |
// | General Public License for more details.                              |
// |                                                                       |
// | You should have received a copy of the GNU General Public License     |
// | along with this program; if not, write to the Free Software           |
// | Foundation, Inc., 59 Temple Place - Suite 330, Boston, MA 02111-1307, |
// | USA.                                                                  |
// +-----------------------------------------------------------------------+

use Piwigo\admin\inc\tabsheet;
use Piwigo\inc\functions;
use Piwigo\inc\functions_url;
use Piwigo\inc\functions_user;
use Piwigo\plugins\LocalFilesEditor\inc\functions_LocalFilesEditor;

if (! defined('PHPWG_ROOT_PATH')) {
    exit('Hacking attempt!');
}

require_once LOCALEDIT_PATH . 'inc/functions_LocalFilesEditor.php';
functions::load_language('plugin.lang', LOCALEDIT_PATH);
$my_base_url = functions_url::get_root_url() . 'admin.php?page=plugin-' . basename(dirname(__FILE__));

functions_user::check_status(ACCESS_WEBMASTER);

// +-----------------------------------------------------------------------+
// |                            Tabssheet
// +-----------------------------------------------------------------------+

if (empty($conf['LocalFilesEditor_tabs'])) {
    $conf['LocalFilesEditor_tabs'] = ['localconf', 'css', 'tpl', 'lang', 'plug'];
}

$page['tab'] = isset($_GET['tab']) ? $_GET['tab'] : $conf['LocalFilesEditor_tabs'][0];

if (! in_array($page['tab'], $conf['LocalFilesEditor_tabs'])) {
    exit('Hacking attempt!');
}

$tabsheet = new tabsheet();

foreach ($conf['LocalFilesEditor_tabs'] as $tab) {
    $tabsheet->add($tab, functions::l10n('locfiledit_onglet_' . $tab), $my_base_url . '-' . $tab);
}

$tabsheet->select($page['tab']);
$tabsheet->assign();

require_once LOCALEDIT_PATH . 'inc/' . $page['tab'] . '.php';

// +-----------------------------------------------------------------------+
// |                           Load backup file
// +-----------------------------------------------------------------------+
if (isset($_POST['restore'])) {
    $content_file = file_get_contents(functions_LocalFilesEditor::get_bak_file($edited_file));
    $page['infos'][] = functions::l10n('locfiledit_bak_loaded1');
    $page['infos'][] = functions::l10n('locfiledit_bak_loaded2');
}

// +-----------------------------------------------------------------------+
// |                            Save file
// +-----------------------------------------------------------------------+
if (isset($_POST['submit'])) {
    functions::check_pwg_token();

    if (! functions_user::is_webmaster()) {
        $page['errors'][] = functions::l10n('locfiledit_webmaster_only');
    } else {
        $content_file = stripslashes($_POST['text']);

        if (functions::get_extension($edited_file) == 'php') {
            $content_file = functions_LocalFilesEditor::eval_syntax($content_file);
        }

        if ($content_file === false) {
            $page['errors'][] = functions::l10n('locfiledit_syntax_error');
        } else {
            if ($page['tab'] == 'plug' and
                ! is_dir(PHPWG_PLUGINS_PATH . 'PersonalPlugin')
            ) {
                mkdir(PHPWG_PLUGINS_PATH . 'PersonalPlugin');
            }

            if (file_exists($edited_file)) {
                copy($edited_file, functions_LocalFilesEditor::get_bak_file($edited_file));
                $page['infos'][] = functions::l10n('locfiledit_saved_bak', substr(functions_LocalFilesEditor::get_bak_file($edited_file), 2));
            }

            $file = fopen($edited_file, 'w');

            if ($file) {
                fwrite($file, $content_file);
                fclose($file);
                array_unshift($page['infos'], functions::l10n('locfiledit_save_config'));
                $template->delete_compiled_templates();
            } else {
                $page['errors'][] = functions::l10n('locfiledit_cant_save');
            }
        }
    }
}

// +-----------------------------------------------------------------------+
// |                            template initialization
// +-----------------------------------------------------------------------+
$template->set_filenames([
    'plugin_admin_content' => dirname(__FILE__) . '/template/admin.tpl',
]);

if (! empty($edited_file)) {
    if (! empty($page['errors'])) {
        $content_file = stripslashes($_POST['text']);
    }

    $template->assign(
        'zone_edit',
        [
            'EDITED_FILE' => $edited_file,
            'CONTENT_FILE' => htmlspecialchars($content_file),
            'FILE_NAME' => trim($edited_file, './\\'),
        ]
    );

    if (file_exists(functions_LocalFilesEditor::get_bak_file($edited_file))) {
        $template->assign('restore', true);
    }

    if (file_exists($edited_file)) {
        $template->assign('restore_infos', true);
    }
}

$template->assign(
    [
        'F_ACTION' => './admin.php?page=plugin-LocalFilesEditor-' . $page['tab'],
        'LOCALEDIT_PATH' => LOCALEDIT_PATH,
        'PWG_TOKEN' => functions::get_pwg_token(),
        'CODEMIRROR_MODE' => $codemirror_mode,
    ]
);

$template->assign_var_from_handle('ADMIN_CONTENT', 'plugin_admin_content');
