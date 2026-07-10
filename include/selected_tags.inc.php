<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+
/**
 * @var array<string, mixed> $page
 * @var \Template $template
 */
global $page, $template;

$selected_related_tags_info = [];

// $page['tags'] is only known as mixed even though $page itself is
// typed -- narrow it once here and reuse the local var everywhere below.
// It is set in include/functions_url.inc.php to either [] or
// find_tags()'s list of tag rows (int-keyed, one assoc row per tag).
/** @var array<int, array<string, mixed>> $tags */
$tags = is_array($page['tags'] ?? null) ? $page['tags'] : [];

foreach ($tags as $key => $tag) {
    $other_tags = $tags;
    unset($other_tags[$key]);

    $selected_related_tags_info[$key] =
    [
        'tag_name' => trigger_change('render_tag_name', $tag['name'], $tag),
        'item_count' => '',
        'index_url' => make_index_url(
            [
                'tags' => [$tag],
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
