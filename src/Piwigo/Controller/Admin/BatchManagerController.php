<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin;

use Doctrine\DBAL\ParameterType;
use Latte\Runtime\Html;
use Piwigo\Activity\ActivityAction;
use Piwigo\Activity\ActivityEvent;
use Piwigo\Activity\ActivityLogger;
use Piwigo\Activity\ActivityObject;
use Piwigo\Admin\AdminService;
use Piwigo\Admin\BatchManager\FilterResolver;
use Piwigo\Admin\Category\CategoryAdminService;
use Piwigo\Admin\Image\DuplicateField;
use Piwigo\Admin\Image\ImageAdminService;
use Piwigo\Admin\Tabsheet;
use Piwigo\Admin\Tag\TagAdminService;
use Piwigo\Admin\Users\UserAdminService;
use Piwigo\Cache\RequestCache;
use Piwigo\Category\CategoryRepository;
use Piwigo\Category\CategoryService;
use Piwigo\Common\Enum\UserStatus;
use Piwigo\Config\Config;
use Piwigo\Core\DateService;
use Piwigo\Core\Lang;
use Piwigo\Core\PageState;
use Piwigo\Core\StringUtil;
use Piwigo\Core\ValidationPattern;
use Piwigo\Csrf\CsrfService;
use Piwigo\Db\SqlExpr;
use Piwigo\Db\Tables;
use Piwigo\Event\Admin\BatchManagerPerformFilters;
use Piwigo\Event\Admin\BatchManagerRegisterFilters;
use Piwigo\Event\Admin\BatchManagerUrlFilter;
use Piwigo\Event\Admin\ElementSetGlobalAction;
use Piwigo\Event\Admin\PerformBatchManagerPrefilters;
use Piwigo\Event\Location\LocBeginElementSetGlobal;
use Piwigo\Event\Location\LocBeginElementSetUnit;
use Piwigo\Event\Location\LocEndElementSetGlobal;
use Piwigo\Event\Location\LocEndElementSetUnit;
use Piwigo\Html\HtmlService;
use Piwigo\Http\RedirectResponder;
use Piwigo\Image\DerivativeImage;
use Piwigo\Image\DerivativeSize;
use Piwigo\Image\Entity\Image;
use Piwigo\Image\ImageRepository;
use Piwigo\Image\ImageStdParams;
use Piwigo\Image\OrderByService;
use Piwigo\Image\SrcImage;
use Piwigo\Job\MessengerRepository;
use Piwigo\Lang\Translator;
use Piwigo\Page\PaginationService;
use Piwigo\Plugin\PluginRegistry;
use Piwigo\Search\SearchService;
use Piwigo\Session\Session;
use Piwigo\Site\LocalSiteReader;
use Piwigo\Tag\TagRepository;
use Piwigo\Tag\TagService;
use Piwigo\Template\TemplateRegistry;
use Piwigo\Url\UrlGenerator;
use Piwigo\Url\UrlService;
use Piwigo\Users\CurrentUser;
use Piwigo\Users\PermissionService;
use Piwigo\Users\UserCaddieRepository;
use Piwigo\Users\UserFavoriteRepository;
use Piwigo\Users\UserRepository;
use Piwigo\Validation\InputValidator;
use Psr\EventDispatcher\EventDispatcherInterface;

final class BatchManagerController implements AdminSubControllerInterface
{
    /** @var list<string> */
    public const array PAGES = [
        'batch_manager',
        'batch_manager_global',
        'batch_manager_unit',
        'queue',
    ];

    /** @var list<string> */
    private array $catElementsId = [];
    private int $pageStart = 0;
    private string $batchTab = 'global';
    private string $prefilter = 'none';

    public function __construct(
        private readonly AdminService $adminService,
        private readonly CategoryAdminService $categoryAdminService,
        private readonly CategoryRepository $categoryRepository,
        private readonly CategoryService $categoryService,
        private readonly DateService $dateService,
        private readonly FilterResolver $filterResolver,
        private readonly HtmlService $htmlService,
        private readonly ImageAdminService $imageAdminService,
        private readonly ImageRepository $imageRepository,
        private readonly MessengerRepository $messengerRepository,
        private readonly PermissionService $permissionService,
        private readonly PluginRegistry $pluginRegistry,
        private readonly SearchService $searchService,
        private readonly Session $session,
        private readonly TagAdminService $tagAdminService,
        private readonly TagRepository $tagRepository,
        private readonly TagService $tagService,
        private readonly UrlGenerator $urlGenerator,
        private readonly UrlService $urlService,
        private readonly UserAdminService $userAdminService,
        private readonly UserCaddieRepository $userCaddieRepository,
        private readonly UserFavoriteRepository $userFavoriteRepository,
        private readonly UserRepository $userRepository,
        private readonly ActivityLogger $activityLogger,
        private readonly CsrfService $csrfService,
        private readonly InputValidator $inputValidator,
        private readonly RedirectResponder $redirectResponder,
        private readonly PaginationService $paginationService,
        private readonly EventDispatcherInterface $dispatcher,
        private readonly OrderByService $orderByService,
    ) {
    }

    #[\Override]
    public function handle(string $page): void
    {
        if ($page === 'batch_manager') {
            $this->batchManager();
        } elseif ($page === 'batch_manager_global') {
            $this->batchManagerGlobal();
        } elseif ($page === 'batch_manager_unit') {
            $this->batchManagerUnit();
        } elseif ($page === 'queue') {
            $this->queue();
        }
    }

    // ── batch_manager ─────────────────────────────────────────────────────────

