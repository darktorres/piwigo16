<?php

declare(strict_types=1);

namespace Piwigo\Admin;

use Piwigo\Category\CategoryRepository;
use Piwigo\Core\AccessLevel;
use Piwigo\Core\Lang;
use Piwigo\Core\RedirectServiceInterface;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Db\DbConnection;
use Piwigo\Db\Tables;
use Piwigo\Group\GroupRepository;
use Piwigo\Html\HtmlService;
use Piwigo\Permission\PermissionRepository;
use Piwigo\Permission\PermissionService;
use Piwigo\Tag\TagRepository;
use Piwigo\Tag\TagService;

/**
 * Ported from admin/tags.php (page slug "tags").
 */
final class TagsPageRenderer
{
    public function __construct(
        private readonly RedirectServiceInterface $redirectService,
        private readonly UrlServiceInterface $urlService,
    ) {}

    public function render(): void
    {
        $template = \Piwigo\Template\CurrentTemplate::get();

        \Piwigo\Auth\AccessControl::checkStatus(AccessLevel::Administrator);

        $tabsheet = new tabsheet();
        $tabsheet->set_id('tags');
        $tabsheet->select('');
        $tabsheet->assign();

        $tagConn = DbConnection::build();
        $tagService = new TagService(new TagRepository($tagConn), new PermissionService(new PermissionRepository($tagConn), new GroupRepository($tagConn), new CategoryRepository($tagConn)), new \Piwigo\Activity\ActivityService(new \Piwigo\Activity\ActivityRepository($tagConn)));

        if (($_GET['action'] ?? null) === 'delete_orphans') {
            new \Piwigo\Csrf\CsrfService()
                ->checkOrFail(new HtmlService(), $this->redirectService);

            $tagService->deleteOrphanTags();
            $_SESSION['message_tags'] = Lang::t('Orphan tags deleted');
            $this->redirectService->redirect($this->urlService->getRootUrl() . 'admin.php?page=tags');
        }

        $template->set_filenames([
            'tags' => 'tags.tpl',
        ]);

        $template->assign(
            [
                'F_ACTION' => PHPWG_ROOT_PATH . 'admin.php?page=tags',
                'PWG_TOKEN' => new \Piwigo\Csrf\CsrfService()
                    ->getToken(),
            ]
        );

        $warning_tags = '';

        $orphan_tags = $tagService->getOrphanTags();

        $orphan_tag_names_array = '[]';
        $orphan_tag_names = [];
        foreach ($orphan_tags as $tag) {
            $orphan_tag_names[] = \Piwigo\PluginConfig\EventDispatcher::get()->triggerChange('render_tag_name', $tag['name'], $tag);
        }
        $orphan_tag_names = array_filter($orphan_tag_names, is_string(...));

        if (count($orphan_tag_names) > 0) {
            $warning_tags = sprintf(
                Lang::t('You have %d orphan tags %s'),
                count($orphan_tag_names),
                '<a
      class="icon-eye"
      data-url="' . $this->urlService->getRootUrl() . 'admin.php?page=tags&amp;action=delete_orphans&amp;pwg_token=' . new \Piwigo\Csrf\CsrfService()->getToken() . '">'
                . Lang::t('Review') . '</a>'
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
        $tag_counters = array_column($tagConn->fetchAllAssociative($query), 'counter', 'tag_id');

        // all tags
        $query = '
SELECT name, id, url_name
  FROM ' . Tables::tags() . '
;';
        $all_tags = [];
        foreach ($tagConn->fetchAllAssociative($query) as $tag) {
            $raw_name = $tag['name'];
            $tag['raw_name'] = $raw_name;
            $raw_name_str = is_scalar($raw_name) ? (string) $raw_name : '';
            $rendered_name = \Piwigo\PluginConfig\EventDispatcher::get()->triggerChange('render_tag_name', $raw_name, $tag);
            $rendered_name = is_string($rendered_name) ? $rendered_name : $raw_name_str;
            $tag['name'] = $rendered_name;

            $tag_id = $tag['id'];
            $counter = 0;
            if ((is_int($tag_id) || is_string($tag_id)) && isset($tag_counters[$tag_id])) {
                $tag_counter_value = $tag_counters[$tag_id];
                if (is_numeric($tag_counter_value)) {
                    $counter = intval($tag_counter_value);
                }
            }
            if ($counter > 0) {
                $tag['counter'] = $counter;
            }

            $alt_names = \Piwigo\PluginConfig\EventDispatcher::get()->triggerChange('get_tag_alt_names', [], $raw_name);
            $alt_names = is_array($alt_names) ? array_filter($alt_names, is_string(...)) : [];
            $alt_names = array_diff(array_unique($alt_names), [$rendered_name]);
            if (count($alt_names) > 0) {
                $tag['alt_names'] = implode(', ', $alt_names);
            }
            $all_tags[] = $tag;
        }
        usort($all_tags, new HtmlService()->tagAlphaCompare(...));

        $template->assign(
            [
                'first_tags' => array_slice($all_tags, 0, $per_page),
                'data' => $all_tags,
                'total' => count($all_tags),
                'per_page' => $per_page,
                'ADMIN_PAGE_TITLE' => Lang::t('Tags'),
            ]
        );

        $template->assign_var_from_handle('ADMIN_CONTENT', 'tags');
    }
}
