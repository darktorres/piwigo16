<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin;

use Override;
use Piwigo\Admin\CoreTabs;
use Piwigo\Admin\CoreTabsContext;
use Piwigo\Admin\Tabsheet;
use Piwigo\Category\CategoryService;
use Piwigo\Config\CurrentConfig;
use Piwigo\Controller\Admin\Request\PermalinksRequest;
use Piwigo\Core\HtmlRenderingInterface;
use Piwigo\Core\Lang;
use Piwigo\Core\RedirectServiceInterface;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Csrf\CsrfService;
use Piwigo\Db\DbConnection;
use Piwigo\Db\EntityManagerFactory;
use Piwigo\Permalink\OldPermalinkSortField;
use Piwigo\Permalink\PermalinkRepository;
use Piwigo\Permalink\PermalinkService;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Template\CurrentTemplate;
use Piwigo\Validation\InputValidator;
use Psr\Http\Message\ServerRequestInterface;

final class PermalinksSubController implements AdminSubControllerInterface
{
    public function __construct(
        private readonly Lang $lang,
        private readonly RedirectServiceInterface $redirectService,
        private readonly UrlServiceInterface $urlService,
        private readonly CoreTabs $coreTabs,
        private readonly CurrentTemplate $currentTemplate,
        private readonly PermalinkService $permalinkService,
        private readonly CategoryService $categoryService,
        private readonly HtmlRenderingInterface $htmlRenderer,
        private readonly InputValidator $inputValidator,
        private readonly EventDispatcher $eventDispatcher,
        private readonly CurrentConfig $currentConfig,
    ) {}

    #[Override]
    public function handle(ServerRequestInterface $request): void
    {
        $template = $this->currentTemplate->get();

        $htmlRenderer = $this->htmlRenderer;
        $conn = DbConnection::build();

        $permalinksRequest = PermalinksRequest::fromGlobals($this->inputValidator);

        $selected_cat = [];
        $post_cat_id = $permalinksRequest->catId;
        if ($permalinksRequest->isSetPermalink and $post_cat_id > 0) {
            new CsrfService($this->currentConfig)
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
            new CsrfService($this->currentConfig)
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
        $tabsheet->select('permalinks', $this->eventDispatcher);
        $tabsheet->assign($this->currentTemplate);

        $nb_cats = $this->categoryService->countAllCategories();
        $template->assign(
            [
                'nb_cats' => $nb_cats,
            ]
        );

        $this->categoryService->displaySelectForPermalinks($selected_cat, 'categories', $htmlRenderer, $template);

        $pwg_token = new CsrfService($this->currentConfig)
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

        $order_by_column = ($sort_by[0] === 'id' or $sort_by[0] === 'permalink') ? $sort_by[0] : null;
        $categories = [];
        foreach ($this->categoryService->getActivePermalinksList($order_by_column) as $row) {
            $row['name'] = $htmlRenderer->getCatDisplayNameCache($row['uppercats']);
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
        $sortField = count($sort_by) > 0 ? OldPermalinkSortField::fromToken($sort_by[0]) : null;
        $deleted_permalinks = [];
        foreach (new PermalinkRepository(EntityManagerFactory::build($conn))->findAllOrderedBy($sortField) as $permalinkRow) {
            $row = $permalinkRow->toArray();
            $row['name'] = $htmlRenderer->getCatDisplayNameCache((string) $permalinkRow->catId);
            $row['U_DELETE'] =
                $this->urlService->addUrlParams(
                    $url_del_base,
                    [
                        'delete_permanent' => $permalinkRow->permalink->value,
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
