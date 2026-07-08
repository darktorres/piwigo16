<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+
global $page;

$selected_related_tags_info = [];

foreach ($page['tags'] as $key => $tag) {
    $other_tags = $page['tags'];
    unset($other_tags[$key]);

    $selected_related_tags_info[$key] =
    [
        'tag_name' => trigger_change('render_tag_name', $page['tags'][$key]['name'], $page['tags'][$key]),
        'item_count' => '',
        'index_url' => make_index_url(
            [
                'tags' => [$page['tags'][$key]],
            ]
        ),
        'remove_url' => make_index_url(
            [
                'tags' => $other_tags,
            ]
        ),
    ];
}

$template->assign(
    [
        'SELECT_RELATED_TAGS' => $selected_related_tags_info,
    ]
);

$template->set_filename('selected_tags', 'include/selected_tags.inc.tpl');
$template->assign_var_from_handle('SELECTED_TAGS_TEMPLATE', 'selected_tags');
