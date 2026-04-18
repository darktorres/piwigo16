<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

use Piwigo\admin\inc\functions_admin;
use Piwigo\admin\inc\tabsheet;
use Piwigo\inc\functions;
use Piwigo\inc\functions_html;
use Piwigo\inc\functions_plugins;
use Piwigo\inc\functions_url;
use Piwigo\inc\functions_user;

if (! defined('PHPWG_ROOT_PATH')) {
    exit('Hacking attempt!');
}

functions_user::check_status(ACCESS_ADMINISTRATOR);

// +-----------------------------------------------------------------------+
// | tabs                                                                  |
// +-----------------------------------------------------------------------+

$my_base_url = functions_url::get_root_url() . 'admin.php?page=';

$tabsheet = new tabsheet();
$tabsheet->set_id('tags');
$tabsheet->select('');
$tabsheet->assign();

// +-----------------------------------------------------------------------+
// |                           delete orphan tags                          |
// +-----------------------------------------------------------------------+

if (isset($_GET['action']) &&
    $_GET['action'] == 'delete_orphans'
) {
    functions::check_pwg_token();

    functions_admin::delete_orphan_tags();
    $_SESSION['message_tags'] = functions::l10n('Orphan tags deleted');
    functions::redirect(functions_url::get_root_url() . 'admin.php?page=tags');
}

// +-----------------------------------------------------------------------+
// |                             template init                             |
// +-----------------------------------------------------------------------+

$template->set_filenames([
    'tags' => 'tags.tpl',
]);

$template->assign(
    [
        'F_ACTION' => './admin.php?page=tags',
        'PWG_TOKEN' => functions::get_pwg_token(),
    ]
);

// +-----------------------------------------------------------------------+
// |                              orphan tags                              |
// +-----------------------------------------------------------------------+

$warning_tags = '';

$orphan_tags = functions_admin::get_orphan_tags();

$orphan_tag_names_array = '[]';
$orphan_tag_names = [];

foreach ($orphan_tags as $tag) {
    $orphan_tag_names[] = functions_plugins::trigger_change('render_tag_name', $tag['name'], $tag);
}

if ($orphan_tag_names !== []) {
    $url = functions_url::get_root_url() . 'admin.php?page=tags&amp;action=delete_orphans&amp;pwg_token=' . functions::get_pwg_token();
    $review = functions::l10n('Review');
    $warning_tags = sprintf(
        functions::l10n('You have %d orphan tags %s'),
        count($orphan_tag_names),
        <<<HTML
        <a class="icon-eye" data-url="{$url}">
            {$review}
        </a>
        HTML
    );

    $orphan_tag_names_array = '["';
    $orphan_tag_names_array .= implode(
        '" ,"',
        array_map(
            htmlentities(...),
            $orphan_tag_names,
            array_fill(0, count($orphan_tag_names), ENT_QUOTES)
        )
    );
    $orphan_tag_names_array .= '"]';
}

$template->assign(
    [
        'orphan_tag_names_array' => $orphan_tag_names_array,
        'warning_tags' => $warning_tags,
    ]
);

$message_tags = '';

if (isset($_SESSION['message_tags'])) {
    $message_tags = $_SESSION['message_tags'];
    unset($_SESSION['message_tags']);
}

$template->assign('message_tags', $message_tags);

// +-----------------------------------------------------------------------+
// |                             form creation                             |
// +-----------------------------------------------------------------------+
$per_page = 100;

// tag counters
$query = <<<SQL
    SELECT tag_id, COUNT(image_id) AS counter
    FROM image_tag
    GROUP BY tag_id;
    SQL;
$tag_counters = $conf->sql_backend::query2array($query, 'tag_id', 'counter');

// all tags
$query = <<<SQL
    SELECT name, id, url_name
    FROM tags;
    SQL;
$result = $conf->sql_backend::pwg_query($query);
$all_tags = [];

while ($tag = $conf->sql_backend::pwg_db_fetch_assoc($result)) {
    $raw_name = $tag['name'];
    $tag['raw_name'] = $raw_name;
    $tag['name'] = functions_plugins::trigger_change('render_tag_name', $raw_name, $tag);
    $counter = intval($tag_counters[$tag['id']]);

    if ($counter > 0) {
        $tag['counter'] = intval($tag_counters[$tag['id']]);
    }

    $alt_names = functions_plugins::trigger_change('get_tag_alt_names', [], $raw_name);
    $alt_names = array_diff(array_unique($alt_names), [$tag['name']]);

    if ($alt_names !== []) {
        $tag['alt_names'] = implode(', ', $alt_names);
    }

    $all_tags[] = $tag;
}

usort($all_tags, functions_html::tag_alpha_compare(...));

$template->assign(
    [
        'first_tags' => array_slice($all_tags, 0, $per_page),
        'data' => $all_tags,
        'total' => count($all_tags),
        'per_page' => $per_page,
        'ADMIN_PAGE_TITLE' => functions::l10n('Tags'),
    ]
);

// +-----------------------------------------------------------------------+
// |                           sending html code                           |
// +-----------------------------------------------------------------------+

require_once __DIR__ . '/../inc/vite_helper.php';
\Piwigo\Vite\vite_assign_modules($template, ['tags', 'pwgConfirm']);

$template->assign_var_from_handle('ADMIN_CONTENT', 'tags');
