<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin;

use Piwigo\Core\ValidationPattern;
use Piwigo\Db\DbConnection;
use Piwigo\Db\Tables;
use Piwigo\Permalink\PermalinkRepository;
use Piwigo\Permalink\PermalinkService;
use Piwigo\Template\Template;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Replaces admin/permalinks.php (page slug "permalinks"), folded directly
 * into this controller -- same shape as every prior P23 batch 6 sub-batch's
 * shell folding. Its per-category writes already go through
 * Piwigo\Permalink\PermalinkService::deleteCatPermalink()/setCatPermalink(),
 * inlined directly at their one real call site below -- the free-function
 * bridge they used to go through (admin/include/functions_permalinks.php)
 * is deleted in this same commit: 2 of its 4 functions
 * (get_cat_id_from_permalink()/get_cat_id_from_old_permalink()) had zero
 * callers anywhere in the repo, confirmed via a direct grep, and the other
 * 2 (delete_cat_permalink()/set_cat_permalink()) only this file ever
 * called. No CSRF gap in this sub-batch -- both real mutation branches
 * (`set_permalink`, `delete_permanent`) already call `check_pwg_token()`
 * first, kept unchanged.
 *
 * `global $my_base_url;` before the `admin/include/albums_tab.inc.php`
 * include below is a real bug fix, not a mechanical carry-over: that file
 * sets `$my_base_url` via a bare assignment, and without a preceding
 * `global` declaration in this method's own call frame that assignment
 * would stay local to this method, invisible to add_core_tabs()'s own
 * `global $my_base_url;` read for the 'albums' tabsheet case (triggered
 * synchronously inside albums_tab.inc.php's own `$tabsheet->select()`
 * call). Verified live that this exact bug already existed, unfixed, in
 * the other 2 real callers of albums_tab.inc.php --
 * Piwigo\Admin\CatListPageRenderer and Piwigo\Admin\AlbumsPageRenderer
 * (both P23 batch 6f) -- neither declared `global $my_base_url;` before
 * their own `include`, so `?page=cat_list`/`?page=albums`'s own
 * "List"/"Permalinks" tab hrefs were rendering as bare `href="albums"` /
 * `href="permalinks"` instead of `admin.php?page=albums` /
 * `admin.php?page=permalinks` -- fixed in both of those files in this same
 * commit (P23 batch 6j-1) so the pattern isn't introduced a 3rd time here.
 */
