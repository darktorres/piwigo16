<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin;

use Piwigo\Admin\CoreTabs;
use Piwigo\Admin\CoreTabsContext;
use Piwigo\Admin\Tabsheet;
use Piwigo\Category\CategoryService;
use Piwigo\Core\Lang;
use Piwigo\Core\RedirectServiceInterface;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Db\DbConnection;
use Piwigo\Permalink\PermalinkRepository;
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
        private readonly Lang $lang,
        private readonly RedirectServiceInterface $redirectService,
        private readonly UrlServiceInterface $urlService,
        private readonly CoreTabs $coreTabs,
        private readonly \Piwigo\Template\CurrentTemplate $currentTemplate,
        private readonly \Piwigo\Permalink\PermalinkService $permalinkService,
        private readonly \Piwigo\Category\CategoryService $categoryService,
        private readonly \Piwigo\Core\HtmlRenderingInterface $htmlRenderer,
    ) {}

    #[\Override]
    public function handle(ServerRequestInterface $request): void
    {
        $template = $this->currentTemplate->get();

        $htmlRenderer = $this->htmlRenderer;
        $conn = DbConnection::build();

        $permalinksRequest = Request\PermalinksRequest::fromGlobals();

        $selected_cat = [];
        $post_cat_id = $permalinksRequest->catId;
        if ($permalinksRequest->isSetPermalink and $post_cat_id > 0) {
            new \Piwigo\Csrf\CsrfService()
                ->checkOrFail($htmlRenderer, $this->redirectService);
            $permalink = $permalinksRequest->permalink;
            $permalink_service = $this->permalinkService;
            if ($permalink === '') {
                $permalink_service->deleteCatPermalink($post_cat_id, $permalinksRequest->isSave);
            } else {
                $permalink_service->setCatPermalink($post_cat_id, $permalink, $permalinksRequest->isSave);
            }
            $selected_cat = [$post_cat_id];
        } elseif ($permalinksRequest->deletePermanentPresent) {
            new \Piwigo\Csrf\CsrfService()
                ->checkOrFail($htmlRenderer, $this->redirectService);
            $this->permalinkService
                ->deleteOldPermalinkByValue($permalinksRequest->deletePermanent);
        }

        $template->set_filename('permalinks', 'permalinks.tpl');

        // +-----------------------------------------------------------------------+
        // | tabs                                                                  |
        // +-----------------------------------------------------------------------+

        $this->coreTabs->setContext(new CoreTabsContext(myBaseUrl: $this->urlService->getRootUrl() . 'admin.php?page='));

        $tabsheet = new Tabsheet();
        $tabsheet->set_id('albums');
        $tabsheet->select('permalinks');
        $tabsheet->assign($this->currentTemplate);

        $nb_cats = $this->categoryService->countAllCategories();
        $template->assign(
            [
                'nb_cats' => $nb_cats,
            ]
        );

        $this->categoryService->displaySelectForPermalinks($selected_cat, 'categories', $htmlRenderer, $template);

        $pwg_token = new \Piwigo\Csrf\CsrfService()
            ->getToken();

        // --- generate display of active permalinks -----------------------------------
        $sort_by = $this->parseSortVariables(
            ['id', 'name', 'permalink'],
            'name',
            'psf',
            ['delete_permanent'],
            'SORT_',
            $permalinksRequest->psfPresent,
            $permalinksRequest->psf
        );

        $order_by_sql = ($sort_by[0] === 'id' or $sort_by[0] === 'permalink') ? ' ORDER BY ' . $sort_by[0] : '';
        $categories = [];
        foreach ($this->categoryService->getActivePermalinksList($order_by_sql) as $row) {
            // uppercats is NOT NULL in the schema; is_string() is a defensive
            // narrowing of the driver's generic string|null column type, not a
            // documented nullability.
            $uppercats = is_string($row['uppercats']) ? $row['uppercats'] : '';
            $row['name'] = $htmlRenderer->getCatDisplayNameCache($uppercats);
            $categories[] = $row;
        }

        if ($sort_by[0] === 'name') {
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
            $permalinksRequest->dpsfPresent,
            $permalinksRequest->dpsf,
            '#old_permalinks'
        );

        $url_del_base = $this->urlService->getRootUrl() . 'admin.php?page=permalinks';
        $orderByColumn = count($sort_by) > 0 ? $sort_by[0] : null;
        $deleted_permalinks = [];
        foreach (new PermalinkRepository(\Piwigo\Db\EntityManagerFactory::build($conn))->findAllOrderedBy($orderByColumn) as $permalinkRow) {
            $row = $permalinkRow->toArray();
            $row['name'] = $htmlRenderer->getCatDisplayNameCache((string) $permalinkRow->catId);
            $row['U_DELETE'] =
                $this->urlService->addUrlParams(
                    $url_del_base,
                    [
                        'delete_permanent' => $permalinkRow->permalink,
                        'pwg_token' => $pwg_token,
                    ]
                );
            $deleted_permalinks[] = $row;
        }

        $template->assign([
            'PWG_TOKEN' => $pwg_token,
            'U_HELP' => $this->urlService->getRootUrl() . 'admin/popuphelp.php?page=permalinks',
            'deleted_permalinks' => $deleted_permalinks,
            'ADMIN_PAGE_TITLE' => $this->lang->t('Albums'),
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
        bool $sort_param_present,
        ?string $sort_param_value,
        string $anchor = ''
    ): array {
        $template = $this->currentTemplate->get();

        $request_uri = $_SERVER['REQUEST_URI'] ?? '';
        $request_uri = is_string($request_uri) ? $request_uri : '';
        $url_components = parse_url($request_uri);
        // REQUEST_URI is always a well-formed URI for a real HTTP request
        assert($url_components !== false);

        $base_url = $url_components['path'] ?? '';

        parse_str($url_components['query'] ?? '', $vars);
        $is_first = true;
        foreach ($vars as $key => $value) {
            if (! in_array((string) $key, $get_rejects, true) and $key !== $get_param) {
                $base_url .= $is_first ? '?' : '&amp;';
                $is_first = false;

                if (! in_array((string) $key, ['page', 'psf', 'dpsf', 'pwg_token'], true)) {
                    $this->htmlRenderer
                        ->fatalError('unexpected URL get key');
                }

                $base_url .= urlencode((string) $key) . '=' . urlencode(is_array($value) ? '' : $value);
            }
        }

        $ret = [];
        foreach ($sortable_by as $field) {
            $url = $base_url;
            $disp = '↓';

            if ($field !== $sort_param_value) {
                if (($default_field ?? '') !== $field) { // the first should be the default
                    $url = $this->urlService->addUrlParams($url, [
                        $get_param => $field,
                    ]);
                } elseif (! $sort_param_present) {
                    $ret[] = $field;
                    $disp = '<em>' . $disp . '</em>';
                }
            } else {
                $ret[] = $field;
                $disp = '<em>' . $disp . '</em>';
            }
            $template->assign(
                $template_var . strtoupper($field),
                '<a href="' . $url . $anchor . '" title="' . $this->lang->t('Sort order') . '">' . $disp . '</a>'
            );
        }
        return $ret;
    }
}
