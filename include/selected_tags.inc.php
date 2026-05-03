<?php

declare(strict_types=1);

global $persistent_cache;

use Piwigo\Template\TemplateRegistry;

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

$template = TemplateRegistry::current();
$pageRaw = $GLOBALS['page'] ?? [];
$pageTagsRaw = is_array($pageRaw) ? ($pageRaw['tags'] ?? []) : [];
$pageTags = is_array($pageTagsRaw) ? $pageTagsRaw : [];

$selected_related_tags_info = [];

foreach ($pageTags as $key => $tag) {
    if (!is_array($tag)) {
        continue;
    }
    $other_tags = $pageTags;
    unset($other_tags[$key]);

    $tagName = is_string($tag['name'] ?? null) ? $tag['name'] : '';
    $selected_related_tags_info[$key] =
    [
      'tag_name' => trigger_change('render_tag_name', $tagName, $tag),
      'item_count' => '',
      'index_url' => make_index_url(
          [
          'tags' => [ $tag ],
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
