<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

use Piwigo\inc\dblayer\functions_mysqli;
use Piwigo\inc\functions;
use Piwigo\inc\functions_plugins;
use Piwigo\inc\functions_user;

if (! defined('PHPWG_ROOT_PATH')) {
    exit('Hacking attempt!');
}

// +-----------------------------------------------------------------------+
// | Check Access and exit when user status is not ok                      |
// +-----------------------------------------------------------------------+
functions_user::check_status(ACCESS_ADMINISTRATOR);

// +-----------------------------------------------------------------------+
// | tabs                                                                  |
// +-----------------------------------------------------------------------+

$page['tab'] = 'search';
require __DIR__ . '/../admin/inc/albums_tab.php';

// +-----------------------------------------------------------------------+
// | Get Categories                                                        |
// +-----------------------------------------------------------------------+

$categories = [];

$query = <<<SQL
    SELECT id, name, status, uppercats
    FROM categories;
    SQL;

$result = functions_mysqli::query2array($query);

foreach ($result as $cat) {
    $cat['name'] = functions_plugins::trigger_change('render_category_name', $cat['name'], 'admin_cat_list');

    $private = ($cat['status'] == 'private') ? 1 : 0;

    $parents = explode(',', $cat['uppercats']);

    $content = [$cat['name'], $parents, $private];
    $categories[$cat['id']] = $content;
}

// +-----------------------------------------------------------------------+
// |                       template initialization                         |
// +-----------------------------------------------------------------------+

// let's find a custom placeholder
$query = <<<SQL
    SELECT name
    FROM categories
    ORDER BY RAND()
    LIMIT 1;
    SQL;
$lines = functions_mysqli::query2array($query);
$placeholder = null;

foreach ($lines as $line) {
    $name = functions_plugins::trigger_change('render_category_name', $line['name']);

    if (mb_strlen($name) > 25) {
        $name = mb_substr($name, 0, 25) . '...';
    }

    $placeholder = $name;
    break;
}

if (empty($placeholder)) {
    $placeholder = functions::l10n('Portraits');
}

$template->set_filename('cat_search', 'cat_search.tpl');

$template->assign(
    [
        'data_cat' => $categories,
        'ADMIN_PAGE_TITLE' => functions::l10n('Albums'),
        'placeholder' => $placeholder,
    ]
);

// +-----------------------------------------------------------------------+
// |                          sending html code                            |
// +-----------------------------------------------------------------------+
$template->assign_var_from_handle('ADMIN_CONTENT', 'cat_search');