final class PermalinksSubController implements AdminSubControllerInterface
{
    #[\Override]
    public function handle(ServerRequestInterface $request): void
    {
        /**
         * @var array<string, mixed> $page
         * @var Template $template
         */
        global $page, $template;
        global $my_base_url;

        check_input_parameter('cat_id', $_POST, false, ValidationPattern::ID);

        $selected_cat = [];
        // check_input_parameter() above only validates the format when 'cat_id' is
        // present; narrow to a real int here rather than risk building an invalid
        // query from a missing/non-numeric value.
        $post_cat_id = isset($_POST['cat_id']) && is_numeric($_POST['cat_id']) ? (int) $_POST['cat_id'] : 0;
        if (isset($_POST['set_permalink']) and $post_cat_id > 0) {
            check_pwg_token();
            $permalink = $_POST['permalink'] ?? null;
            $permalink = is_string($permalink) ? $permalink : '';
            $permalink_service = new PermalinkService(new PermalinkRepository(DbConnection::build()));
            if (empty($permalink)) {
                $permalink_service->deleteCatPermalink($post_cat_id, isset($_POST['save']));
            } else {
                $permalink_service->setCatPermalink($post_cat_id, $permalink, isset($_POST['save']));
            }
            $selected_cat = [$post_cat_id];
        } elseif (isset($_GET['delete_permanent'])) {
            check_pwg_token();
            $delete_permanent = is_string($_GET['delete_permanent']) ? $_GET['delete_permanent'] : '';
            new PermalinkService(new PermalinkRepository(DbConnection::build()))
                ->deleteOldPermalinkByValue($delete_permanent);
        }

        $template->set_filename('permalinks', 'permalinks.tpl');

        // +-----------------------------------------------------------------------+
        // | tabs                                                                  |
        // +-----------------------------------------------------------------------+

        $page['tab'] = 'permalinks';
        include PHPWG_ROOT_PATH . 'admin/include/albums_tab.inc.php';

        $query = '
SELECT
  id, permalink,
  CONCAT(id, " - ", name, IF(permalink IS NULL, "", " &radic;") ) AS name,
  uppercats, global_rank
FROM ' . Tables::categories();

        display_select_cat_wrapper($query, $selected_cat, 'categories', false);

        $pwg_token = get_pwg_token();

        // --- generate display of active permalinks -----------------------------------
        $sort_by = $this->parseSortVariables(
            ['id', 'name', 'permalink'],
            'name',
            'psf',
            ['delete_permanent'],
            'SORT_'
        );

        $query = '
SELECT id, permalink, uppercats, global_rank
  FROM ' . Tables::categories() . '
  WHERE permalink IS NOT NULL
';
        if ($sort_by[0] == 'id' or $sort_by[0] == 'permalink') {
            $query .= ' ORDER BY ' . $sort_by[0];
        }
        $categories = [];
        $result = pwg_query($query);
        while ((bool) ($row = pwg_db_fetch_assoc($result))) {
            // uppercats is NOT NULL in the schema; is_string() is a defensive
            // narrowing of the driver's generic string|null column type, not a
            // documented nullability.
            $uppercats = is_string($row['uppercats']) ? $row['uppercats'] : '';
            $row['name'] = get_cat_display_name_cache($uppercats);
            $categories[] = $row;
        }

        if ($sort_by[0] == 'name') {
            usort($categories, global_rank_compare(...));
        }
        $template->assign('permalinks', $categories);

        // --- generate display of old permalinks --------------------------------------

        $sort_by = $this->parseSortVariables(
            ['cat_id', 'permalink', 'date_deleted', 'last_hit', 'hit'],
            null,
            'dpsf',
            ['delete_permanent'],
            'SORT_OLD_',
            '#old_permalinks'
        );

        $url_del_base = get_root_url() . 'admin.php?page=permalinks';
        $query = 'SELECT * FROM ' . Tables::oldPermalinks();
        if ((bool) count($sort_by)) {
            $query .= ' ORDER BY ' . $sort_by[0];
        }
        $result = pwg_query($query);
        $deleted_permalinks = [];
        while ((bool) ($row = pwg_db_fetch_assoc($result))) {
            // cat_id is NOT NULL in the schema; is_string() is a defensive
            // narrowing of the driver's generic string|null column type, not a
            // documented nullability.
            $cat_id_str = is_string($row['cat_id']) ? $row['cat_id'] : '';
            $row['name'] = get_cat_display_name_cache($cat_id_str);
            $row['U_DELETE'] =
                add_url_params(
                    $url_del_base,
                    [
                        'delete_permanent' => $row['permalink'],
                        'pwg_token' => $pwg_token,
                    ]
                );
            $deleted_permalinks[] = $row;
        }

        $template->assign([
            'PWG_TOKEN' => $pwg_token,
            'U_HELP' => get_root_url() . 'admin/popuphelp.php?page=permalinks',
            'deleted_permalinks' => $deleted_permalinks,
            'ADMIN_PAGE_TITLE' => l10n('Albums'),
        ]);

        $template->assign_var_from_handle('ADMIN_CONTENT', 'permalinks');
    }

    /**
     * @param array<int, string> $sortable_by
     * @param array<int, string> $get_rejects
     * @return array<int, string>
     */
    private function parseSortVariables(
        array $sortable_by,
        ?string $default_field,
        string $get_param,
        array $get_rejects,
        string $template_var,
        string $anchor = ''
    ): array {
        /** @var Template $template */
        global $template;

        $request_uri = $_SERVER['REQUEST_URI'] ?? '';
        $request_uri = is_string($request_uri) ? $request_uri : '';
        $url_components = parse_url($request_uri);
        // REQUEST_URI is always a well-formed URI for a real HTTP request
        assert($url_components !== false);

        $base_url = $url_components['path'] ?? '';

        parse_str($url_components['query'] ?? '', $vars);
        $is_first = true;
        foreach ($vars as $key => $value) {
            if (! in_array($key, $get_rejects) and $key != $get_param) {
                $base_url .= $is_first ? '?' : '&amp;';
                $is_first = false;

                if (! in_array($key, ['page', 'psf', 'dpsf', 'pwg_token'])) {
                    fatal_error('unexpected URL get key');
                }

                $base_url .= urlencode((string) $key) . '=' . urlencode(is_array($value) ? '' : $value);
            }
        }

        $ret = [];
        foreach ($sortable_by as $field) {
            $url = $base_url;
            $disp = '↓'; // TODO: an small image is better

            if ($field !== @$_GET[$get_param]) {
                if ($default_field != $field) { // the first should be the default
                    $url = add_url_params($url, [
                        $get_param => $field,
                    ]);
                } elseif (! isset($_GET[$get_param])) {
                    $ret[] = $field;
                    $disp = '<em>' . $disp . '</em>';
                }
            } else {
                $ret[] = $field;
                $disp = '<em>' . $disp . '</em>';
            }
            $template->assign(
                $template_var . strtoupper($field),
                '<a href="' . $url . $anchor . '" title="' . l10n('Sort order') . '">' . $disp . '</a>'
            );
        }
        return $ret;
    }
}
