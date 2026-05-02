<?php

declare(strict_types=1);

global $template, $user, $page, $persistent_cache, $lang;
// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

// +-----------------------------------------------------------------------+
// |                           initialization                              |
// +-----------------------------------------------------------------------+

define('PHPWG_ROOT_PATH', './');
include_once(PHPWG_ROOT_PATH.'include/common.inc.php');
\Piwigo\Core\Kernel::boot();

check_status(ACCESS_GUEST);

trigger_notify('loc_begin_tags');

// +-----------------------------------------------------------------------+
// |                       page header and options                         |
// +-----------------------------------------------------------------------+

$title = l10n('Tags');
$page['body_id'] = 'theTagsPage';

$template->set_filenames(array('tags' => 'tags.tpl'));

$page['display_mode'] = \Piwigo\Core\Config::tagsDefaultDisplayMode();
$display_mode = input_string('display_mode', null, $_GET);
if ($display_mode !== null && in_array($display_mode, ['cloud', 'letters'])) {
    $page['display_mode'] = $display_mode;
}

foreach (array('cloud', 'letters') as $mode) {
    $template->assign(
        'U_'.strtoupper($mode),
        get_root_url().'tags.php'. (\Piwigo\Core\Config::tagsDefaultDisplayMode() == $mode ? '' : '?display_mode='.$mode)
    );
}

$template->assign('display_mode', $page['display_mode']);

// find all tags available for the current user
$tags = get_available_tags();

// +-----------------------------------------------------------------------+
// |                       letter groups construction                      |
// +-----------------------------------------------------------------------+

if ($page['display_mode'] == 'letters') {
    // we want tags diplayed in alphabetic order
    usort($tags, fn (mixed $a, mixed $b): int => tag_alpha_compare(is_array($a) ? $a : [], is_array($b) ? $b : []));

    $current_letter = null;
    $nb_tags = count($tags);
    $current_column = 1;
    $current_tag_idx = 0;

    $letter = array(
      'tags' => array(),
      );

    foreach ($tags as $tag) {
        $tagArr = is_array($tag) ? $tag : [];
        $tag_letter = mb_strtoupper(mb_substr(pwg_transliterate(is_string($tagArr['name'] ?? null) ? $tagArr['name'] : ''), 0, 1, PWG_CHARSET), PWG_CHARSET);

        if ($current_tag_idx == 0) {
            $current_letter = $tag_letter;
            $letter['TITLE'] = $tag_letter;
        }

        //lettre precedente differente de la lettre suivante
        if ($tag_letter !== $current_letter) {
            if ($current_column < \Piwigo\Core\Config::tagLettersColumnNumber()
                and $current_tag_idx > $current_column * $nb_tags / \Piwigo\Core\Config::tagLettersColumnNumber()) {
                $letter['CHANGE_COLUMN'] = true;
                $current_column++;
            }

            $letter['TITLE'] = $current_letter;

            $template->append(
                'letters',
                $letter
            );

            $current_letter = $tag_letter;
            $letter = array(
              'tags' => array(),
              );
        }

        $letter['tags'][] = array_merge(
            $tagArr,
            array(
            'URL' => make_index_url(array('tags' => array($tag))),
            )
        );

        $current_tag_idx++;
    }

    // flush last letter
    if (count($letter['tags']) > 0) {
        $letter['TITLE'] = $current_letter;
        $template->append(
            'letters',
            $letter
        );
    }
} else {
    // +-----------------------------------------------------------------------+
    // |                        tag cloud construction                         |
    // +-----------------------------------------------------------------------+

    // we want only the first most represented tags, so we sort them by counter
    // and take the first tags
    usort($tags, fn (mixed $a, mixed $b): int => tags_counter_compare(is_array($a) ? $a : [], is_array($b) ? $b : []));
    $tags = array_slice($tags, 0, \Piwigo\Core\Config::fullTagCloudItemsNumber());

    // depending on its counter and the other tags counter, each tag has a level
    $tags = add_level_to_tags($tags);

    // we want tags diplayed in alphabetic order
    usort($tags, fn (mixed $a, mixed $b): int => tag_alpha_compare(is_array($a) ? $a : [], is_array($b) ? $b : []));

    // display sorted tags
    foreach ($tags as $tag) {
        $tagArr = is_array($tag) ? $tag : [];
        $template->append(
            'tags',
            array_merge(
                $tagArr,
                array(
          'URL' => make_index_url(
              array(
              'tags' => array($tag),
              )
          ),
          )
            )
        );
    }
}
// include menubar
$themeconf = $template->get_template_vars('themeconf');
if (!isset($themeconf['hide_menu_on']) or !in_array('theTagsPage', $themeconf['hide_menu_on'])) {
    include(PHPWG_ROOT_PATH.'include/menubar.inc.php');
}

include(PHPWG_ROOT_PATH.'include/page_header.php');
trigger_notify('loc_end_tags');
flush_page_messages();
$template->pparse('tags');
include(PHPWG_ROOT_PATH.'include/page_tail.php');