    private function batchManager(): void
    {
        $tpl = TemplateRegistry::current();
        /** @var array<string, mixed> $user */
        $user = CurrentUser::get()->rawAttributes;
        $this->inputValidator->check('selection', $_POST, true, ValidationPattern::ID);
        $this->inputValidator->check('display', $_REQUEST, false, '/^(\d+|all)$/');

        // ── Specific actions ──────────────────────────────────────────────────

        if (isset($_GET['action'])) {
            if ('empty_caddie' == $_GET['action']) {
                $this->userCaddieRepository->deleteAllByUserId(is_numeric($user['id']) ? (int) $user['id'] : 0);
                $this->session->flash->add('info', Lang::t('Information data registered in database'));
                $this->redirectResponder->redirect($this->urlGenerator->admin() . '&page=' . (is_string($rawPage = $_GET['page'] ?? null) ? $rawPage : ''));
            }

            if ('delete_orphans' == $_GET['action'] && isset($_GET['nb_orphans_deleted'])) {
                $this->inputValidator->check('nb_orphans_deleted', $_GET, false, '/^\d+$/');
                $nb_orphans_deleted = is_numeric($_GET['nb_orphans_deleted']) ? (int) $_GET['nb_orphans_deleted'] : 0;
                if ($nb_orphans_deleted > 0) {
                    $this->session->flash->add('info', Translator::get()->plural('%d photo was deleted', '%d photos were deleted', $nb_orphans_deleted));
                    $getPage = $_GET['page'] ?? null;
                    $this->redirectResponder->redirect($this->urlGenerator->admin() . '&page=' . (is_string($getPage) ? $getPage : ''));
                }
            }

            if ('sync_md5sum' == $_GET['action'] && isset($_GET['nb_md5sum_added'])) {
                $this->inputValidator->check('nb_md5sum_added', $_GET, false, '/^\d+$/');
                $nb_md5sum_added = is_numeric($_GET['nb_md5sum_added']) ? (int) $_GET['nb_md5sum_added'] : 0;
                if ($nb_md5sum_added > 0) {
                    $this->session->flash->add('info', Translator::get()->plural('%d checksums were added', '%d checksums were added', $nb_md5sum_added));
                    $getPage2 = $_GET['page'] ?? null;
                    $this->redirectResponder->redirect($this->urlGenerator->admin() . '&page=' . (is_string($getPage2) ? $getPage2 : ''));
                }
            }
        }

        // ── Build filter set from POST/GET ────────────────────────────────────

        if (isset($_POST['submitFilter'])) {
            unset($_REQUEST['start']);
            /** @var array<string, mixed> $bmf */
            $bmf = [];

            if (isset($_POST['filter_prefilter_use'])) {
                $bmf['prefilter'] = $_POST['filter_prefilter'];
                if ('duplicates' == $_POST['filter_prefilter']) {
                    $has_options = false;
                    foreach ([DuplicateField::Checksum, DuplicateField::Date, DuplicateField::Dimensions] as $duplicateField) {
                        if (isset($_POST['filter_duplicates_' . $duplicateField->value])) {
                            $bmf['duplicates_' . $duplicateField->value] = true;
                            $has_options                                  = true;
                        }
                    }
                    if (!$has_options || isset($_POST['filter_duplicates_filename'])) {
                        $bmf['duplicates_' . DuplicateField::Filename->value] = true;
                    }
                }
            }

            if (isset($_POST['filter_category_use'])) {
                $this->inputValidator->check('filter_category', $_POST, false, ValidationPattern::ID);
                $bmf['category'] = $_POST['filter_category'];
                if (isset($_POST['filter_category_recursive'])) {
                    $bmf['category_recursive'] = true;
                }
            }

            if (isset($_POST['filter_tags_use'])) {
                $filter_tags_post = $_POST['filter_tags'] ?? null;
                if (is_array($filter_tags_post)) {
                    $filter_tags_raw = array_map(static fn (mixed $v): string => is_string($v) ? $v : '', $filter_tags_post);
                } else {
                    $filter_tags_raw = is_string($filter_tags_post) ? $filter_tags_post : '';
                }
                $bmf['tags'] = $this->tagAdminService->getTagIds($filter_tags_raw, false);
                if (isset($_POST['tag_mode']) && in_array($_POST['tag_mode'], ['AND', 'OR'])) {
                    $bmf['tag_mode'] = $_POST['tag_mode'];
                }
            }

            if (isset($_POST['filter_level_use'])) {
                $this->inputValidator->check('filter_level', $_POST, false, '/^\d+$/');
                if (in_array($_POST['filter_level'], Config::availablePermissionLevels())) {
                    $bmf['level'] = $_POST['filter_level'];
                    if (isset($_POST['filter_level_include_lower'])) {
                        $bmf['level_include_lower'] = true;
                    }
                }
            }

            if (isset($_POST['filter_dimension_use'])) {
                /** @var array<string, mixed> $dim_filter */
                $dim_filter = [];
                foreach (['min_width', 'max_width', 'min_height', 'max_height'] as $type) {
                    if (filter_var($_POST['filter_dimension_' . $type], FILTER_VALIDATE_INT) !== false) {
                        $dim_filter[$type] = $_POST['filter_dimension_' . $type];
                    }
                }
                foreach (['min_ratio', 'max_ratio'] as $type) {
                    if (filter_var($_POST['filter_dimension_' . $type], FILTER_VALIDATE_FLOAT) !== false) {
                        $dim_filter[$type] = $_POST['filter_dimension_' . $type];
                    }
                }
                $bmf['dimension'] = $dim_filter;
            }

            if (isset($_POST['filter_filesize_use'])) {
                /** @var array<string, mixed> $fs_filter */
                $fs_filter = [];
                foreach (['min', 'max'] as $type) {
                    if (filter_var($_POST['filter_filesize_' . $type], FILTER_VALIDATE_FLOAT) !== false) {
                        $fs_filter[$type] = $_POST['filter_filesize_' . $type];
                    }
                }
                $bmf['filesize'] = $fs_filter;
            }

            if (isset($_POST['filter_search_use'])) {
                $bmf['search'] = ['q' => $_POST['q']];
            }

            $registerEvent = new BatchManagerRegisterFilters($bmf);
            $this->dispatcher->dispatch($registerEvent);
            $this->session->bulkManagerFilter = $registerEvent->bulkManagerFilter;
        } elseif (isset($_GET['filter'])) {
            if (!is_array($_GET['filter'])) {
                /** @var string $rawFilter */
                $rawFilter      = $_GET['filter'];
                $_GET['filter'] = explode(',', $rawFilter);
            }
            /** @var array<string, mixed> $bmf */
            $bmf = [];

            foreach ($_GET['filter'] as $filter) {
                [$type, $value] = explode('-', is_string($filter) ? $filter : '', 2) + ['1' => ''];

                switch ($type) {
                    case 'prefilter':
                        if (preg_match('/^duplicates-?/', $value)) {
                            $dupParts = explode('-', $value, 2);
                            $duplicate_field = $dupParts[1] ?? '';
                            $bmf['prefilter'] = 'duplicates';
                            if (DuplicateField::tryFrom($duplicate_field) !== null) {
                                $bmf['duplicates_' . $duplicate_field] = true;
                            }
                        } else {
                            $bmf['prefilter'] = $value;
                        }
                        break;
                    case 'album': case 'category': case 'cat':
                        if (is_numeric($value)) {
                            $bmf['category'] = $value;
                        }
                        break;
                    case 'tag':
                        if (is_numeric($value)) {
                            $bmf['tags'] = [$value];
                            $bmf['tag_mode'] = 'AND';
                        }
                        break;
                    case 'level':
                        if (is_numeric($value) && in_array($value, Config::availablePermissionLevels())) {
                            $bmf['level'] = $value;
                        }
                        break;
                    case 'search':
                        $bmf['search'] = ['q' => $value];
                        break;
                    case 'dimension':
                        $dim_map = ['w' => 'width', 'h' => 'height', 'r' => 'ratio'];
                        /** @var array<string, string> $url_dim_filter */
                        $url_dim_filter = is_array($bmf['dimension'] ?? null) ? $bmf['dimension'] : [];
                        foreach (explode('-', $value) as $part) {
                            $values = explode('..', substr($part, 1));
                            if (!isset($dim_map[$part[0]])) {
                                continue;
                            }
                            $dtype = $dim_map[$part[0]];
                            $filter_validate = ['width' => FILTER_VALIDATE_INT, 'height' => FILTER_VALIDATE_INT, 'ratio' => FILTER_VALIDATE_FLOAT];
                            $valid = true;
                            foreach ($values as $v) {
                                if (filter_var($v, $filter_validate[$dtype]) === false) {
                                    $valid = false;
                                }
                            }
                            if ($valid) {
                                [$url_dim_filter['min_' . $dtype], $url_dim_filter['max_' . $dtype]] = $values;
                            }
                        }
                        $bmf['dimension'] = $url_dim_filter;
                        break;
                    case 'filesize':
                        $values = explode('..', $value);
                        $valid  = true;
                        foreach ($values as $v) {
                            if (filter_var($v, FILTER_VALIDATE_FLOAT) === false) {
                                $valid = false;
                            }
                        }
                        if ($valid) {
                            /** @var array<string, string> $url_fs_filter */
                            $url_fs_filter = [];
                            [$url_fs_filter['min'], $url_fs_filter['max']] = $values;
                            $bmf['filesize'] = $url_fs_filter;
                        }
                        break;
                    default:
                        $urlFilterEvent = new BatchManagerUrlFilter($bmf, is_string($filter) ? $filter : '');
                        $this->dispatcher->dispatch($urlFilterEvent);
                        $bmf = $urlFilterEvent->bulkManagerFilter;
                        break;
                }
            }

            $this->session->bulkManagerFilter = $bmf;
        }

        if ($this->session->bulkManagerFilter === null || $this->session->bulkManagerFilter === []) {
            $this->session->bulkManagerFilter = ['prefilter' => 'caddie'];
        }

        /** @var array<string, mixed> $bmf */
        $bmf = $this->session->bulkManagerFilter;

        // ── Build photo set from filters ──────────────────────────────────────

        $filter_sets     = [];
        $bmf_prefilter   = is_string($bmf['prefilter'] ?? null) ? $bmf['prefilter'] : '';
        if ($bmf_prefilter !== '') {
            switch ($bmf_prefilter) {
                case 'caddie':
                    $userId        = is_numeric($user['id']) ? (int) $user['id'] : 0;
                    $filter_sets[] = $this->userCaddieRepository->findElementIdsByUserId($userId);
                    break;
                case 'favorites':
                    $userId2       = is_numeric($user['id']) ? (int) $user['id'] : 0;
                    $filter_sets[] = $this->userFavoriteRepository->findImageIdsByUserId($userId2);
                    break;
                case 'last_import':
                    $last_import_date = $this->imageRepository->findMaxDateAvailable();
                    if ($last_import_date !== null && $last_import_date !== '') {
                        $filter_sets[] = $this->imageRepository->findIdsByDateAvailableBetween(SqlExpr::recentPeriodExpr(1, $last_import_date), $last_import_date);
                    }
                    break;
                case 'no_virtual_album':
                    $all_elements        = $this->imageRepository->findAllIds();
                    $virtual_categories  = $this->categoryRepository->findVirtualCategoryIds();
                    $linked_to_virtual   = $virtual_categories === [] ? [] : $this->categoryRepository->findImageIdsLinkedToCategories($virtual_categories);
                    $filter_sets[] = array_values(array_diff($all_elements, $linked_to_virtual));
                    break;
                case 'no_album':
                    $filter_sets[] = $this->imageAdminService->getOrphans();
                    break;
                case 'no_sync_md5sum':
                    $filter_sets[] = $this->imageAdminService->getPhotosNoMd5sum();
                    break;
                case 'no_tag':
                    $filter_sets[] = $this->imageRepository->findUntaggedIds();
                    break;
                case 'duplicates':
                    $duplicates_on_fields = [];
                    foreach (DuplicateField::cases() as $duplicateField) {
                        if (isset($bmf['duplicates_' . $duplicateField->value])) {
                            $duplicates_on_fields = [...$duplicates_on_fields, ...$duplicateField->dbColumns()];
                        }
                    }
                    $filter_sets[] = $this->imageRepository->findIdsInDuplicateGroups(
                        $duplicates_on_fields,
                        in_array('md5sum', $duplicates_on_fields),
                    );
                    break;
                case 'all_photos':
                    if (count($bmf) == 1) {
                        $filter_sets[] = $this->imageRepository->findAllIdsWithOrderSuffix($this->orderByService->buildOrderByClause(Config::orderBy()));
                    }
                    break;
                default:
                    $preEvent = new PerformBatchManagerPrefilters($filter_sets, $bmf_prefilter);
                    $this->dispatcher->dispatch($preEvent);
                    $filter_sets = $preEvent->filterSets;
                    break;
            }
        }

        if (isset($bmf['category'])) {
            $bmf_category = is_numeric($bmf['category']) ? (int) $bmf['category'] : 0;
            if (!$this->categoryRepository->existsById($bmf_category)) {
                $this->session->bulkManagerFilter = null;
                $this->redirectResponder->redirect($this->urlGenerator->admin() . '&page=' . (is_string($rawPage = $_GET['page'] ?? null) ? $rawPage : ''));
            }
            $categories   = isset($bmf['category_recursive']) ? $this->categoryService->getSubcatIds([$bmf_category]) : [$bmf_category];
            $filter_sets[] = $this->categoryRepository->findImageIdsLinkedToCategories(array_values($categories));
        }

        if (isset($bmf['level'])) {
            $operator  = isset($bmf['level_include_lower']) ? '<=' : '=';
            $bmf_level = is_numeric($bmf['level']) ? (int) $bmf['level'] : 0;
            $filter_sets[] = $this->imageRepository->findIdsByLevelComparison($operator, $bmf_level, $this->orderByService->buildOrderByClause(Config::orderBy()));
        }

        if (!empty($bmf['tags'])) {
            $bmf_tags     = array_map(static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, is_array($bmf['tags']) ? $bmf['tags'] : []);
            $bmfTagMode = $bmf['tag_mode'] ?? null;
            $bmf_tag_mode = is_string($bmfTagMode) ? $bmfTagMode : 'AND';
            $filter_sets[] = $this->tagService->getImageIdsForTags($bmf_tags, $bmf_tag_mode, null, null, false);
        }

        if (isset($bmf['dimension'])) {
            $bmf_dimension = is_array($bmf['dimension']) ? $bmf['dimension'] : [];
            $where_clauses = [];
            $where_params  = [];
            $where_types   = [];
            $dimensionFields = [
                'min_width'  => ['width >= ?',  ParameterType::INTEGER],
                'max_width'  => ['width <= ?',  ParameterType::INTEGER],
                'min_height' => ['height >= ?', ParameterType::INTEGER],
                'max_height' => ['height <= ?', ParameterType::INTEGER],
                'min_ratio'  => ['width/height >= ?', ParameterType::STRING],
            ];
            foreach ($dimensionFields as $fieldKey => [$clause, $type]) {
                if (isset($bmf_dimension[$fieldKey])) {
                    $where_clauses[] = $clause;
                    $where_params[]  = is_scalar($bmf_dimension[$fieldKey]) ? (string) $bmf_dimension[$fieldKey] : '0';
                    $where_types[]   = $type;
                }
            }
            if (isset($bmf_dimension['max_ratio'])) {
                $max_ratio       = is_numeric($bmf_dimension['max_ratio']) ? (float) $bmf_dimension['max_ratio'] : 0.0;
                $where_clauses[] = 'width/height < ?';
                $where_params[]  = number_format($max_ratio + 0.01, 4, '.', '');
                $where_types[]   = ParameterType::STRING;
            }
            if (!empty($where_clauses)) {
                $filter_sets[] = $this->imageRepository->findIdsByWhereFragment(
                    implode(' AND ', $where_clauses),
                    $this->orderByService->buildOrderByClause(Config::orderBy()),
                    $where_params,
                    $where_types,
                );
            }
        }

        if (isset($bmf['filesize'])) {
            $bmf_filesize = is_array($bmf['filesize']) ? $bmf['filesize'] : [];
            $where_clauses = [];
            $where_params  = [];
            $where_types   = [];
            if (isset($bmf_filesize['min'])) {
                $fs_min = is_numeric($bmf_filesize['min']) ? (float) $bmf_filesize['min'] : 0.0;
                $where_clauses[] = 'filesize >= ?';
                $where_params[]  = number_format(($fs_min - 0.1) * 1024.0, 4, '.', '');
                $where_types[]   = ParameterType::STRING;
            }
            if (isset($bmf_filesize['max'])) {
                $fs_max = is_numeric($bmf_filesize['max']) ? (float) $bmf_filesize['max'] : 0.0;
                $where_clauses[] = 'filesize <= ?';
                $where_params[]  = number_format(($fs_max + 0.1) * 1024.0, 4, '.', '');
                $where_types[]   = ParameterType::STRING;
            }
            if (!empty($where_clauses)) {
                $filter_sets[] = $this->imageRepository->findIdsByWhereFragment(
                    implode(' AND ', $where_clauses),
                    $this->orderByService->buildOrderByClause(Config::orderBy()),
                    $where_params,
                    $where_types,
                );
            }
        }

        if (isset($bmf['search'])) {
            $bmf_search   = is_array($bmf['search']) ? $bmf['search'] : [];
            $bmf_search_q = is_string($bmf_search['q'] ?? null) ? $bmf_search['q'] : '';
            if (strlen($bmf_search_q) > 0) {
                $res       = $this->searchService->getQuickSearchResultsNoCache($bmf_search_q, ['permissions' => false]);
                $res_qs    = is_array($res['qs'] ?? null) ? $res['qs'] : [];
                if (!empty($res['items']) && !empty($res_qs['unmatched_terms'])) {
                    $tpl->assign('no_search_results', array_map(static fn (mixed $v): string => htmlspecialchars(is_scalar($v) ? (string) $v : ''), is_array($res_qs['unmatched_terms']) ? $res_qs['unmatched_terms'] : []));
                }
                $filter_sets[] = $res['items'];
            }
        }

        $performEvent = new BatchManagerPerformFilters($filter_sets, $bmf);
        $this->dispatcher->dispatch($performEvent);
        $filter_sets = $performEvent->filterSets;

        $current_set = array_shift($filter_sets);
        foreach ($filter_sets as $set) {
            $a = is_array($current_set) ? array_map(static fn (mixed $v): string => is_scalar($v) ? (string) $v : '0', $current_set) : [];
            $b = is_array($set) ? array_map(static fn (mixed $v): string => is_scalar($v) ? (string) $v : '0', $set) : [];
            $current_set = array_intersect($a, $b);
        }
        $this->catElementsId = !is_array($current_set) || empty($current_set) ? [] : array_values(array_map(
            static fn (mixed $v): string => is_scalar($v) ? (string) $v : '0',
            $current_set
        ));

        // ── Pagination ────────────────────────────────────────────────────────

        if (!isset($_REQUEST['start']) || !is_numeric($_REQUEST['start']) || $_REQUEST['start'] < 0 || (isset($_REQUEST['display']) && 'all' == $_REQUEST['display'])) {
            $this->pageStart = 0;
        } else {
            $this->pageStart = (int) $_REQUEST['start'];
        }

        // ── Tabs ──────────────────────────────────────────────────────────────

        if (isset($_GET['mode'])) {
            $this->inputValidator->check('mode', $_GET, false, '/^(global|unit)$/');
            $this->batchTab = is_string($_GET['mode']) ? $_GET['mode'] : 'global';
        } else {
            $this->batchTab = 'global';
        }

        $tabsheet = new Tabsheet();
        $tabsheet->setId('batch_manager');
        $tabsheet->select($this->batchTab);
        $tabsheet->assign();

        // ── Dimensions ────────────────────────────────────────────────────────

        $widths = $heights = $ratios = [];
        foreach ($this->imageRepository->findDistinctDimensions() as $row) {
            $row_width  = $row['width'];
            $row_height = $row['height'];
            if ($row_width > 0 && $row_height > 0) {
                $widths[]  = $row_width;
                $heights[] = $row_height;
                $ratios[]  = floor((float) $row_width / (float) $row_height * 100.0) / 100.0;
            }
        }
        if (empty($widths)) {
            $widths = [600, 1920, 3500];
            $heights = [480, 1080, 2300];
            $ratios = [1.25, 1.52, 1.78];
        }

        $dimensions = [];
        foreach (['widths', 'heights', 'ratios'] as $type) {
            ${$type} = array_unique(${$type});
            sort(${$type});
            $dimensions[$type] = implode(',', ${$type});
        }
        $dimensions['bounds'] = ['min_width' => $widths[0], 'max_width' => end($widths), 'min_height' => $heights[0] ?? 0, 'max_height' => end($heights), 'min_ratio' => $ratios[0] ?? 0.0, 'max_ratio' => end($ratios)];

        $ratio_categories = ['portrait' => [], 'square' => [], 'landscape' => [], 'panorama' => []];
        foreach ($ratios as $ratio) {
            if ($ratio < 0.95) {
                $ratio_categories['portrait'][]  = $ratio;
            } elseif ($ratio >= 0.95 && $ratio <= 1.05) {
                $ratio_categories['square'][]    = $ratio;
            } elseif ($ratio > 1.05 && $ratio < 2) {
                $ratio_categories['landscape'][] = $ratio;
            } elseif ($ratio >= 2) {
                $ratio_categories['panorama'][]  = $ratio;
            }
        }
        foreach ($ratio_categories as $rtype => $rtypeValues) {
            if (count($rtypeValues) > 0) {
                $dimensions['ratio_' . $rtype] = ['min' => $rtypeValues[0], 'max' => end($rtypeValues)];
            }
        }

        $bmf_dimension_sel = is_array($bmf['dimension'] ?? null) ? $bmf['dimension'] : [];
        $dimensions['selected'] = [];
        foreach (array_keys($dimensions['bounds']) as $dtype) {
            $dimensions['selected'][$dtype] = $bmf_dimension_sel[$dtype] ?? $dimensions['bounds'][$dtype];
        }
        $tpl->assign('dimensions', $dimensions);

        // ── Filesizes ─────────────────────────────────────────────────────────

        $filesizes = [];
        foreach ($this->imageRepository->findDistinctFilesizes() as $filesize_kb) {
            $filesizes[] = sprintf('%.1f', (float) $filesize_kb / 1024.0);
        }
        if (empty($filesizes)) {
            $filesizes = [0, 1, 2, 5, 8, 15];
        }
        $filesizes = array_unique($filesizes);
        sort($filesizes);

        $filesize = [];
        $filesize['list']   = implode(',', $filesizes);
        $filesize['bounds'] = ['min' => $filesizes[0], 'max' => end($filesizes)];
        $bmf_filesize_sel   = is_array($bmf['filesize'] ?? null) ? $bmf['filesize'] : [];
        foreach (array_keys($filesize['bounds']) as $ftype) {
            $filesize['selected'][$ftype] = $bmf_filesize_sel[$ftype] ?? $filesize['bounds'][$ftype];
        }
        $tpl->assign('filesize', $filesize);

        $sliders_json = [
            'widths'    => ['values' => array_map(floatval(...), explode(',', $dimensions['widths'])),   'selected' => ['min' => $dimensions['selected']['min_width'],  'max' => $dimensions['selected']['max_width']],  'text' => Lang::t('between %d and %d pixels')],
            'heights'   => ['values' => array_map(floatval(...), explode(',', $dimensions['heights'])),  'selected' => ['min' => $dimensions['selected']['min_height'], 'max' => $dimensions['selected']['max_height']], 'text' => Lang::t('between %d and %d pixels')],
            'ratios'    => ['values' => array_map(floatval(...), explode(',', $dimensions['ratios'])),   'selected' => ['min' => $dimensions['selected']['min_ratio'],  'max' => $dimensions['selected']['max_ratio']],  'text' => Lang::t('between %.2f and %.2f')],
            'filesizes' => ['values' => array_map(floatval(...), explode(',', $filesize['list'])),       'selected' => ['min' => $filesize['selected']['min'], 'max' => $filesize['selected']['max']],                  'text' => Lang::t('between %s and %s MB')],
        ];

        $selected_category            = is_numeric($bmf['category'] ?? null) ? (int) $bmf['category'] : null;
        $filter_category_selected_val = $selected_category;
        $tpl->assign('batch_filter_page_data_json', json_encode([
            'sliders'                  => $sliders_json,
            'selected_filter_cat_ids'  => $filter_category_selected_val !== null ? [$filter_category_selected_val] : [],
            'str_select_album'         => Lang::t('Select at least one album'),
            'str_select_tag'           => Lang::t('Select at least one tag'),
        ], JSON_HEX_TAG | JSON_UNESCAPED_UNICODE));

        // ── Dispatch to tab ───────────────────────────────────────────────────

        $tab = $this->batchTab;
        if ($tab === 'global') {
            $this->batchManagerGlobal();
        } elseif ($tab === 'unit') {
            $this->batchManagerUnit();
        }
    }

