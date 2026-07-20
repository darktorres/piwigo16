<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin;

use Piwigo\Admin\tabsheet;
use Piwigo\Category\CategoryRepository;
use Piwigo\Category\CategoryService;
use Piwigo\Core\RedirectServiceInterface;
use Piwigo\Core\ValidationPattern;
use Piwigo\Db\DbConnection;
use Piwigo\Db\Tables;
use Piwigo\Group\GroupRepository;
use Piwigo\Html\HtmlService;
use Piwigo\Permalink\PermalinkRepository;
use Piwigo\Permalink\PermalinkService;
use Piwigo\Permission\PermissionRepository;
use Piwigo\Permission\PermissionService;
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
 * `global $my_base_url;` before the inline tabsheet block below (formerly
 * the `admin/include/albums_tab.inc.php` include, folded in P23 batch
 * 8b-5) is a real bug fix, not a mechanical carry-over: that block sets
 * `$my_base_url` via a bare assignment, and without a preceding
 * `global` declaration in this method's own call frame that assignment
 * would stay local to this method, invisible to CoreTabs::addCoreTabs()'s own
 * `global $my_base_url;` read for the 'albums' tabsheet case (triggered
 * synchronously inside the block's own `$tabsheet->select()` call).
 * Verified live that this exact bug already existed, unfixed, in the
 * other 2 real callers of the same tabsheet block --
 * Piwigo\Admin\CatListPageRenderer and Piwigo\Admin\AlbumsPageRenderer
 * (both P23 batch 6f) -- neither declared `global $my_base_url;` before
 * their own `include`, so `?page=cat_list`/`?page=albums`'s own
 * "List"/"Permalinks" tab hrefs were rendering as bare `href="albums"` /
 * `href="permalinks"` instead of `admin.php?page=albums` /
 * `admin.php?page=permalinks` -- fixed in both of those files in the P23
 * batch 6j-1 commit so the pattern isn't introduced a 3rd time here.
 */
final class PermalinksSubController implements AdminSubControllerInterface
{
    public function __construct(
        private readonly RedirectServiceInterface $redirectService,
    ) {}

    #[\Override]
    public function handle(ServerRequestInterface $request): void
    {
        $template = \Piwigo\Template\CurrentTemplate::get();
        global $my_base_url;

        $htmlRenderer = new HtmlService();
        $conn = DbConnection::build();

        new \Piwigo\Validation\InputValidator()
            ->validate('cat_id', $_POST, false, ValidationPattern::ID);

        $selected_cat = [];
        // check_input_parameter() above only validates the format when 'cat_id' is
        // present; narrow to a real int here rather than risk building an invalid
        // query from a missing/non-numeric value.
        $post_cat_id = isset($_POST['cat_id']) && is_numeric($_POST['cat_id']) ? (int) $_POST['cat_id'] : 0;
        if (isset($_POST['set_permalink']) and $post_cat_id > 0) {
            new \Piwigo\Csrf\CsrfService()
                ->checkOrFail($htmlRenderer, $this->redirectService);
            $permalink = $_POST['permalink'] ?? null;
            $permalink = is_string($permalink) ? $permalink : '';
            $permalink_service = new PermalinkService(new PermalinkRepository($conn));
            if (empty($permalink)) {
                $permalink_service->deleteCatPermalink($post_cat_id, isset($_POST['save']));
            } else {
                $permalink_service->setCatPermalink($post_cat_id, $permalink, isset($_POST['save']));
            }
            $selected_cat = [$post_cat_id];
        } elseif (isset($_GET['delete_permanent'])) {
            new \Piwigo\Csrf\CsrfService()
                ->checkOrFail($htmlRenderer, $this->redirectService);
            $delete_permanent = is_string($_GET['delete_permanent']) ? $_GET['delete_permanent'] : '';
            new PermalinkService(new PermalinkRepository($conn))
                ->deleteOldPermalinkByValue($delete_permanent);
        }

        $template->set_filename('permalinks', 'permalinks.tpl');

        // +-----------------------------------------------------------------------+
        // | tabs                                                                  |
        // +-----------------------------------------------------------------------+

        $my_base_url = get_root_url() . 'admin.php?page=';

        $tabsheet = new tabsheet();
        $tabsheet->set_id('albums');
        $tabsheet->select('permalinks');
        $tabsheet->assign();

        $query = '
SELECT COUNT(*)
  FROM ' . Tables::categories() . '
;';
        $nb_cats = $conn->fetchOne($query);
        $template->assign(
            [
                'nb_cats' => $nb_cats,
            ]
        );

        $query = '
SELECT
  id, permalink,
  CONCAT(id, " - ", name, IF(permalink IS NULL, "", " &radic;") ) AS name,
  uppercats, global_rank
FROM ' . Tables::categories();

        new CategoryService(
            new CategoryRepository($conn),
            new PermissionService(new PermissionRepository($conn), new GroupRepository($conn), new CategoryRepository($conn))
        )->displaySelectCatWrapper($query, $selected_cat, 'categories', $htmlRenderer, $template, false);

        $pwg_token = new \Piwigo\Csrf\CsrfService()
            ->getToken();

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
        foreach ($conn->fetchAllAssociative($query) as $row) {
            // uppercats is NOT NULL in the schema; is_string() is a defensive
            // narrowing of the driver's generic string|null column type, not a
            // documented nullability.
            $uppercats = is_string($row['uppercats']) ? $row['uppercats'] : '';
            $row['name'] = $htmlRenderer->getCatDisplayNameCache($uppercats);
            $categories[] = $row;
        }

        if ($sort_by[0] == 'name') {
            usort($categories, CategoryService::compareByGlobalRank(...));
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
        $deleted_permalinks = [];
        foreach ($conn->fetchAllAssociative($query) as $row) {
            // cat_id is NOT NULL in the schema; DBAL's native int/float
            // casting means this can now arrive as a native int rather
            // than always a string, so accept both instead of only
            // is_string().
            $cat_id_raw = $row['cat_id'];
            $cat_id_str = (is_int($cat_id_raw) || is_string($cat_id_raw)) ? (string) $cat_id_raw : '';
            $row['name'] = $htmlRenderer->getCatDisplayNameCache($cat_id_str);
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
        $template = \Piwigo\Template\CurrentTemplate::get();

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
                    new HtmlService()
                        ->fatalError('unexpected URL get key');
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
