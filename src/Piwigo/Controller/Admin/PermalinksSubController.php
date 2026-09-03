<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin;

use Doctrine\ORM\EntityManagerInterface;
use Override;
use Piwigo\Admin\CoreTabs;
use Piwigo\Admin\CoreTabsContext;
use Piwigo\Admin\Tabsheet;
use Piwigo\Category\CategoryService;
use Piwigo\Controller\Admin\Projection\AdminPageResult;
use Piwigo\Controller\Admin\Projection\DeletedPermalinkRow;
use Piwigo\Controller\Admin\Projection\PermalinkListRow;
use Piwigo\Controller\Admin\Projection\PermalinksView;
use Piwigo\Controller\Admin\Request\PermalinksRequest;
use Piwigo\Core\HtmlRenderingInterface;
use Piwigo\Core\Lang;
use Piwigo\Core\RedirectServiceInterface;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Csrf\CsrfService;
use Piwigo\Permalink\OldPermalinkSortField;
use Piwigo\Permalink\PermalinkRepository;
use Piwigo\Permalink\PermalinkService;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Template\CurrentTemplate;
use Piwigo\Template\Renderer;
use Piwigo\Validation\InputValidator;
use Psr\Http\Message\ServerRequestInterface;

final readonly class PermalinksSubController implements AdminSubControllerInterface
{
    public function __construct(
        private Lang $lang,
        private RedirectServiceInterface $redirectService,
        private UrlServiceInterface $urlService,
        private CoreTabs $coreTabs,
        private CurrentTemplate $currentTemplate,
        private PermalinkService $permalinkService,
        private CategoryService $categoryService,
        private HtmlRenderingInterface $htmlRenderer,
        private InputValidator $inputValidator,
        private EventDispatcher $eventDispatcher,
        private EntityManagerInterface $entityManager,
        private CsrfService $csrfService,
        private Renderer $renderer,
    ) {}

    #[Override]
    public function handle(ServerRequestInterface $request): AdminPageResult
    {
        $htmlRenderer = $this->htmlRenderer;

        $permalinksRequest = PermalinksRequest::fromGlobals($this->inputValidator);

        $selected_cat = [];
        $post_cat_id = $permalinksRequest->catId;
        if ($permalinksRequest->isSetPermalink and $post_cat_id > 0) {
            $this->csrfService
                ->checkOrFail($htmlRenderer, $this->redirectService);
            $permalink = $permalinksRequest->permalink;
            $permalink_service = $this->permalinkService;
            if ($permalink === '') {
                $permalink_service->deleteCatPermalink($post_cat_id, $permalinksRequest->isSave, $this->entityManager);
            } else {
                $permalink_service->setCatPermalink($post_cat_id, $permalink, $permalinksRequest->isSave, $this->entityManager);
            }
            // Normalized to string here rather than in the template: the
            // option keys it compares against are stringified there too.
            $selected_cat = [(string) $post_cat_id];
        } elseif ($permalinksRequest->deletePermanentPresent) {
            $this->csrfService
                ->checkOrFail($htmlRenderer, $this->redirectService);
            $this->permalinkService
                ->deleteOldPermalinkByValue($permalinksRequest->deletePermanent);
        }

        $this->coreTabs->setContext(new CoreTabsContext(myBaseUrl: $this->urlService->getRootUrl() . 'admin.php?page='));

        $tabsheet = new Tabsheet();
        $tabsheet->setId('albums');
        $tabsheet->select('permalinks', $this->eventDispatcher);
        $tabsheet->assign($this->currentTemplate, $this->renderer);

        $nb_cats = $this->categoryService->countAllCategories();

        $categories_options = $this->categoryService->displaySelectForPermalinks($selected_cat, $htmlRenderer);

        $pwg_token = $this->csrfService
            ->getToken();

        $sortResult = $this->parseSortVariables(
            ['id', 'name', 'permalink'],
            'name',
            'psf',
            ['delete_permanent'],
            'SORT_',
            $permalinksRequest->psfPresent,
            $permalinksRequest->psf
        );
        $sort_by = $sortResult['active'];
        $sortHeaders = $sortResult['headers'];

        $order_by_column = ($sort_by[0] === 'id' or $sort_by[0] === 'permalink') ? $sort_by[0] : null;
        $activePermalinks = $this->categoryService->getActivePermalinksList($order_by_column);

        if ($sort_by[0] === 'name') {
            usort($activePermalinks, CategoryService::compareByGlobalRank(...));
        }

        $categories = [];
        foreach ($activePermalinks as $row) {
            $categories[] = [
                ...$row->toArray(),
                'name' => $htmlRenderer->getCatDisplayNameCache($row->uppercats),
            ];
        }

        $categories = array_map(
            static fn (array $row): PermalinkListRow => new PermalinkListRow(
                id: $row['id'],
                name: $row['name'],
                permalink: $row['permalink'],
            ),
            $categories
        );

        $sortResult = $this->parseSortVariables(
            ['cat_id', 'permalink', 'date_deleted', 'last_hit', 'hit'],
            null,
            'dpsf',
            ['delete_permanent'],
            'SORT_OLD_',
            $permalinksRequest->dpsfPresent,
            $permalinksRequest->dpsf,
            '#old_permalinks'
        );
        $sort_by = $sortResult['active'];
        $oldSortHeaders = $sortResult['headers'];

        $url_del_base = $this->urlService->getRootUrl() . 'admin.php?page=permalinks';
        $sortField = count($sort_by) > 0 ? OldPermalinkSortField::fromToken($sort_by[0]) : null;
        $deleted_permalinks = [];
        foreach (new PermalinkRepository($this->entityManager)->findAllOrderedBy($sortField) as $permalinkRow) {
            $deleted_permalinks[] = new DeletedPermalinkRow(
                catId: $permalinkRow->catId->value,
                permalink: $permalinkRow->permalink->value,
                dateDeleted: $permalinkRow->dateDeleted,
                lastHit: $permalinkRow->lastHit,
                hit: $permalinkRow->hit,
                name: $htmlRenderer->getCatDisplayNameCache((string) $permalinkRow->catId),
                uDelete: $this->urlService->addUrlParams(
                    $url_del_base,
                    [
                        'delete_permanent' => $permalinkRow->permalink->value,
                        'pwg_token' => $pwg_token,
                    ]
                ),
            );
        }

        $adminContent = $this->renderer->render(new PermalinksView(
            nbCats: $nb_cats,
            sortId: $sortHeaders['SORT_ID'],
            sortName: $sortHeaders['SORT_NAME'],
            sortPermalink: $sortHeaders['SORT_PERMALINK'],
            permalinks: $categories,
            sortOldCatId: $oldSortHeaders['SORT_OLD_CAT_ID'],
            sortOldPermalink: $oldSortHeaders['SORT_OLD_PERMALINK'],
            sortOldDateDeleted: $oldSortHeaders['SORT_OLD_DATE_DELETED'],
            sortOldLastHit: $oldSortHeaders['SORT_OLD_LAST_HIT'],
            sortOldHit: $oldSortHeaders['SORT_OLD_HIT'],
            csrfToken: $pwg_token,
            deletedPermalinks: $deleted_permalinks,
            categoriesOptions: $categories_options,
        ));

        return new AdminPageResult(
            content: $adminContent,
            pageTitle: $this->lang->t('Albums'),
            helpUrl: $this->urlService->getRootUrl() . 'admin/popuphelp.php?page=permalinks',
        );
    }

    /**
     * @param array<int, string> $sortable_by
     * @param array<int, string> $get_rejects
     * @return array{active: array<int, string>, headers: array<string, string>}
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
                // Plain '&', not '&amp;' -- $base_url/$url below are embedded
                // directly into this method's own hand-built raw-HTML
                // $headers values (no auto-escape ever runs over them), so
                // this needn't be entity-encoded any more than the rest of
                // that hand-built markup is (P59 Batch 1).
                $base_url .= $is_first ? '?' : '&';
                $is_first = false;

                if (! in_array((string) $key, ['page', 'psf', 'dpsf', 'pwg_token'], true)) {
                    $this->htmlRenderer
                        ->fatalError('unexpected URL get key');
                }

                $base_url .= urlencode((string) $key) . '=' . urlencode(is_array($value) ? '' : $value);
            }
        }

        $ret = [];
        $headers = [];
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
            $headers[$template_var . strtoupper($field)] =
                '<a href="' . $url . $anchor . '" title="' . $this->lang->t('Sort order') . '">' . $disp . '</a>';
        }
        return [
            'active' => $ret,
            'headers' => $headers,
        ];
    }
}