    // ── batch_manager_global ──────────────────────────────────────────────────

    private function batchManagerGlobal(): void
    {
        $tpl = TemplateRegistry::current();
        /** @var array<string, mixed> $user */
        $user = CurrentUser::get()->rawAttributes;
        $duplicates_on_fields = [];
        $associated_categories = [];
        if (!empty($_POST)) {
            $this->csrfService->check();
        }

        $this->dispatcher->dispatch(new LocBeginElementSetGlobal());

        $this->inputValidator->check('del_tags', $_POST, true, ValidationPattern::ID);
        $this->inputValidator->check('associate', $_POST, true, ValidationPattern::ID);
        $this->inputValidator->check('move', $_POST, false, ValidationPattern::ID);
        $this->inputValidator->check('dissociate', $_POST, false, ValidationPattern::ID);

        $collection = [];
        if (isset($_POST['nb_photos_deleted'])) {
            $this->inputValidator->check('nb_photos_deleted', $_POST, false, '/^\d+$/');
            $collection = array_fill(0, is_numeric($_POST['nb_photos_deleted']) ? (int) $_POST['nb_photos_deleted'] : 0, null);
        } elseif (isset($_POST['setSelected'])) {
            $collection = explode(',', is_string($rawWholeSet1 = $_POST['whole_set'] ?? null) ? $rawWholeSet1 : '');
            foreach ($collection as $id) {
                if (!preg_match('/^\d+$/', $id)) {
                    HtmlService::fatalError('[Hacking attempt] the input parameter "whole_set" is not valid');
                }
            }
        } elseif (isset($_POST['selection'])) {
            $collection = is_array($_POST['selection']) ? $_POST['selection'] : [];
        }

        $this->prefilter = 'none';
        /** @var array<string, mixed> $bmf */
        $bmf = $this->session->bulkManagerFilter ?? [];
        if (is_string($bmf['prefilter'] ?? null)) {
            $this->prefilter = $bmf['prefilter'];
        }

        $redirect_url = $this->urlGenerator->admin() . '&page=' . (is_string($rawPage = $_GET['page'] ?? null) ? $rawPage : '');

        /** @var array<int> $collection_int */
        $collection_int = array_map(static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $collection);

        if (isset($_POST['submit'])) {
            if (count($collection_int) == 0) {
                PageState::current()->addError(Lang::t('Select at least one photo'));
            }

            $selectActionRaw = $_POST['selectAction'] ?? null;
            $action   = is_string($selectActionRaw) ? $selectActionRaw : '';
            $redirect = false;

            if ('remove_from_caddie' == $action) {
                $this->userCaddieRepository->deleteByImageIds(is_numeric($user['id']) ? (int) $user['id'] : 0, $collection_int);
                $redirect = true;
            } elseif ('add_tags' == $action) {
                if (!isset($_POST['add_tags']) || $_POST['add_tags'] === '') {
                    PageState::current()->addError(Lang::t('Select at least one tag'));
                } else {
                    /** @var array<mixed>|string $add_tags_raw */
                    $add_tags_raw = $_POST['add_tags'];
                    if (is_array($add_tags_raw)) {
                        $add_tags_val = array_map(static fn (mixed $v): string => is_string($v) ? $v : '', $add_tags_raw);
                    } else {
                        $add_tags_val = $add_tags_raw;
                    }
                    $tag_ids = $this->tagAdminService->getTagIds($add_tags_val);
                    $this->tagAdminService->addTags($tag_ids, $collection_int);
                    if ('no_tag' == $this->prefilter) {
                        $redirect = true;
                    }
                }
            } elseif ('del_tags' == $action) {
                $del_tags_raw = $_POST['del_tags'] ?? null;
                $del_tags_post = is_array($del_tags_raw) ? $del_tags_raw : [];
                /** @var array<int> $del_tags_int */
                $del_tags_int = array_map(static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $del_tags_post);
                if (count($del_tags_int) > 0) {
                    $taglist_before = $this->tagAdminService->getImageTagIds($collection_int);
                    $this->tagRepository->deleteImageTagsByImageIdsAndTagIds($collection_int, $del_tags_int);
                    $taglist_after  = $this->tagAdminService->getImageTagIds($collection_int);
                    /** @var array<int> $images_to_update */
                    $images_to_update = array_map(static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $this->tagAdminService->compareImageTagLists($taglist_before, $taglist_after));
                    $this->imageAdminService->updateImagesLastmodified($images_to_update);
                    $bmf_tags_int = array_map(static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, is_array($bmf['tags'] ?? null) ? $bmf['tags'] : []);
                    if (count(array_intersect($bmf_tags_int, $del_tags_int)) > 0) {
                        $redirect = true;
                    }
                } else {
                    PageState::current()->addError(Lang::t('Select at least one tag'));
                }
            } elseif ('associate' == $action) {
                if (!isset($_POST['associate']) || $_POST['associate'] === '') {
                    PageState::current()->addError(Lang::t('Select at least one album'));
                } else {
                    $associate_raw = is_array($_POST['associate']) ? array_map(fn ($v): int => is_numeric($v) ? (int) $v : 0, $_POST['associate']) : [];
                    $this->categoryAdminService->associateImagesToCategories($collection_int, $associate_raw);
                    $this->session->flash->add('info', Lang::t('Information data registered in database'));
                    if ('no_album' == $this->prefilter) {
                        $redirect = true;
                    } elseif ('no_virtual_album' == $this->prefilter) {
                        $rawAssociate  = $_POST['associate'];
                        $associate_id  = is_string($rawAssociate) ? $rawAssociate : '';
                        $category_info = $this->categoryService->getCatInfo($associate_id);
                        if (!isset($category_info['dir']) || $category_info['dir'] === '') {
                            $redirect = true;
                        }
                    }
                }
            } elseif ('move' == $action) {
                $moveRaw     = $_POST['move'] ?? null;
                $move_id     = is_string($moveRaw) ? $moveRaw : '';
                $move_id_int = is_numeric($move_id) ? (int) $move_id : 0;
                $this->categoryAdminService->moveImagesToCategories($collection_int, [$move_id_int]);
                $this->session->flash->add('info', Lang::t('Information data registered in database'));
                if ('no_album' == $this->prefilter) {
                    $redirect = true;
                } elseif ('no_virtual_album' == $this->prefilter) {
                    $category_info = $this->categoryService->getCatInfo($move_id);
                    if (!isset($category_info['dir']) || $category_info['dir'] === '') {
                        $redirect = true;
                    }
                } elseif (isset($bmf['category']) && $move_id != (is_string($bmf['category']) ? $bmf['category'] : '')) {
                    $redirect = true;
                }
            } elseif ('dissociate' == $action) {
                $dissociate_key = $_POST['dissociate'] ?? null;
                $dissociate_raw = is_string($dissociate_key) ? $dissociate_key : '';
                $nb_dissociated = $this->categoryAdminService->dissociateImagesFromCategory($collection_int, $dissociate_raw);
                if ($nb_dissociated > 0) {
                    $this->session->flash->add('info', Lang::t('Information data registered in database'));
                    $redirect = true;
                }
            } elseif ('author' == $action) {
                $rawAuthor   = isset($_POST['remove_author']) ? null : ($_POST['author'] ?? null);
                $authorValue = is_string($rawAuthor) ? $rawAuthor : null;
                $datas = [];
                foreach ($collection_int as $image_id) {
                    $datas[] = ['id' => $image_id, 'author' => $authorValue];
                }
                $this->imageRepository->setAuthorBatch($datas);
                $this->activityLogger->log(new ActivityEvent(ActivityObject::Photo, $collection_int, ActivityAction::Edit, ['action' => 'author']));
            } elseif ('title' == $action) {
                $rawTitle   = isset($_POST['remove_title']) ? null : ($_POST['title'] ?? null);
                $titleValue = is_string($rawTitle) ? $rawTitle : null;
                $datas = [];
                foreach ($collection_int as $image_id) {
                    $datas[] = ['id' => $image_id, 'name' => $titleValue];
                }
                $this->imageRepository->setNameBatch($datas);
                $this->activityLogger->log(new ActivityEvent(ActivityObject::Photo, $collection_int, ActivityAction::Edit, ['action' => 'title']));
            } elseif ('date_creation' == $action) {
                $rawDateCreation = (isset($_POST['remove_date_creation']) || !isset($_POST['date_creation']) || $_POST['date_creation'] === '') ? null : $_POST['date_creation'];
                $date_creation   = is_string($rawDateCreation) ? $rawDateCreation : null;
                $datas = [];
                foreach ($collection_int as $image_id) {
                    $datas[] = ['id' => $image_id, 'date_creation' => $date_creation];
                }
                $this->imageRepository->setDateCreationBatch($datas);
                $this->activityLogger->log(new ActivityEvent(ActivityObject::Photo, $collection_int, ActivityAction::Edit, ['action' => 'date_creation']));
            } elseif ('level' == $action) {
                $rawLevel   = $_POST['level'] ?? null;
                $levelValue = is_numeric($rawLevel) ? (int) $rawLevel : 0;
                $datas = [];
                foreach ($collection_int as $image_id) {
                    $datas[] = ['id' => $image_id, 'level' => $levelValue];
                }
                $this->imageRepository->setLevelBatch($datas);
                $this->activityLogger->log(new ActivityEvent(ActivityObject::Photo, $collection_int, ActivityAction::Edit, ['action' => 'privacy_level']));
                if (isset($bmf['level'])) {
                    $bmf_level_val  = is_numeric($bmf['level']) ? (int) $bmf['level'] : 0;
                    $postLevelRaw = $_POST['level'] ?? null;
                    $post_level_val = is_numeric($postLevelRaw) ? (int) $postLevelRaw : 0;
                    if ($post_level_val < $bmf_level_val) {
                        $redirect = true;
                    }
                }
            } elseif ('add_to_caddie' == $action) {
                $this->userCaddieRepository->addElements(CurrentUser::get()->id, array_values($collection_int));
            } elseif ('delete' == $action) {
                if (isset($_POST['confirm_deletion']) && 1 == $_POST['confirm_deletion']) {
                    if (count($collection_int) > 0) {
                        $this->session->flash->add('info', Translator::get()->plural('%d photo was deleted', '%d photos were deleted', count($collection_int)));
                        $redirect_url = $this->urlGenerator->admin() . '&page=' . (is_string($rawPage = $_GET['page'] ?? null) ? $rawPage : '');
                        $redirect     = true;
                    } else {
                        PageState::current()->addError(Lang::t('No photo can be deleted'));
                    }
                } else {
                    PageState::current()->addError(Lang::t('You need to confirm deletion'));
                }
            } elseif ('metadata' == $action) {
                PageState::current()->addInfo(new Html(Lang::t('Metadata synchronized from file') . ' <span class="badge">' . count($collection_int) . '</span>'));
            } elseif ('delete_derivatives' == $action && isset($_POST['del_derivatives_type']) && $_POST['del_derivatives_type'] !== '') {
                foreach ($this->imageRepository->findPathsAndRepresentativesByIds($collection_int) as $proj) {
                    $del_types = is_array($_POST['del_derivatives_type']) ? $_POST['del_derivatives_type'] : [];
                    $infoShim  = ['path' => $proj->path->value, 'representative_ext' => $proj->representativeExt];
                    foreach ($del_types as $dtype) {
                        $this->imageAdminService->deleteElementDerivatives($infoShim, is_string($dtype) ? $dtype : '');
                    }
                }
            } elseif ('generate_derivatives' == $action) {
                if ($_POST['regenerateSuccess'] != '0') {
                    $regenSuccess = $_POST['regenerateSuccess'] ?? '';
                    PageState::current()->addInfo(Lang::t('%s photos have been regenerated', is_string($regenSuccess) ? $regenSuccess : ''));
                }
                if ($_POST['regenerateError'] != '0') {
                    $regenError = $_POST['regenerateError'] ?? '';
                    PageState::current()->addWarning(Lang::t('%s photos can not be regenerated', is_string($regenError) ? $regenError : ''));
                }
            }

            if (!in_array($action, ['remove_from_caddie', 'add_to_caddie', 'delete_derivatives', 'generate_derivatives'])) {
                $this->userAdminService->invalidateUserCache();
            }

            $this->dispatcher->dispatch(new ElementSetGlobalAction($action, $collection_int));
            if ($redirect) {
                $this->redirectResponder->redirect($redirect_url);
            }
        }

        // ── Template ──────────────────────────────────────────────────────────

        $base_url = $this->urlGenerator->admin();

        $this->filterResolver->render($collection, $base_url, $this->catElementsId, $this->pageStart);

        $catElementsId = $this->catElementsId;
        $pageStart     = $this->pageStart;

        $tpl->assign('IN_CADDIE', 'caddie' == $this->prefilter);

        if (count($catElementsId) > 0) {
            $tpl->assign('associated_tags', $this->tagService->getCommonTags($catElementsId, -1));
        }

        $rawDateCreationPost = $_POST['date_creation'] ?? null;
        $dateCreationAssign  = (!isset($_POST['date_creation']) || $_POST['date_creation'] === '') ? date('Y-m-d') . ' 00:00:00' : (is_string($rawDateCreationPost) ? $rawDateCreationPost : '');
        $tpl->assign('DATE_CREATION', $dateCreationAssign);
        $tpl->assign(['level_options' => $this->htmlService->getPrivacyLevelOptions(), 'level_options_selected' => 0]);

        $site_reader  = new LocalSiteReader('./');
        $used_metadata = implode(', ', array_map(static fn (mixed $v): string => is_scalar($v) ? (string) $v : '', $site_reader->getMetadataAttributes()));
        $tpl->assign(['used_metadata' => $used_metadata]);

        $del_deriv_map = [];
        foreach (ImageStdParams::getDefinedTypeMap() as $params) {
            $del_deriv_map[$params->type] = Lang::t($params->type);
        }
        $gen_deriv_map  = $del_deriv_map;
        $del_deriv_map[DerivativeSize::Custom->value] = Lang::t(DerivativeSize::Custom->value);
        $tpl->assign(['del_derivatives_types' => $del_deriv_map, 'generate_derivatives_types' => $gen_deriv_map]);

        if (isset($_GET['display']) && $_GET['display'] !== '') {
            $nbImages = 'all' == $_GET['display'] ? count($catElementsId) : (is_numeric($_GET['display']) ? (int) $_GET['display'] : 20);
        } elseif (in_array(Config::batchManagerImagesPerPageGlobal(), [20, 50, 100])) {
            $nbImages = Config::batchManagerImagesPerPageGlobal();
        } else {
            $nbImages = 20;
        }

        $nb_thumbs_page = 0;

        if (count($catElementsId) > 0) {
            $nav_bar = $this->paginationService->createNavigationBar($base_url . $this->urlService->getQueryStringDiff(['start']), count($catElementsId), $pageStart, $nbImages);
            $tpl->assign('navbar', $nav_bar);

            $is_category      = isset($bmf['category']) && !isset($bmf['category_recursive']);
            $bmf_category_val = is_numeric($bmf['category'] ?? null) ? (int) $bmf['category'] : 0;

            if ($is_category) {
                $category_info = $this->categoryService->getCatInfo($bmf_category_val);
                Config::override('order_by', Config::orderByInsideCategory());
                if (isset($category_info['image_order']) && $category_info['image_order'] !== '') {
                    Config::override('order_by', ' ORDER BY ' . (is_string($category_info['image_order']) ? $category_info['image_order'] : ''));
                }
            }
            $batchImages = $this->imageRepository->findForBatchManager(
                array_map(static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $catElementsId),
                $is_category ? $bmf_category_val : null,
                $this->orderByService->buildOrderByClause(Config::orderBy()),
                $nbImages,
                $pageStart,
            );
            $thumb_params = ImageStdParams::getByType(DerivativeSize::Square->value);

            foreach ($batchImages as $img) {
                $row = $img->toRow();
                $nb_thumbs_page++;
                $src_image   = new SrcImage($row);
                $ttitle      = $this->htmlService->renderElementName($row);
                $row_file    = is_scalar($row['file'] ?? null) ? (string) $row['file'] : '';
                if ($ttitle != StringUtil::getNameFromFile($row_file)) {
                    $ttitle .= ' (' . $row_file . ')';
                }
                $row_filesize = is_numeric($row['filesize'] ?? null) ? (float) $row['filesize'] : 0.0;
                $ttitle .= '<br>' . (is_string($row['width'] ?? null) ? $row['width'] : '') . '&times;' . (is_string($row['height'] ?? null) ? $row['height'] : '') . ' pixels, ' . sprintf('%.2f', $row_filesize / 1024.0) . 'MB';

                $tpl->append('thumbnails', array_merge($row, [
                    'thumb'    => new DerivativeImage($thumb_params, $src_image),
                    'TITLE'    => new Html($ttitle),
                    'FILE_SRC' => DerivativeImage::url(DerivativeSize::Large->value, $src_image),
                    'U_EDIT'   => $this->urlGenerator->admin('photo-' . (is_scalar($row['id'] ?? null) ? (string) $row['id'] : '')),
                ]));
            }
            $tpl->assign('thumb_params', $thumb_params);
        }

        $cache_keys = $this->adminService->getAdminClientCacheKeys(['tags', 'categories']);
        $tpl->assign([
            'nb_thumbs_page'                      => $nb_thumbs_page,
            'nb_thumbs_set'                        => count($catElementsId),
            'CACHE_KEYS'                           => $cache_keys,
            'batch_manager_global_page_data_json'  => json_encode([
                'CACHE_KEYS'              => $cache_keys,
                'ROOT_URL'                => UrlService::getRootUrl(),
                'associated_categories'   => $associated_categories,
                'str_create'              => Lang::t('Create'),
                'nb_thumbs_page'          => $nb_thumbs_page,
                'nb_thumbs_set'           => count($catElementsId),
                'all_elements'            => $catElementsId,
                'lang'                    => ['Cancel' => Lang::t('Cancel'), 'deleteProgressMessage' => Lang::t('Deletion in progress'), 'syncProgressMessage' => Lang::t('Synchronization in progress'), 'AreYouSure' => Lang::t('Are you sure?'), 'generateMsg' => Lang::t('Generate multiple size images')],
                'str_add_alb_associate'   => Lang::t('Add Album'),
                'str_select_alb_associate' => Lang::t('Select an album'),
                'applyOnDetails_pattern'  => Lang::t('on the %d selected photos'),
                'selectedMessage_pattern' => Lang::t('%d of %d photos selected'),
                'selectedMessage_none'    => Lang::t('No photo selected, %d photos in current set'),
                'selectedMessage_all'     => Lang::t('All %d photos are selected'),
            ], JSON_HEX_TAG | JSON_UNESCAPED_UNICODE),
        ]);

        $this->dispatcher->dispatch(new LocEndElementSetGlobal());
        $tpl->assignVarFromTemplate('ADMIN_CONTENT', 'batch_manager_global.latte');
    }

