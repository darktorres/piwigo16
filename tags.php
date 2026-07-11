<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

use Piwigo\Core\AccessLevel;
use Piwigo\Template\Template;

// +-----------------------------------------------------------------------+
// |                           initialization                              |
// +-----------------------------------------------------------------------+

define('PHPWG_ROOT_PATH', './');
include_once PHPWG_ROOT_PATH . 'include/common.inc.php';

// Bootstrap globals, set by include/common.inc.php.
/**
 * @var array<string, mixed> $conf
 * @var array<string, mixed> $page
 * @var Template $template
 */
global $conf, $page, $template;

check_status(AccessLevel::Guest);

trigger_notify('loc_begin_tags');

// +-----------------------------------------------------------------------+
// |                       page header and options                         |
// +-----------------------------------------------------------------------+

$title = l10n('Tags');
$page['body_id'] = 'theTagsPage';

$template->set_filenames([
    'tags' => 'tags.tpl',
]);

$page['display_mode'] = $conf['tags_default_display_mode'];
if (isset($_GET['display_mode'])) {
    if (in_array($_GET['display_mode'], ['cloud', 'letters'])) {
        $page['display_mode'] = $_GET['display_mode'];
    }
}

foreach (['cloud', 'letters'] as $mode) {
    $template->assign(
        'U_' . strtoupper($mode),
        get_root_url() . 'tags.php' . ($conf['tags_default_display_mode'] == $mode ? '' : '?display_mode=' . $mode)
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
    usort($tags, tag_alpha_compare(...));

    $tag_letters_column_number = is_numeric($conf['tag_letters_column_number']) ? (int) $conf['tag_letters_column_number'] : 4;

    $current_letter = null;
    $nb_tags = count($tags);
    $current_column = 1;
    $current_tag_idx = 0;

    $letter = [
        'tags' => [],
    ];

    foreach ($tags as $tag) {
        $tag_name = $tag['name'];
        if (! is_string($tag_name)) {
            $tag_name = is_string($tag['name_raw'] ?? null) ? $tag['name_raw'] : '';
        }
        $tag_letter = mb_strtoupper(mb_substr(pwg_transliterate($tag_name), 0, 1, PWG_CHARSET), PWG_CHARSET);

        if ($current_tag_idx == 0) {
            $current_letter = $tag_letter;
            $letter['TITLE'] = $tag_letter;
        }

        // lettre precedente differente de la lettre suivante
        if ($tag_letter !== $current_letter) {
            if ($current_column < $tag_letters_column_number
                and $current_tag_idx > $current_column * $nb_tags / $tag_letters_column_number) {
                $letter['CHANGE_COLUMN'] = true;
                $current_column++;
            }

            $letter['TITLE'] = $current_letter;

            $template->append(
                'letters',
                $letter
            );

            $current_letter = $tag_letter;
            $letter = [
                'tags' => [],
            ];
        }

        $letter['tags'][] = array_merge(
            $tag,
            [
                'URL' => make_index_url([
                    'tags' => [$tag],
                ]),
            ]
        );

        $current_tag_idx++;
    }

    // flush last letter
    if (count($letter['tags']) > 0) {
        unset($letter['CHANGE_COLUMN']);
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
    usort($tags, tags_counter_compare(...));
    $full_tag_cloud_items_number = is_numeric($conf['full_tag_cloud_items_number']) ? (int) $conf['full_tag_cloud_items_number'] : 200;
    $tags = array_slice($tags, 0, $full_tag_cloud_items_number);

    // depending on its counter and the other tags counter, each tag has a level
    $tags = add_level_to_tags($tags);

    // we want tags diplayed in alphabetic order
    usort($tags, tag_alpha_compare(...));

    // display sorted tags
    foreach ($tags as $tag) {
        $template->append(
            'tags',
            array_merge(
                $tag,
                [
                    'URL' => make_index_url(
                        [
                            'tags' => [$tag],
                        ]
                    ),
                ]
            )
        );
    }
}
// include menubar
$themeconf = $template->get_template_vars('themeconf');
$themeconf = is_array($themeconf) ? $themeconf : [];
if (! isset($themeconf['hide_menu_on']) or ! is_array($themeconf['hide_menu_on']) or ! in_array('theTagsPage', $themeconf['hide_menu_on'])) {
    include PHPWG_ROOT_PATH . 'include/menubar.inc.php';
}

include PHPWG_ROOT_PATH . 'include/page_header.php';
trigger_notify('loc_end_tags');
flush_page_messages();
$template->pparse('tags');
include PHPWG_ROOT_PATH . 'include/page_tail.php';
