<?php

declare(strict_types=1);

use Piwigo\Admin\tabsheet;

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


include_once(PHPWG_ROOT_PATH.'admin/include/functions.php');
check_status(ACCESS_ADMINISTRATOR);

// +-----------------------------------------------------------------------+
// | tabs                                                                  |
// +-----------------------------------------------------------------------+

$my_base_url = get_root_url().'admin.php?page=';

$tabsheet = new tabsheet();
$tabsheet->set_id('tags');
$tabsheet->select('');
$tabsheet->assign();

// +-----------------------------------------------------------------------+
// |                           delete orphan tags                          |
// +-----------------------------------------------------------------------+

if (isset($_GET['action']) and 'delete_orphans' == $_GET['action']) {
    check_pwg_token();

    delete_orphan_tags();
    $_SESSION['message_tags'] = l10n('Orphan tags deleted');
    redirect(get_root_url().'admin.php?page=tags');
}

// +-----------------------------------------------------------------------+
// |                             template init                             |
// +-----------------------------------------------------------------------+

$template->set_filenames(['tags' => 'tags.tpl']);

$template->assign(
    [
    'F_ACTION' => PHPWG_ROOT_PATH.'admin.php?page=tags',
    'PWG_TOKEN' => get_pwg_token(),
    ]
);

// +-----------------------------------------------------------------------+
// |                              orphan tags                              |
// +-----------------------------------------------------------------------+

$warning_tags = '';

$orphan_tags = get_orphan_tags();

$orphan_tag_names_array = '[]';
$orphan_tag_names = [];
foreach ($orphan_tags as $tag) {
    if (!is_array($tag)) {
        continue;
    }
    $tag_name = is_scalar($tag['name'] ?? null) ? (string) $tag['name'] : '';
    $rendered = trigger_change('render_tag_name', $tag_name, $tag);
    $orphan_tag_names[] = $rendered;
}

if (count($orphan_tag_names) > 0) {
    $warning_tags = sprintf(
        l10n('You have %d orphan tags %s'),
        count($orphan_tag_names),
        '<a 
      class="icon-eye"
      data-url="'.get_root_url().'admin.php?page=tags&amp;action=delete_orphans&amp;pwg_token='.get_pwg_token().'">'
        .l10n('Review').'</a>'
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
$query = '
SELECT tag_id, COUNT(image_id) AS counter
  FROM '.IMAGE_TAG_TABLE.'
  GROUP BY tag_id';
$tag_counters = query2array($query, 'tag_id', 'counter');

// all tags
$query = '
SELECT name, id, url_name
  FROM '.TAGS_TABLE.'
;';
$result = pwg_query($query);
$all_tags = [];
while ($tag = pwg_db_fetch_assoc($result)) {
    $raw_name = $tag['name'];
    $tag['raw_name'] = $raw_name;
    $tag['name'] = trigger_change('render_tag_name', $raw_name, $tag);
    $tag_id_key = is_scalar($tag['id']) ? (string) $tag['id'] : '';
    $counter = intval(@$tag_counters[$tag_id_key]);
    if ($counter > 0) {
        $tag['counter'] = intval(@$tag_counters[$tag_id_key]);
    }

    $alt_names = trigger_change('get_tag_alt_names', [], $raw_name);
    $alt_names = array_diff(array_unique($alt_names), [$tag['name']]);
    if (count($alt_names)) {
        $tag['alt_names'] = implode(', ', $alt_names);
    }
    $all_tags[] = $tag;
}
usort($all_tags, tag_alpha_compare(...));

$template->assign(
    [
    'first_tags' => array_slice($all_tags, 0, $per_page),
    'data' => $all_tags,
    'total' => count($all_tags),
    'per_page' => $per_page,
    'ADMIN_PAGE_TITLE' => l10n('Tags'),
    ]
);

$template->assign('page_data_json', json_encode([
    'pwg_token'                  => get_pwg_token(),
    'total'                      => count($all_tags),
    'orphan_tag_names'           => $orphan_tag_names,
    'str_already_exist'          => l10n('Tag "%s" already exists'),
    'str_and_others_tags'        => l10n('and %s others'),
    'str_clear_selection'        => l10n('Clear Selection'),
    'str_copy'                   => l10n(' (copy)'),
    'str_delete'                 => l10n('Delete tag "%s"?'),
    'str_delete_orphan_tags'     => l10n('Delete orphan tags ?'),
    'str_delete_tags'            => l10n('Delete tags {%s}?'),
    'str_delete_them'            => l10n('Delete them'),
    'str_keep_them'              => l10n('Keep them'),
    'str_merged_into'            => l10n('Tag(s) {%s1} succesfully merged into "%s2"'),
    'str_no_delete_confirmation' => l10n('No, I have changed my mind'),
    'str_no_photos'              => l10n('no photo'),
    'str_number_photos'          => l10n('%d photos'),
    'str_orphan_tags'            => l10n('You have %s1 orphan : %s2'),
    'str_other_copy'             => l10n(' (copy %s)'),
    'str_select_all_tag'         => l10n('Select all %d tags'),
    'str_selection_done'         => l10n('The %d tags on this page are selected'),
    'str_tag_created'            => l10n('Tag "%s" created'),
    'str_tag_deleted'            => l10n('Tag "%s" succesfully deleted'),
    'str_tag_found'              => l10n('<b>%d</b> tag found'),
    'str_tag_rename'             => l10n('Rename "%s"'),
    'str_tag_selected'           => l10n('<b>%d</b> tag selected'),
    'str_tags_deleted'           => l10n('Tags {%s} succesfully deleted'),
    'str_tags_found'             => l10n('<b>%d</b> tags found'),
    'str_yes_delete_confirmation' => l10n('Yes, delete'),
    'str_yes_rename_confirmation' => l10n('Yes, rename'),
], JSON_HEX_TAG | JSON_UNESCAPED_UNICODE));

// +-----------------------------------------------------------------------+
// |                           sending html code                           |
// +-----------------------------------------------------------------------+

$template->assign_var_from_handle('ADMIN_CONTENT', 'tags');