    // ── batch_manager_unit ────────────────────────────────────────────────────

    private function batchManagerUnit(): void
    {
        $associated_categories = [];
        $tpl = TemplateRegistry::current();
        /** @var array<string, mixed> $user */
        $user = CurrentUser::get()->rawAttributes;
        $activePluginIds = $this->pluginRegistry->getActiveIds();
        $this->dispatcher->dispatch(new LocBeginElementSetUnit());

        if (isset($_POST['submit'])) {
            $this->csrfService->check();
            $this->inputValidator->check('element_ids', $_POST, false, '/^\d+(,\d+)*$/');
            $collection = explode(',', is_string($rawElementIds = $_POST['element_ids'] ?? null) ? $rawElementIds : '');

            $datas = [];
            foreach ($this->imageRepository->findByIds(array_map(intval(...), $collection)) as $img) {
                $imgId      = $img->id->value;
                $imgIdStr   = (string) $imgId;
                $data       = [
                    'id'     => $imgId,
                    'name'   => $_POST['name-' . $imgIdStr] ?? null,
                    'author' => $_POST['author-' . $imgIdStr] ?? null,
                    'level'  => $_POST['level-' . $imgIdStr] ?? null,
                ];

                $desc_val = $_POST['description-' . $imgIdStr] ?? null;
                $data['comment'] = Config::allowHtmlDescriptions() ? $desc_val : strip_tags(is_string($desc_val) ? $desc_val : '');

                $rawDateCreation = $_POST['date_creation-' . $imgIdStr] ?? null;
                $data['date_creation'] = (is_string($rawDateCreation) && $rawDateCreation !== '') ? $rawDateCreation : null;

                $datas[] = $data;

                $tag_ids  = [];
                /** @var array<mixed>|string|null $tags_val */
                $tags_val = $_POST['tags-' . $imgIdStr] ?? null;
                if ($tags_val !== null && $tags_val !== '') {
                    if (is_array($tags_val)) {
                        $tags_val = array_map(static fn (mixed $v): string => is_string($v) ? $v : '', $tags_val);
                    }
                    $tag_ids  = $this->tagAdminService->getTagIds($tags_val);
                }
                $this->tagAdminService->setTags($tag_ids, $imgId);
            }

            $updateRows = [];
            foreach ($datas as $row) {
                $updateRows[] = [
                    'id'     => $row['id'],
                    'fields' => ['name' => $row['name'], 'author' => $row['author'], 'level' => $row['level'], 'comment' => $row['comment'], 'date_creation' => $row['date_creation']],
                ];
            }
            $this->imageRepository->updateBatchByIds($updateRows);
            PageState::current()->addInfo(Lang::t('Photo informations updated'));
            $this->userAdminService->invalidateUserCache();
        }

        $collection = [];
        if (isset($_POST['nb_photos_deleted'])) {
            $this->inputValidator->check('nb_photos_deleted', $_POST, false, '/^\d+$/');
            $collection = array_fill(0, is_numeric($_POST['nb_photos_deleted']) ? (int) $_POST['nb_photos_deleted'] : 0, null);
        } elseif (isset($_POST['setSelected'])) {
            $collection = explode(',', is_string($rawWholeSet2 = $_POST['whole_set'] ?? null) ? $rawWholeSet2 : '');
            foreach ($collection as $id) {
                if (!preg_match('/^\d+$/', $id)) {
                    HtmlService::fatalError('[Hacking attempt] the input parameter "whole_set" is not valid');
                }
            }
        } elseif (isset($_POST['selection'])) {
            $collection = is_array($_POST['selection']) ? $_POST['selection'] : [];
        }

        $base_url = $this->urlGenerator->admin();

        $tpl->assign([
            'U_ELEMENTS_PAGE' => $base_url . $this->urlService->getQueryStringDiff(['display', 'start']),
            'level_options'   => $this->htmlService->getPrivacyLevelOptions(),
            'ADMIN_PAGE_TITLE' => Lang::t('Batch Manager'),
            'PWG_TOKEN'       => $this->csrfService->getToken(),
        ]);

        $this->filterResolver->render($collection, $base_url, $this->catElementsId, $this->pageStart);

        $tpl->assign('page_data_json', json_encode([
            'str_are_you_sure' => Lang::t('Are you sure?'),
            'str_yes'          => Lang::t('Yes, delete'),
            'str_no'           => Lang::t('No, I have changed my mind'),
            'str_orphan'       => Lang::t('This photo is an orphan'),
            'str_meta_warning' => Lang::t('Warning ! Unsaved changes will be lost'),
            'str_meta_yes'     => Lang::t('I want to continue'),
            'str_title_ab'     => Lang::t('Associate to album'),
            'str_cancel'       => Lang::t('Cancel'),
        ], JSON_HEX_TAG | JSON_UNESCAPED_UNICODE));

        $tpl->assign('ACTIVE_PLUGINS', $activePluginIds);

        $catElementsIdU = $this->catElementsId;
        $pageStartU     = $this->pageStart;
        if (isset($_GET['display']) && $_GET['display'] !== '') {
            $nbImagesU = is_numeric($_GET['display']) ? (int) $_GET['display'] : 5;
        } elseif (in_array(Config::batchManagerImagesPerPageUnit(), [5, 10, 50])) {
            $nbImagesU = Config::batchManagerImagesPerPageUnit();
        } else {
            $nbImagesU = 5;
        }
        $tpl->assign('per_page', $nbImagesU);

        if (count($catElementsIdU) > 0) {
            $nav_bar = $this->paginationService->createNavigationBar($base_url . $this->urlService->getQueryStringDiff(['start']), count($catElementsIdU), $pageStartU, $nbImagesU);
            $tpl->assign(['navbar' => $nav_bar]);

            $element_ids      = [];
            /** @var array<string, mixed> $bmf */
            $bmf              = $this->session->bulkManagerFilter ?? [];
            $is_category      = isset($bmf['category']) && !isset($bmf['category_recursive']);
            $bmf_category_val = is_numeric($bmf['category'] ?? null) ? (int) $bmf['category'] : 0;

            if (is_string($bmf['prefilter'] ?? null) && 'duplicates' == $bmf['prefilter']) {
                Config::override('order_by', ' ORDER BY file, id');
            }

            if ($is_category) {
                $category_info = $this->categoryService->getCatInfo($bmf_category_val);
                Config::override('order_by', Config::orderByInsideCategory());
                if (isset($category_info['image_order']) && $category_info['image_order'] !== '') {
                    Config::override('order_by', ' ORDER BY ' . (is_string($category_info['image_order']) ? $category_info['image_order'] : ''));
                }
            }
            $unitImages = $this->imageRepository->findForBatchManager(
                array_map(static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $catElementsIdU),
                $is_category ? $bmf_category_val : null,
                $this->orderByService->buildOrderByClause(Config::orderBy()),
                $nbImagesU,
                $pageStartU,
            );

            $added_by_ids   = array_values(array_unique(array_map(static fn (Image $img): int => $img->addedBy !== null ? $img->addedBy->value : 0, $unitImages)));
            $added_by_username_of = [];
            if (count($added_by_ids) > 0) {
                $added_by_username_of = $this->userRepository->findIdToUsernameMapByIds(
                    Config::userFields()->id,
                    Config::userFields()->username,
                    Tables::users(),
                    $added_by_ids,
                );
            }

            $storage_category_id = null;

            foreach ($unitImages as $img) {
                $row = $img->toRow();
                $element_ids[] = is_scalar($row['id'] ?? null) ? (string) $row['id'] : '0';
                $src_image     = new SrcImage($row);
                $image_file    = $row['file'];
                $tag_selection = $this->tagAdminService->getTaglistFromRows(array_map(
                    static fn (\Piwigo\Tag\Projection\TagBrief $t): array => $t->toRow(),
                    $this->tagRepository->findTagsByImageId(is_numeric($row['id'] ?? null) ? (int) $row['id'] : 0),
                ));
                $legend        = $this->htmlService->renderElementName($row);
                $row_file_str  = is_scalar($row['file'] ?? null) ? (string) $row['file'] : '';
                if ($legend != StringUtil::getNameFromFile($row_file_str)) {
                    $legend .= ' (' . $row_file_str . ')';
                }
                $extTab        = explode('.', is_scalar($row['path'] ?? null) ? (string) $row['path'] : '');

                $related_categories   = [];
                $related_category_ids = [];
                $row_id_int           = is_numeric($row['id'] ?? null) ? (int) $row['id'] : 0;
                $media_image          = $this->imageAdminService->getImageInfos($row_id_int, true);

                foreach ($this->categoryRepository->findCategoryInfosByImageId($row_id_int) as $item) {
                    $name        = $this->htmlService->getCatDisplayNameCache($item->uppercats, $this->urlGenerator->admin() . '&page=album-');
                    $item_cat_id = $item->categoryId->value;
                    if ($item_cat_id == $storage_category_id) {
                        $tpl->assign('STORAGE_CATEGORY', $name);
                    }
                    $related_categories[$item_cat_id] = ['name' => $name, 'unlinkable' => $item_cat_id != $storage_category_id];
                    $related_category_ids[]           = $item_cat_id;
                }

                $row_id_str  = is_scalar($row['id'] ?? null) ? (string) $row['id'] : '0';
                $row_id_int  = is_numeric($row['id'] ?? null) ? (int) $row['id'] : 0;
                $userStatusForPerm = is_string($user['status']) ? (UserStatus::tryFrom($user['status']) ?? UserStatus::Guest) : UserStatus::Guest;
                $forbidden   = $this->permissionService->calculatePermissions(is_numeric($user['id']) ? (int) $user['id'] : 0, $userStatusForPerm);
                $authorizeds = array_values(array_diff(
                    $this->categoryRepository->findCategoryIdsByImageId($row_id_int),
                    $forbidden,
                ));

                $catNamesRaw = RequestCache::remember('cat_names', 'all', fn (): array => $this->categoryRepository->findAllIdNamePermalinkMap());
                $catNames    = is_array($catNamesRaw) ? $catNamesRaw : [];
                $url_img     = null;
                if (isset($row['cat_id']) && is_numeric($row['cat_id']) && in_array((int) $row['cat_id'], $authorizeds, true)) {
                    $catIdStr = (string) (int) $row['cat_id'];
                    $url_img  = $this->urlService->makePictureUrl(['image_id' => $row['id'], 'image_file' => $image_file, 'category' => $catNames[$catIdStr] ?? null]);
                } else {
                    foreach ($authorizeds as $category) {
                        $url_img = $this->urlService->makePictureUrl(['image_id' => $row['id'], 'image_file' => $image_file, 'category' => $catNames[(string) $category] ?? null]);
                        break;
                    }
                }

                $admin_photo_base_url = $this->urlGenerator->admin('photo-' . $row_id_str);
                $admin_url_start      = $admin_photo_base_url . '-properties';
                $admin_url_start     .= isset($row['cat_id']) ? '&cat_id=' . (is_scalar($row['cat_id']) ? (string) $row['cat_id'] : '') : '';
                $selected_level       = $row['level'] ?? null;

                $userLevel  = is_numeric($user['level'] ?? null) ? (int) $user['level'] : 0;
                $mediaLevelRaw = is_array($media_image) ? ($media_image['level'] ?? null) : null;
                $mediaLevel    = is_numeric($mediaLevelRaw) ? (int) $mediaLevelRaw : 0;

                $tpl->append('elements', array_merge($row, [
                    'ID'                    => $row['id'],
                    'TN_SRC'                => DerivativeImage::url(DerivativeSize::Medium->value, $src_image),
                    'FILE_SRC'              => DerivativeImage::url(DerivativeSize::Large->value, $src_image),
                    'LEGEND'                => $legend,
                    'U_EDIT'                => $this->urlGenerator->admin('photo-' . $row_id_str),
                    'NAME'                  => htmlspecialchars(is_string($row['name'] ?? null) ? $row['name'] : ''),
                    'AUTHOR'                => htmlspecialchars(is_string($row['author'] ?? null) ? $row['author'] : ''),
                    'LEVEL'                 => (isset($row['level']) && $row['level'] !== '' && $row['level'] !== 0) ? $row['level'] : '0',
                    'DESCRIPTION'           => htmlspecialchars(is_string($row['comment'] ?? null) ? $row['comment'] : ''),
                    'DATE_CREATION'         => $row['date_creation'],
                    'TAGS'                  => $tag_selection,
                    'is_svg'                => (strtoupper(end($extTab)) == 'SVG'),
                    'TITLE'                 => $this->htmlService->renderElementName($row),
                    'DIMENSIONS'            => (is_string($row['width'] ?? null) ? $row['width'] : '') . 'x' . (is_string($row['height'] ?? null) ? $row['height'] : '') . ' px',
                    'FORMAT'                => ($row['width'] >= $row['height']) ? 1 : 0,
                    'FILESIZE'              => Lang::t('%.2f MB', (is_numeric($row['filesize']) ? (float) $row['filesize'] : 0.0) / 1024.0),
                    'REGISTRATION_DATE'     => $this->dateService->formatDate(is_string($row['date_available']) ? $row['date_available'] : (is_int($row['date_available']) ? $row['date_available'] : null)),
                    'EXT'                   => Lang::t('%s file type', end($extTab)),
                    'POST_DATE'             => Lang::t('Added on %s', $this->dateService->formatDate(is_string($row['date_available']) ? $row['date_available'] : (is_int($row['date_available']) ? $row['date_available'] : null), ['day', 'month', 'year'])),
                    'AGE'                   => Lang::t(ucfirst($this->dateService->timeSince(is_string($row['date_available']) ? $row['date_available'] : (is_int($row['date_available']) ? $row['date_available'] : null), 'year'))),
                    'ADDED_BY'              => Lang::t('Added by %s', is_string($added_by_username_of[is_string($row['added_by'] ?? null) ? $row['added_by'] : ''] ?? null) ? $added_by_username_of[is_string($row['added_by'] ?? null) ? $row['added_by'] : ''] : Lang::t('N/A')),
                    'STATS'                 => Lang::t('Visited %d times', is_numeric($row['hit']) ? (int) $row['hit'] : 0),
                    'FILE'                  => Lang::t('%s', is_string($row['file']) ? $row['file'] : ''),
                    'related_categories'    => $related_categories,
                    'related_category_ids'  => json_encode($related_category_ids),
                    'U_JUMPTO'              => (isset($url_img) && $userLevel >= $mediaLevel) ? $url_img : null,
                    'tag_selection'         => $tag_selection,
                    'U_DOWNLOAD'            => $this->urlGenerator->actionDownload((int) $row_id_str, 'e', $this->csrfService->getToken()),
                    'U_HISTORY'             => $this->urlGenerator->admin('history') . '&filter_image_id=' . $row_id_str,
                    'U_ACTIVITY'            => $this->urlGenerator->admin('user_activity') . '&photo=' . $row_id_str,
                    'U_DELETE'              => $admin_url_start . '&delete=1&pwg_token=' . $this->csrfService->getToken(),
                    'U_SYNC'                => $admin_url_start . '&sync_metadata=1',
                    'PATH'                  => $row['path'],
                    'level_options_selected' => [$selected_level],
                ]));
            }

            $tpl->assign(['ELEMENT_IDS' => implode(',', $element_ids)]);
        }

        $cache_keys = $this->adminService->getAdminClientCacheKeys(['tags', 'categories']);
        $tpl->assign([
            'CACHE_KEYS'                          => $cache_keys,
            'batch_manager_unit_page_data_json'   => json_encode([
                'CACHE_KEYS'            => $cache_keys,
                'ROOT_URL'              => UrlService::getRootUrl(),
                'associated_categories' => $associated_categories,
                'str_create'            => Lang::t('Create'),
                'active_plugins'        => $activePluginIds,
            ], JSON_HEX_TAG | JSON_UNESCAPED_UNICODE),
        ]);

        $this->dispatcher->dispatch(new LocEndElementSetUnit());
        $tpl->assignVarFromTemplate('ADMIN_CONTENT', 'batch_manager_unit.latte');
    }

