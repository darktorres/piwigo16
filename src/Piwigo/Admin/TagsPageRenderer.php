<?php

declare(strict_types=1);

namespace Piwigo\Admin;

use Piwigo\Core\AccessLevel;
use Piwigo\Db\Tables;
use Piwigo\Template\Template;

/**
 * Ported from admin/tags.php (page slug "tags").
 */
final class TagsPageRenderer
{
    public function render(): void
    {
        /** @var Template $template */
        global $template;

        check_status(AccessLevel::Administrator);

        $tabsheet = new tabsheet();
        $tabsheet->set_id('tags');
        $tabsheet->select('');
        $tabsheet->assign();

        if (($_GET['action'] ?? null) === 'delete_orphans') {
            check_pwg_token();

            delete_orphan_tags();
            $_SESSION['message_tags'] = l10n('Orphan tags deleted');
            redirect(get_root_url() . 'admin.php?page=tags');
        }

        $template->set_filenames([
            'tags' => 'tags.tpl',
        ]);

        $template->assign(
            [
                'F_ACTION' => PHPWG_ROOT_PATH . 'admin.php?page=tags',
                'PWG_TOKEN' => get_pwg_token(),
            ]
        );

        $warning_tags = '';

        $orphan_tags = get_orphan_tags();

        $orphan_tag_names_array = '[]';
        $orphan_tag_names = [];
        foreach ($orphan_tags as $tag) {
            $orphan_tag_names[] = trigger_change('render_tag_name', $tag['name'], $tag);
        }
        $orphan_tag_names = array_filter($orphan_tag_names, is_string(...));

        if (count($orphan_tag_names) > 0) {
            $warning_tags = sprintf(
                l10n('You have %d orphan tags %s'),
                count($orphan_tag_names),
                '<a
      class="icon-eye"
      data-url="' . get_root_url() . 'admin.php?page=tags&amp;action=delete_orphans&amp;pwg_token=' . get_pwg_token() . '">'
                . l10n('Review') . '</a>'
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

        $per_page = 100;

        // tag counters
        $query = '
SELECT tag_id, COUNT(image_id) AS counter
  FROM ' . Tables::imageTag() . '
  GROUP BY tag_id';
        $tag_counters = simple_hash_from_query($query, 'tag_id', 'counter');

        // all tags
        $query = '
SELECT name, id, url_name
  FROM ' . Tables::tags() . '
;';
        $result = pwg_query($query);
        $all_tags = [];
        while ((bool) ($tag = pwg_db_fetch_assoc($result))) {
            $raw_name = $tag['name'];
            $tag['raw_name'] = $raw_name;
            $rendered_name = trigger_change('render_tag_name', $raw_name, $tag);
            $rendered_name = is_string($rendered_name) ? $rendered_name : ($raw_name ?? '');
            $tag['name'] = $rendered_name;

            $tag_id = $tag['id'];
            $counter = 0;
            if (is_string($tag_id) && isset($tag_counters[$tag_id])) {
                $tag_counter_value = $tag_counters[$tag_id];
                if (is_numeric($tag_counter_value)) {
                    $counter = intval($tag_counter_value);
                }
            }
            if ($counter > 0) {
                $tag['counter'] = $counter;
            }

            $alt_names = trigger_change('get_tag_alt_names', [], $raw_name);
            $alt_names = is_array($alt_names) ? array_filter($alt_names, is_string(...)) : [];
            $alt_names = array_diff(array_unique($alt_names), [$rendered_name]);
            if (count($alt_names) > 0) {
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

        $template->assign_var_from_handle('ADMIN_CONTENT', 'tags');
    }
}
