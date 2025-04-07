<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

use Piwigo\admin\inc\tabsheet;
use Piwigo\inc\dblayer\functions_mysqli;
use Piwigo\inc\DerivativeImage;
use Piwigo\inc\functions;
use Piwigo\inc\functions_comment;
use Piwigo\inc\functions_plugins;
use Piwigo\inc\functions_url;
use Piwigo\inc\functions_user;

if (! defined('PHPWG_ROOT_PATH')) {
    exit('Hacking attempt!');
}

if (isset($_GET['start']) &&
    is_numeric($_GET['start'])
) {
    $page['start'] = $_GET['start'];
} else {
    $page['start'] = 0;
}

// +-----------------------------------------------------------------------+
// | Check Access and exit when user status is not ok                      |
// +-----------------------------------------------------------------------+

functions_user::check_status(ACCESS_ADMINISTRATOR);

// +-----------------------------------------------------------------------+
// |                                actions                                |
// +-----------------------------------------------------------------------+

if (! empty($_POST)) {
    if (empty($_POST['comments'])) {
        $page['errors'][] = functions::l10n('Select at least one comment');
    } else {
        require_once __DIR__ . '/../inc/functions_comment.php';
        functions::check_input_parameter('comments', $_POST, true, PATTERN_ID);

        if (isset($_POST['validate'])) {
            functions_comment::validate_user_comment($_POST['comments']);

            $page['infos'][] = functions::l10n_dec(
                '%d user comment validated',
                '%d user comments validated',
                count($_POST['comments'])
            );
        }

        if (isset($_POST['reject'])) {
            functions_comment::delete_user_comment($_POST['comments']);

            $page['infos'][] = functions::l10n_dec(
                '%d user comment rejected',
                '%d user comments rejected',
                count($_POST['comments'])
            );
        }
    }
}

// +-----------------------------------------------------------------------+
// |                             template init                             |
// +-----------------------------------------------------------------------+

$template->set_filenames([
    'comments' => 'comments.tpl',
]);

$template->assign(
    [
        'F_ACTION' => functions_url::get_root_url() . 'admin.php?page=comments',
    ]
);

// +-----------------------------------------------------------------------+
// | Tabs                                                                  |
// +-----------------------------------------------------------------------+

$my_base_url = functions_url::get_root_url() . 'admin.php?page=';

$tabsheet = new tabsheet();
$tabsheet->set_id('comments');
$tabsheet->select('');
$tabsheet->assign();

// +-----------------------------------------------------------------------+
// |                           comments display                            |
// +-----------------------------------------------------------------------+

$nb_total = 0;
$nb_pending = 0;

$query = <<<SQL
    SELECT COUNT(*) AS counter, validated
    FROM comments
    GROUP BY validated;
    SQL;
$result = functions_mysqli::pwg_query($query);

while ($row = functions_mysqli::pwg_db_fetch_assoc($result)) {
    $nb_total += $row['counter'];

    if ($row['validated'] == 'false') {
        $nb_pending = $row['counter'];
    }
}

if (! isset($_GET['filter']) &&
    $nb_pending > 0
) {
    $page['filter'] = 'pending';
} else {
    $page['filter'] = 'all';
}

if (isset($_GET['filter']) &&
    $_GET['filter'] == 'pending'
) {
    $page['filter'] = $_GET['filter'];
}

$template->assign(
    [
        'nb_total' => $nb_total,
        'nb_pending' => $nb_pending,
        'filter' => $page['filter'],
    ]
);

$where_clauses = ['1 = 1'];

if ($page['filter'] == 'pending') {
    $where_clauses[] = "validated='false'";
}

$where_clause = implode(' AND ', $where_clauses);
$query = <<<SQL
    SELECT c.id, c.image_id, c.date, c.author, {$conf->user_fields['username']} AS username, c.content,
        i.path, i.representative_ext, validated, c.anonymous_id
    FROM comments AS c
    INNER JOIN images AS i ON i.id = c.image_id
    LEFT JOIN users AS u ON u.{$conf->user_fields['id']} = c.author_id
    WHERE {$where_clause}
    ORDER BY c.date DESC
    LIMIT {$page['start']}, {$conf->comments_page_nb_comments};
    SQL;
$result = functions_mysqli::pwg_query($query);

while ($row = functions_mysqli::pwg_db_fetch_assoc($result)) {
    $thumb = DerivativeImage::thumb_url(
        [
            'id' => $row['image_id'],
            'path' => $row['path'],
            'representative_ext' => $row['representative_ext'],
        ]
    );

    if (empty($row['author_id'])) {
        $author_name = $row['author'];
    } else {
        $author_name = stripslashes($row['username']);
    }

    $template->append(
        'comments',
        [
            'U_PICTURE' => functions_url::get_root_url() . 'admin.php?page=photo-' . $row['image_id'],
            'ID' => $row['id'],
            'TN_SRC' => $thumb,
            'AUTHOR' => functions_plugins::trigger_change('render_comment_author', $author_name),
            'DATE' => functions::format_date($row['date'], ['day_name', 'day', 'month', 'year', 'time']),
            'CONTENT' => functions_plugins::trigger_change('render_comment_content', $row['content']),
            'IS_PENDING' => ($row['validated'] == 'false'),
            'IP' => $row['anonymous_id'],
        ]
    );

    $list[] = $row['id'];
}

// +-----------------------------------------------------------------------+
// |                            navigation bar                             |
// +-----------------------------------------------------------------------+

$navbar = functions::create_navigation_bar(
    functions_url::get_root_url() . 'admin.php' . functions_url::get_query_string_diff(['start']),
    ($page['filter'] == 'pending' ? $nb_pending : $nb_total),
    $page['start'],
    $conf->comments_page_nb_comments
);

$template->assign('navbar', $navbar);
$template->assign('ADMIN_PAGE_TITLE', functions::l10n('User comments'));

// +-----------------------------------------------------------------------+
// |                           sending html code                           |
// +-----------------------------------------------------------------------+

$template->assign_var_from_handle('ADMIN_CONTENT', 'comments');