    // ── queue ─────────────────────────────────────────────────────────────────

    private function queue(): void
    {
        $tpl = TemplateRegistry::current();

        $this->imageAdminService->fsQuickCheck();

        $action = is_string($_GET['action'] ?? null) ? $_GET['action'] : '';

        $getIdRaw = $_GET['id'] ?? null;
        if ($action === 'retry' && is_numeric($getIdRaw)) {
            $failedId = (int) $getIdRaw;
            $row      = $this->messengerRepository->findFailedJobById($failedId);
            if ($row !== null) {
                $this->messengerRepository->requeueFailed($failedId);
                PageState::current()->addInfo('Job moved back to async queue.');
            }
            $this->redirectResponder->redirect($this->urlGenerator->admin('queue'));
        }

        if ($action === 'purge_failed') {
            $this->csrfService->check();
            $this->messengerRepository->purgeFailed();
            PageState::current()->addInfo('Failed queue purged.');
            $this->redirectResponder->redirect($this->urlGenerator->admin('queue'));
        }

        $stats       = [];
        $failedJobs  = [];
        $tableExists = false;

        try {
            $stats       = $this->messengerRepository->countPendingByQueueName();
            $tableExists = true;
            $failedJobs  = $this->messengerRepository->findFailedJobs();
        } catch (\Throwable) {
            $tableExists = false;
        }


        $pwg_token     = $this->csrfService->getToken();
        $pendingAsync  = $stats['piwigo_async'] ?? 0;
        $pendingFailed = $stats['piwigo_failed'] ?? 0;

        $failedJobsForTpl = array_map(function (array $row): array {
            /** @var array<string, mixed> $body */
            $body  = json_decode(is_string($row['body']) ? $row['body'] : '{}', true) ?? [];
            $class = is_string($bodyType = $body['type'] ?? null) ? basename(str_replace('\\', '/', $bodyType)) : 'Unknown';
            return ['id' => is_numeric($row['id']) ? (int) $row['id'] : 0, 'class' => $class, 'created_at' => is_string($row['created_at']) ? $row['created_at'] : '', 'U_RETRY' => $this->urlGenerator->admin('queue') . '&action=retry&id=' . (is_numeric($row['id']) ? (int) $row['id'] : 0)];
        }, $failedJobs);

        $tpl->assign([
            'table_exists'   => $tableExists,
            'pending_async'  => $pendingAsync,
            'pending_failed' => $pendingFailed,
            'failed_jobs'    => $failedJobsForTpl,
            'U_PURGE_FAILED' => $this->urlGenerator->admin('queue') . '&action=purge_failed&pwg_token=' . $pwg_token,
            'worker_command' => 'bin/piwigo messenger:consume async --time-limit=3600 --memory-limit=256M',
        ]);

        $tpl->assignVarFromTemplate('ADMIN_CONTENT', 'queue.latte');
    }
}
