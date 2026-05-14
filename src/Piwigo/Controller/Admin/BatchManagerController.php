<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin;

use Doctrine\DBAL\Connection;
use Latte\Runtime\Html;
use Piwigo\Admin\AdminService;
use Piwigo\Admin\BatchManager\FilterResolver;
use Piwigo\Admin\Category\CategoryAdminService;
use Piwigo\Admin\Image\ImageAdminService;
use Piwigo\Admin\Tabsheet;
use Piwigo\Admin\Tag\TagAdminService;
use Piwigo\Admin\Users\UserAdminService;
use Piwigo\Cache\RequestCache;
use Piwigo\Category\CategoryRepository;
use Piwigo\Category\CategoryService;
use Piwigo\Config\Config;
use Piwigo\Core\DateService;
use Piwigo\Core\Lang;
use Piwigo\Core\PageState;
use Piwigo\Core\StringUtil;
use Piwigo\Core\Util;
use Piwigo\Core\ValidationPattern;
use Piwigo\Db\Dml;
use Piwigo\Db\SqlExpr;
use Piwigo\Db\Tables;
use Piwigo\Html\HtmlService;
use Piwigo\Image\DerivativeImage;
use Piwigo\Image\DerivativeSize;
use Piwigo\Image\ImageRepository;
use Piwigo\Image\ImageStdParams;
use Piwigo\Image\SrcImage;
use Piwigo\Lang\Translator;
use Piwigo\Plugins\EventDispatcher;
use Piwigo\Search\SearchService;
use Piwigo\Site\LocalSiteReader;
use Piwigo\Tag\TagRepository;
use Piwigo\Tag\TagService;
use Piwigo\Template\TemplateRegistry;
use Piwigo\Url\UrlGenerator;
use Piwigo\Url\UrlService;
use Piwigo\Users\PermissionService;

final readonly class BatchManagerController
{
    /** @var list<string> */
    public const array PAGES = [
        'batch_manager',
        'batch_manager_global',
        'batch_manager_unit',
        'queue',
    ];

    public function __construct(
        private Connection $conn,
        private AdminService $adminService,
        private CategoryAdminService $categoryAdminService,
        private CategoryRepository $categoryRepository,
        private CategoryService $categoryService,
        private DateService $dateService,
        private FilterResolver $filterResolver,
        private HtmlService $htmlService,
        private ImageAdminService $imageAdminService,
        private ImageRepository $imageRepository,
        private PermissionService $permissionService,
        private SearchService $searchService,
        private StringUtil $stringUtil,
        private TagAdminService $tagAdminService,
        private TagRepository $tagRepository,
        private TagService $tagService,
        private UrlGenerator $urlGenerator,
        private UrlService $urlService,
        private UserAdminService $userAdminService,
        private Util $util,
    ) {
    }

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
        /** @var array<string, mixed> $page */
        $page = &$GLOBALS['page'];
        /** @var array<string, mixed> $user */
        $user = $GLOBALS['user'];
        $this->util->checkInputParameter('selection', $_POST, true, ValidationPattern::ID);
        $this->util->checkInputParameter('display', $_REQUEST, false, '/^(\d+|all)$/');

        // ── Specific actions ──────────────────────────────────────────────────

        if (isset($_GET['action'])) {
            if ('empty_caddie' == $_GET['action']) {
                $this->imageRepository->deleteUserCaddie(is_numeric($user['id']) ? (int) $user['id'] : 0);
                $_SESSION['page_infos'] = [Lang::t('Information data registered in database')];
                $this->util->redirect($this->urlGenerator->admin() . '&page=' . (is_string($rawPage = $_GET['page'] ?? null) ? $rawPage : ''));
            }

            if ('delete_orphans' == $_GET['action'] && isset($_GET['nb_orphans_deleted'])) {
                $this->util->checkInputParameter('nb_orphans_deleted', $_GET, false, '/^\d+$/');
                $nb_orphans_deleted = is_numeric($_GET['nb_orphans_deleted']) ? (int) $_GET['nb_orphans_deleted'] : 0;
                if ($nb_orphans_deleted > 0) {
                    if (!is_array($_SESSION['page_infos'] ?? null)) {
                        $_SESSION['page_infos'] = [];
                    }
                    /** @var array<mixed> $page_infos_ref */
                    $page_infos_ref   = &$_SESSION['page_infos'];
                    $page_infos_ref[] = Translator::get()->plural('%d photo was deleted', '%d photos were deleted', $nb_orphans_deleted);
                    $getPage = $_GET['page'] ?? null;
                    $this->util->redirect($this->urlGenerator->admin() . '&page=' . (is_string($getPage) ? $getPage : ''));
                }
            }

            if ('sync_md5sum' == $_GET['action'] && isset($_GET['nb_md5sum_added'])) {
                $this->util->checkInputParameter('nb_md5sum_added', $_GET, false, '/^\d+$/');
                $nb_md5sum_added = is_numeric($_GET['nb_md5sum_added']) ? (int) $_GET['nb_md5sum_added'] : 0;
                if ($nb_md5sum_added > 0) {
                    if (!is_array($_SESSION['page_infos'] ?? null)) {
                        $_SESSION['page_infos'] = [];
                    }
                    /** @var array<mixed> $page_infos_ref */
                    $page_infos_ref   = &$_SESSION['page_infos'];
                    $page_infos_ref[] = Translator::get()->plural('%d checksums were added', '%d checksums were added', $nb_md5sum_added);
                    $getPage2 = $_GET['page'] ?? null;
                    $this->util->redirect($this->urlGenerator->admin() . '&page=' . (is_string($getPage2) ? $getPage2 : ''));
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
                    if (isset($_POST['filter_duplicates_checksum'])) {
                        $bmf['duplicates_checksum']  = true;
                        $has_options = true;
                    }
                    if (isset($_POST['filter_duplicates_date'])) {
                        $bmf['duplicates_date']       = true;
                        $has_options = true;
                    }
                    if (isset($_POST['filter_duplicates_dimensions'])) {
                        $bmf['duplicates_dimensions'] = true;
                        $has_options = true;
                    }
                    if (!$has_options || isset($_POST['filter_duplicates_filename'])) {
                        $bmf['duplicates_filename'] = true;
                    }
                }
            }

            if (isset($_POST['filter_category_use'])) {
                $this->util->checkInputParameter('filter_category', $_POST, false, ValidationPattern::ID);
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
                $this->util->checkInputParameter('filter_level', $_POST, false, '/^\d+$/');
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

            $_SESSION['bulk_manager_filter'] = EventDispatcher::dispatch('batch_manager_register_filters', $bmf);
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
                            if (in_array($duplicate_field, ['filename', 'checksum', 'date', 'dimensions'])) {
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
                            if (isset($dim_map[$part[0]])) {
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
                        $bmf = EventDispatcher::dispatch('batch_manager_url_filter', $bmf, $filter);
                        break;
                }
            }

            $_SESSION['bulk_manager_filter'] = $bmf;
        }

        if (empty($_SESSION['bulk_manager_filter'])) {
            $_SESSION['bulk_manager_filter'] = ['prefilter' => 'caddie'];
        }

        /** @var array<string, mixed> $bmf */
        $bmf = is_array($_SESSION['bulk_manager_filter']) ? $_SESSION['bulk_manager_filter'] : [];

        // ── Build photo set from filters ──────────────────────────────────────

        $filter_sets     = [];
        $bmf_prefilter   = is_string($bmf['prefilter'] ?? null) ? $bmf['prefilter'] : '';
        if ($bmf_prefilter !== '') {
            switch ($bmf_prefilter) {
                case 'caddie':
                    $userId        = is_numeric($user['id']) ? (int) $user['id'] : 0;
                    $filter_sets[] = array_column($this->conn->executeQuery('SELECT element_id FROM ' . Tables::caddie() . ' WHERE user_id = ' . $userId . ';')->fetchAllAssociative(), 'element_id');
                    break;
                case 'favorites':
                    $userId2       = is_numeric($user['id']) ? (int) $user['id'] : 0;
                    $filter_sets[] = array_column($this->conn->executeQuery('SELECT image_id FROM ' . Tables::favorites() . ' WHERE user_id = ' . $userId2 . ';')->fetchAllAssociative(), 'image_id');
                    break;
                case 'last_import':
                    $last_import_date = $this->imageRepository->findMaxDateAvailable();
                    if ($last_import_date !== null && $last_import_date !== '') {
                        $filter_sets[] = array_column($this->conn->executeQuery('SELECT id FROM ' . Tables::images() . ' WHERE date_available BETWEEN ' . SqlExpr::recentPeriodExpr(1, $last_import_date) . ' AND \'' . $last_import_date . '\';')->fetchAllAssociative(), 'id');
                    }
                    break;
                case 'no_virtual_album':
                    $all_elements    = array_column($this->conn->executeQuery('SELECT id FROM ' . Tables::images() . ';')->fetchAllAssociative(), 'id');
                    $linked_to_virtual = [];
                    $virtual_categories = array_column($this->conn->executeQuery('SELECT id FROM ' . Tables::categories() . ' WHERE dir IS NULL;')->fetchAllAssociative(), 'id');
                    if (!empty($virtual_categories)) {
                        $linked_to_virtual = array_column($this->conn->executeQuery('SELECT DISTINCT(image_id) FROM ' . Tables::imageCategory() . ' WHERE category_id IN (' . implode(',', array_map(fn (mixed $v): string => is_scalar($v) ? (string) $v : '0', $virtual_categories)) . ');')->fetchAllAssociative(), 'image_id');
                    }
                    $filter_sets[] = array_diff(array_map(fn (mixed $v): string => is_scalar($v) ? (string) $v : '0', $all_elements), array_map(fn (mixed $v): string => is_scalar($v) ? (string) $v : '0', $linked_to_virtual));
                    break;
                case 'no_album':
                    $filter_sets[] = $this->imageAdminService->getOrphans();
                    break;
                case 'no_sync_md5sum':
                    $filter_sets[] = $this->imageAdminService->getPhotosNoMd5sum();
                    break;
                case 'no_tag':
                    $filter_sets[] = array_column($this->conn->executeQuery('SELECT id FROM ' . Tables::images() . ' LEFT JOIN ' . Tables::imageTag() . ' ON id = image_id WHERE tag_id is null;')->fetchAllAssociative(), 'id');
                    break;
                case 'duplicates':
                    $duplicates_on_fields = [];
                    if (isset($bmf['duplicates_filename'])) {
                        $duplicates_on_fields[] = 'file';
                    }
                    if (isset($bmf['duplicates_checksum'])) {
                        $duplicates_on_fields[] = 'md5sum';
                    }
                    if (isset($bmf['duplicates_date'])) {
                        $duplicates_on_fields[] = 'date_creation';
                    }
                    if (isset($bmf['duplicates_dimensions'])) {
                        $duplicates_on_fields[] = 'width';
                        $duplicates_on_fields[] = 'height';
                    }
                    $query = 'SELECT GROUP_CONCAT(id) AS ids FROM ' . Tables::images();
                    if (in_array('md5sum', $duplicates_on_fields)) {
                        $query .= ' WHERE md5sum IS NOT NULL';
                    }
                    $query .= ' GROUP BY ' . implode(',', $duplicates_on_fields) . ' HAVING COUNT(*) > 1;';
                    $ids = [];
                    foreach (array_column($this->conn->executeQuery($query)->fetchAllAssociative(), 'ids') as $ids_string) {
                        $ids_string = rtrim(is_scalar($ids_string) ? (string) $ids_string : '', ',');
                        $ids = array_merge($ids, explode(',', $ids_string));
                    }
                    $filter_sets[] = $ids;
                    break;
                case 'all_photos':
                    if (count($bmf) == 1) {
                        $filter_sets[] = array_column($this->conn->executeQuery('SELECT id FROM ' . Tables::images() . ' ' . Config::orderBy())->fetchAllAssociative(), 'id');
                    }
                    break;
                default:
                    $filter_sets = EventDispatcher::dispatch('perform_batch_manager_prefilters', $filter_sets, $bmf_prefilter);
                    break;
            }
        }

        if (isset($bmf['category'])) {
            $bmf_category = is_numeric($bmf['category']) ? (int) $bmf['category'] : 0;
            if (!$this->categoryRepository->existsById($bmf_category)) {
                unset($_SESSION['bulk_manager_filter']);
                $this->util->redirect($this->urlGenerator->admin() . '&page=' . (is_string($rawPage = $_GET['page'] ?? null) ? $rawPage : ''));
            }
            $categories   = isset($bmf['category_recursive']) ? $this->categoryService->getSubcatIds([$bmf_category]) : [$bmf_category];
            $filter_sets[] = array_column($this->conn->executeQuery('SELECT DISTINCT(image_id) FROM ' . Tables::imageCategory() . ' WHERE category_id IN (' . implode(',', $categories) . ');')->fetchAllAssociative(), 'image_id');
        }

        if (isset($bmf['level'])) {
            $operator  = isset($bmf['level_include_lower']) ? '<=' : '=';
            $bmf_level = is_numeric($bmf['level']) ? (int) $bmf['level'] : 0;
            $filter_sets[] = array_column($this->conn->executeQuery('SELECT id FROM ' . Tables::images() . ' WHERE level ' . $operator . ' ' . $bmf_level . ' ' . Config::orderBy())->fetchAllAssociative(), 'id');
        }

        if (!empty($bmf['tags'])) {
            $bmf_tags     = array_map(static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, is_array($bmf['tags']) ? $bmf['tags'] : []);
            $bmfTagMode = $bmf['tag_mode'] ?? null;
            $bmf_tag_mode = is_string($bmfTagMode) ? $bmfTagMode : 'AND';
            $filter_sets[] = $this->tagService->getImageIdsForTags($bmf_tags, $bmf_tag_mode, null, null, false);
        }

        if (isset($bmf['dimension'])) {
            $bmf_dimension = is_array($bmf['dimension']) ? $bmf['dimension'] : [];
            $where_clause  = [];
            if (isset($bmf_dimension['min_width'])) {
                $where_clause[] = 'width >= '  . (is_string($bmf_dimension['min_width']) ? $bmf_dimension['min_width'] : '0');
            }
            if (isset($bmf_dimension['max_width'])) {
                $where_clause[] = 'width <= '  . (is_string($bmf_dimension['max_width']) ? $bmf_dimension['max_width'] : '0');
            }
            if (isset($bmf_dimension['min_height'])) {
                $where_clause[] = 'height >= ' . (is_string($bmf_dimension['min_height']) ? $bmf_dimension['min_height'] : '0');
            }
            if (isset($bmf_dimension['max_height'])) {
                $where_clause[] = 'height <= ' . (is_string($bmf_dimension['max_height']) ? $bmf_dimension['max_height'] : '0');
            }
            if (isset($bmf_dimension['min_ratio'])) {
                $where_clause[] = 'width/height >= ' . (is_string($bmf_dimension['min_ratio']) ? $bmf_dimension['min_ratio'] : '0');
            }
            if (isset($bmf_dimension['max_ratio'])) {
                $max_ratio    = is_numeric($bmf_dimension['max_ratio']) ? (float) $bmf_dimension['max_ratio'] : 0.0;
                $where_clause[] = 'width/height < ' . number_format($max_ratio + 0.01, 4, '.', '');
            }
            if (!empty($where_clause)) {
                $filter_sets[] = array_column($this->conn->executeQuery('SELECT id FROM ' . Tables::images() . ' WHERE ' . implode(' AND ', $where_clause) . ' ' . Config::orderBy())->fetchAllAssociative(), 'id');
            }
        }

        if (isset($bmf['filesize'])) {
            $bmf_filesize = is_array($bmf['filesize']) ? $bmf['filesize'] : [];
            $where_clause = [];
            if (isset($bmf_filesize['min'])) {
                $fs_min = is_numeric($bmf_filesize['min']) ? (float) $bmf_filesize['min'] : 0.0;
                $where_clause[] = 'filesize >= ' . number_format(($fs_min - 0.1) * 1024.0, 4, '.', '');
            }
            if (isset($bmf_filesize['max'])) {
                $fs_max = is_numeric($bmf_filesize['max']) ? (float) $bmf_filesize['max'] : 0.0;
                $where_clause[] = 'filesize <= ' . number_format(($fs_max + 0.1) * 1024.0, 4, '.', '');
            }
            if (!empty($where_clause)) {
                $filter_sets[] = array_column($this->conn->executeQuery('SELECT id FROM ' . Tables::images() . ' WHERE ' . implode(' AND ', $where_clause) . ' ' . Config::orderBy())->fetchAllAssociative(), 'id');
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

        $filter_sets = EventDispatcher::dispatch('batch_manager_perform_filters', $filter_sets, $bmf);

        $current_set = array_shift($filter_sets);
        foreach ($filter_sets as $set) {
            $a = is_array($current_set) ? array_map(static fn (mixed $v): string => is_scalar($v) ? (string) $v : '0', $current_set) : [];
            $b = is_array($set) ? array_map(static fn (mixed $v): string => is_scalar($v) ? (string) $v : '0', $set) : [];
            $current_set = array_intersect($a, $b);
        }
        $page['cat_elements_id'] = empty($current_set) ? [] : $current_set;

        // ── Pagination ────────────────────────────────────────────────────────

        if (!isset($_REQUEST['start']) || !is_numeric($_REQUEST['start']) || $_REQUEST['start'] < 0 || (isset($_REQUEST['display']) && 'all' == $_REQUEST['display'])) {
            $page['start'] = 0;
        } else {
            $page['start'] = (int) $_REQUEST['start'];
        }

        // ── Tabs ──────────────────────────────────────────────────────────────

        $GLOBALS['manager_link'] = $manager_link = $this->urlGenerator->admin('batch_manager') . '&mode=';

        if (isset($_GET['mode'])) {
            $this->util->checkInputParameter('mode', $_GET, false, '/^(global|unit)$/');
            $page['tab'] = is_string($_GET['mode']) ? $_GET['mode'] : 'global';
        } else {
            $page['tab'] = 'global';
        }

        $tabsheet = new Tabsheet();
        $tabsheet->setId('batch_manager');
        $tabsheet->select($page['tab']);
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

        $tab = $page['tab'];
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
        /** @var array<string, mixed> $page */
        $page = &$GLOBALS['page'];
        /** @var array<string, mixed> $user */
        $user = $GLOBALS['user'];
        $duplicates_on_fields = [];
        $associated_categories = [];
        if (!empty($_POST)) {
            $this->util->checkPwgToken();
        }

        EventDispatcher::notify('loc_begin_element_set_global');

        $this->util->checkInputParameter('del_tags', $_POST, true, ValidationPattern::ID);
        $this->util->checkInputParameter('associate', $_POST, true, ValidationPattern::ID);
        $this->util->checkInputParameter('move', $_POST, false, ValidationPattern::ID);
        $this->util->checkInputParameter('dissociate', $_POST, false, ValidationPattern::ID);

        $collection = [];
        if (isset($_POST['nb_photos_deleted'])) {
            $this->util->checkInputParameter('nb_photos_deleted', $_POST, false, '/^\d+$/');
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

        $page['prefilter'] = 'none';
        /** @var array<string, mixed> $bmf */
        $bmf = is_array($_SESSION['bulk_manager_filter'] ?? null) ? $_SESSION['bulk_manager_filter'] : [];
        if (is_string($bmf['prefilter'] ?? null)) {
            $page['prefilter'] = $bmf['prefilter'];
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
                $this->imageRepository->deleteUserCaddieByImageIds(is_numeric($user['id']) ? (int) $user['id'] : 0, $collection_int);
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
                    if ('no_tag' == $page['prefilter']) {
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
                    $_SESSION['page_infos'] = [Lang::t('Information data registered in database')];
                    if ('no_album' == $page['prefilter']) {
                        $redirect = true;
                    } elseif ('no_virtual_album' == $page['prefilter']) {
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
                $_SESSION['page_infos'] = [Lang::t('Information data registered in database')];
                if ('no_album' == $page['prefilter']) {
                    $redirect = true;
                } elseif ('no_virtual_album' == $page['prefilter']) {
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
                    $_SESSION['page_infos'] = [Lang::t('Information data registered in database')];
                    $redirect = true;
                }
            } elseif ('author' == $action) {
                $authorValue = isset($_POST['remove_author']) ? null : ($_POST['author'] ?? null);
                $datas = [];
                foreach ($collection_int as $image_id) {
                    $datas[] = ['id' => $image_id, 'author' => $authorValue];
                }
                Dml::massUpdates(Tables::images(), ['primary' => ['id'], 'update' => ['author']], $datas);
                $this->util->pwgActivity('photo', $collection_int, 'edit', ['action' => 'author']);
            } elseif ('title' == $action) {
                $titleValue = isset($_POST['remove_title']) ? null : ($_POST['title'] ?? null);
                $datas = [];
                foreach ($collection_int as $image_id) {
                    $datas[] = ['id' => $image_id, 'name' => $titleValue];
                }
                Dml::massUpdates(Tables::images(), ['primary' => ['id'], 'update' => ['name']], $datas);
                $this->util->pwgActivity('photo', $collection_int, 'edit', ['action' => 'title']);
            } elseif ('date_creation' == $action) {
                $date_creation = (isset($_POST['remove_date_creation']) || !isset($_POST['date_creation']) || $_POST['date_creation'] === '') ? null : $_POST['date_creation'];
                $datas = [];
                foreach ($collection_int as $image_id) {
                    $datas[] = ['id' => $image_id, 'date_creation' => $date_creation];
                }
                Dml::massUpdates(Tables::images(), ['primary' => ['id'], 'update' => ['date_creation']], $datas);
                $this->util->pwgActivity('photo', $collection_int, 'edit', ['action' => 'date_creation']);
            } elseif ('level' == $action) {
                $levelValue = $_POST['level'] ?? null;
                $datas = [];
                foreach ($collection_int as $image_id) {
                    $datas[] = ['id' => $image_id, 'level' => $levelValue];
                }
                Dml::massUpdates(Tables::images(), ['primary' => ['id'], 'update' => ['level']], $datas);
                $this->util->pwgActivity('photo', $collection_int, 'edit', ['action' => 'privacy_level']);
                if (isset($bmf['level'])) {
                    $bmf_level_val  = is_numeric($bmf['level']) ? (int) $bmf['level'] : 0;
                    $postLevelRaw = $_POST['level'] ?? null;
                    $post_level_val = is_numeric($postLevelRaw) ? (int) $postLevelRaw : 0;
                    if ($post_level_val < $bmf_level_val) {
                        $redirect = true;
                    }
                }
            } elseif ('add_to_caddie' == $action) {
                $this->util->fillCaddie($collection_int);
            } elseif ('delete' == $action) {
                if (isset($_POST['confirm_deletion']) && 1 == $_POST['confirm_deletion']) {
                    if (count($collection_int) > 0) {
                        if (!is_array($_SESSION['page_infos'] ?? null)) {
                            $_SESSION['page_infos'] = [];
                        }
                        /** @var array<mixed> $page_infos_ref */
                        $page_infos_ref   = &$_SESSION['page_infos'];
                        $page_infos_ref[] = Translator::get()->plural('%d photo was deleted', '%d photos were deleted', count($collection_int));
                        $redirect_url     = $this->urlGenerator->admin() . '&page=' . (is_string($rawPage = $_GET['page'] ?? null) ? $rawPage : '');
                        $redirect         = true;
                    } else {
                        PageState::current()->addError(Lang::t('No photo can be deleted'));
                    }
                } else {
                    PageState::current()->addError(Lang::t('You need to confirm deletion'));
                }
            } elseif ('metadata' == $action) {
                PageState::current()->addInfo(new Html(Lang::t('Metadata synchronized from file') . ' <span class="badge">' . count($collection_int) . '</span>'));
            } elseif ('delete_derivatives' == $action && isset($_POST['del_derivatives_type']) && $_POST['del_derivatives_type'] !== '') {
                foreach ($this->imageRepository->findPathsAndRepresentativesByIds($collection_int) as $info) {
                    $del_types = is_array($_POST['del_derivatives_type']) ? $_POST['del_derivatives_type'] : [];
                    foreach ($del_types as $dtype) {
                        $this->imageAdminService->deleteElementDerivatives($info, is_string($dtype) ? $dtype : '');
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

            EventDispatcher::notify('element_set_global_action', $action, $collection_int);
            if ($redirect) {
                $this->util->redirect($redirect_url);
            }
        }

        // ── Template ──────────────────────────────────────────────────────────

        $base_url = $this->urlGenerator->admin();

        $this->filterResolver->render($collection, $base_url);

        $catElementsId = is_array($page['cat_elements_id']) ? $page['cat_elements_id'] : [];
        $pageStart     = is_int($page['start']) ? $page['start'] : 0;

        $tpl->assign('IN_CADDIE', 'caddie' == $page['prefilter']);

        if (count($catElementsId) > 0) {
            $tpl->assign('associated_tags', $this->tagService->getCommonTags($catElementsId, -1));
        }

        $rawDateCreationPost = $_POST['date_creation'] ?? null;
        $dateCreationAssign  = (!isset($_POST['date_creation']) || $_POST['date_creation'] === '') ? date('Y-m-d') . ' 00:00:00' : (is_string($rawDateCreationPost) ? $rawDateCreationPost : '');
        $tpl->assign('DATE_CREATION', $dateCreationAssign);
        $tpl->assign(['level_options' => $this->util->getPrivacyLevelOptions(), 'level_options_selected' => 0]);

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
        $page['nb_images'] = $nbImages;

        $nb_thumbs_page = 0;

        if (count($catElementsId) > 0) {
            $nav_bar = $this->util->createNavigationBar($base_url . $this->urlService->getQueryStringDiff(['start']), count($catElementsId), $pageStart, $nbImages);
            $tpl->assign('navbar', $nav_bar);

            $is_category      = isset($bmf['category']) && !isset($bmf['category_recursive']);
            $bmf_category_val = is_numeric($bmf['category'] ?? null) ? (int) $bmf['category'] : 0;

            $query = 'SELECT id,path,representative_ext,file,filesize,level,name,width,height,rotation FROM ' . Tables::images();
            if ($is_category) {
                $category_info = $this->categoryService->getCatInfo($bmf_category_val);
                Config::override('order_by', Config::orderByInsideCategory());
                if (isset($category_info['image_order']) && $category_info['image_order'] !== '') {
                    Config::override('order_by', ' ORDER BY ' . (is_string($category_info['image_order']) ? $category_info['image_order'] : ''));
                }
                $query .= ' JOIN ' . Tables::imageCategory() . ' ON id = image_id';
            }

            $query .= ' WHERE id IN (' . implode(',', array_map(fn (mixed $v): string => is_scalar($v) ? (string) $v : '0', $catElementsId)) . ')';
            if ($is_category) {
                $query .= ' AND category_id = ' . $bmf_category_val;
            }
            $query .= ' ' . Config::orderBy() . ' LIMIT ' . $nbImages . ' OFFSET ' . $pageStart . ';';

            $batchRows   = $this->conn->executeQuery($query)->fetchAllAssociative();
            $thumb_params = ImageStdParams::getByType(DerivativeSize::Square->value);

            foreach ($batchRows as $row) {
                $nb_thumbs_page++;
                $src_image   = new SrcImage($row);
                $ttitle      = $this->htmlService->renderElementName($row);
                $row_file    = is_scalar($row['file'] ?? null) ? (string) $row['file'] : '';
                if ($ttitle != $this->stringUtil->getNameFromFile($row_file)) {
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

        EventDispatcher::notify('loc_end_element_set_global');
        $tpl->assignVarFromTemplate('ADMIN_CONTENT', 'batch_manager_global.latte');
    }

    // ── batch_manager_unit ────────────────────────────────────────────────────

    private function batchManagerUnit(): void
    {
        $associated_categories = [];
        $tpl = TemplateRegistry::current();
        /** @var array<string, mixed> $page */
        $page = &$GLOBALS['page'];
        /** @var array<string, mixed> $user */
        $user = $GLOBALS['user'];
        /** @var array<string, mixed> $pwg_loaded_plugins */
        $pwg_loaded_plugins = is_array($GLOBALS['pwg_loaded_plugins'] ?? null) ? $GLOBALS['pwg_loaded_plugins'] : [];
        EventDispatcher::notify('loc_begin_element_set_unit');

        if (isset($_POST['submit'])) {
            $this->util->checkPwgToken();
            $this->util->checkInputParameter('element_ids', $_POST, false, '/^\d+(,\d+)*$/');
            $collection = explode(',', is_string($rawElementIds = $_POST['element_ids'] ?? null) ? $rawElementIds : '');

            $datas = [];
            foreach ($this->imageRepository->findByIds(array_map(intval(...), $collection)) as $row) {
                $data         = [];
                $row_id_raw   = $row['id'] ?? null;
                $row_id_str   = is_string($row_id_raw) ? $row_id_raw : '';
                $data['id']   = $row_id_raw;
                $data['name'] = $_POST['name-' . $row_id_str] ?? null;
                $data['author'] = $_POST['author-' . $row_id_str] ?? null;
                $data['level'] = $_POST['level-' . $row_id_str] ?? null;

                $desc_key = 'description-' . $row_id_str;
                $desc_val = $_POST[$desc_key] ?? null;
                $data['comment'] = Config::allowHtmlDescriptions() ? $desc_val : strip_tags(is_string($desc_val) ? $desc_val : '');

                $rawDateCreation = $_POST['date_creation-' . $row_id_str] ?? null;
                $data['date_creation'] = (is_string($rawDateCreation) && $rawDateCreation !== '') ? $rawDateCreation : null;

                $datas[] = $data;

                $tag_ids  = [];
                $tags_key = 'tags-' . $row_id_str;
                /** @var array<mixed>|string|null $tags_val */
                $tags_val = $_POST[$tags_key] ?? null;
                if ($tags_val !== null && $tags_val !== '') {
                    if (is_array($tags_val)) {
                        $tags_val = array_map(static fn (mixed $v): string => is_string($v) ? $v : '', $tags_val);
                    }
                    $tag_ids  = $this->tagAdminService->getTagIds($tags_val);
                }
                $this->tagAdminService->setTags($tag_ids, is_numeric($row['id']) ? (int) $row['id'] : 0);
            }

            Dml::massUpdates(Tables::images(), ['primary' => ['id'], 'update' => ['name', 'author', 'level', 'comment', 'date_creation']], $datas);
            PageState::current()->addInfo(Lang::t('Photo informations updated'));
            $this->userAdminService->invalidateUserCache();
        }

        $collection = [];
        if (isset($_POST['nb_photos_deleted'])) {
            $this->util->checkInputParameter('nb_photos_deleted', $_POST, false, '/^\d+$/');
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
            'level_options'   => $this->util->getPrivacyLevelOptions(),
            'ADMIN_PAGE_TITLE' => Lang::t('Batch Manager'),
            'PWG_TOKEN'       => $this->util->getPwgToken(),
        ]);

        $this->filterResolver->render($collection, $base_url);

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

        $tpl->assign('ACTIVE_PLUGINS', array_keys($pwg_loaded_plugins));

        $catElementsIdU = is_array($page['cat_elements_id']) ? $page['cat_elements_id'] : [];
        $pageStartU     = is_int($page['start']) ? $page['start'] : 0;
        if (isset($_GET['display']) && $_GET['display'] !== '') {
            $nbImagesU = is_numeric($_GET['display']) ? (int) $_GET['display'] : 5;
        } elseif (in_array(Config::batchManagerImagesPerPageUnit(), [5, 10, 50])) {
            $nbImagesU = Config::batchManagerImagesPerPageUnit();
        } else {
            $nbImagesU = 5;
        }
        $page['nb_images'] = $nbImagesU;
        $tpl->assign('per_page', $nbImagesU);

        if (count($catElementsIdU) > 0) {
            $nav_bar = $this->util->createNavigationBar($base_url . $this->urlService->getQueryStringDiff(['start']), count($catElementsIdU), $pageStartU, $nbImagesU);
            $tpl->assign(['navbar' => $nav_bar]);

            $element_ids      = [];
            /** @var array<string, mixed> $bmf */
            $bmf              = is_array($_SESSION['bulk_manager_filter'] ?? null) ? $_SESSION['bulk_manager_filter'] : [];
            $is_category      = isset($bmf['category']) && !isset($bmf['category_recursive']);
            $bmf_category_val = is_numeric($bmf['category'] ?? null) ? (int) $bmf['category'] : 0;

            if (is_string($bmf['prefilter'] ?? null) && 'duplicates' == $bmf['prefilter']) {
                Config::override('order_by', ' ORDER BY file, id');
            }

            $query = 'SELECT * FROM ' . Tables::images();
            if ($is_category) {
                $category_info = $this->categoryService->getCatInfo($bmf_category_val);
                Config::override('order_by', Config::orderByInsideCategory());
                if (isset($category_info['image_order']) && $category_info['image_order'] !== '') {
                    Config::override('order_by', ' ORDER BY ' . (is_string($category_info['image_order']) ? $category_info['image_order'] : ''));
                }
                $query .= ' JOIN ' . Tables::imageCategory() . ' ON id = image_id';
            }

            $query .= ' WHERE id IN (' . implode(',', array_map(fn (mixed $v): string => is_scalar($v) ? (string) $v : '0', $catElementsIdU)) . ')';
            if ($is_category) {
                $query .= ' AND category_id = ' . $bmf_category_val;
            }
            $query .= ' ' . Config::orderBy() . ' LIMIT ' . $nbImagesU . ' OFFSET ' . $pageStartU . ';';

            $images         = $this->conn->executeQuery($query)->fetchAllAssociative();
            $added_by_ids   = array_unique(array_map(fn (mixed $v): string => is_scalar($v) ? (string) $v : '0', array_column($images, 'added_by')));
            $added_by_username_of = [];
            if (count($added_by_ids) > 0) {
                $added_by_username_of = array_column($this->conn->executeQuery('SELECT ' . Config::userFields()['username'] . ' AS username, ' . Config::userFields()['id'] . ' AS id FROM ' . Tables::users() . ' WHERE ' . Config::userFields()['id'] . ' IN (' . implode(',', $added_by_ids) . ');')->fetchAllAssociative(), 'username', 'id');
            }

            $storage_category_id = null;

            foreach ($images as $row) {
                $element_ids[] = is_scalar($row['id'] ?? null) ? (string) $row['id'] : '0';
                $src_image     = new SrcImage($row);
                $image_file    = $row['file'];
                $tag_selection = $this->tagAdminService->getTaglistFromRows($this->tagRepository->findTagsByImageId(is_numeric($row['id'] ?? null) ? (int) $row['id'] : 0));
                $legend        = $this->htmlService->renderElementName($row);
                $row_file_str  = is_scalar($row['file'] ?? null) ? (string) $row['file'] : '';
                if ($legend != $this->stringUtil->getNameFromFile($row_file_str)) {
                    $legend .= ' (' . $row_file_str . ')';
                }
                $extTab        = explode('.', is_scalar($row['path'] ?? null) ? (string) $row['path'] : '');

                $related_categories   = [];
                $related_category_ids = [];
                $row_id_int           = is_numeric($row['id'] ?? null) ? (int) $row['id'] : 0;
                $media_image          = $this->imageAdminService->getImageInfos($row_id_int, true);

                foreach ($this->categoryRepository->findCategoryInfosByImageId($row_id_int) as $item) {
                    $item_uppercats = is_scalar($item['uppercats'] ?? null) ? (string) $item['uppercats'] : '';
                    $name = $this->htmlService->getCatDisplayNameCache($item_uppercats, $this->urlGenerator->admin() . '&page=album-');
                    if ($item['category_id'] == $storage_category_id) {
                        $tpl->assign('STORAGE_CATEGORY', $name);
                    }
                    $item_cat_id = is_numeric($item['category_id'] ?? null) ? (int) $item['category_id'] : 0;
                    $related_categories[$item_cat_id] = ['name' => $name, 'unlinkable' => $item_cat_id != $storage_category_id];
                    $related_category_ids[] = $item_cat_id;
                }

                $row_id_str = is_scalar($row['id'] ?? null) ? (string) $row['id'] : '0';
                $authorizeds = array_diff(
                    array_map(fn (mixed $v): string => is_scalar($v) ? (string) $v : '0', array_column($this->conn->executeQuery('SELECT category_id FROM ' . Tables::imageCategory() . ' WHERE image_id = ' . $row_id_str . ';')->fetchAllAssociative(), 'category_id')),
                    explode(',', $this->permissionService->calculatePermissions(is_numeric($user['id']) ? (int) $user['id'] : 0, is_string($user['status']) ? $user['status'] : ''))
                );

                $catNames = RequestCache::remember('cat_names', 'all', fn (): array => array_column($this->conn->executeQuery('SELECT id, name, permalink FROM ' . Tables::categories() . ';')->fetchAllAssociative(), null, 'id') ?: []);
                $url_img  = null;
                if (isset($row['cat_id']) && in_array($row['cat_id'], $authorizeds)) {
                    $url_img = $this->urlService->makePictureUrl(['image_id' => $row['id'], 'image_file' => $image_file, 'category' => (is_array($catNames) && (is_int($row['cat_id']) || is_string($row['cat_id']))) ? ($catNames[$row['cat_id']] ?? null) : null]);
                } else {
                    foreach ($authorizeds as $category) {
                        $url_img = $this->urlService->makePictureUrl(['image_id' => $row['id'], 'image_file' => $image_file, 'category' => is_array($catNames) ? ($catNames[$category] ?? null) : null]);
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
                    'U_DOWNLOAD'            => $this->urlGenerator->actionDownload((int) $row_id_str, 'e', $this->util->getPwgToken()),
                    'U_HISTORY'             => $this->urlGenerator->admin('history') . '&filter_image_id=' . $row_id_str,
                    'U_ACTIVITY'            => $this->urlGenerator->admin('user_activity') . '&photo=' . $row_id_str,
                    'U_DELETE'              => $admin_url_start . '&delete=1&pwg_token=' . $this->util->getPwgToken(),
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
                'active_plugins'        => array_keys($pwg_loaded_plugins),
            ], JSON_HEX_TAG | JSON_UNESCAPED_UNICODE),
        ]);

        EventDispatcher::notify('loc_end_element_set_unit');
        $tpl->assignVarFromTemplate('ADMIN_CONTENT', 'batch_manager_unit.latte');
    }

    // ── queue ─────────────────────────────────────────────────────────────────

    private function queue(): void
    {
        $tpl = TemplateRegistry::current();

        $this->imageAdminService->fsQuickCheck();

        $tableName = Config::dbPrefix() . 'messenger_messages';
        $conn      = $this->conn;

        $action = is_string($_GET['action'] ?? null) ? $_GET['action'] : '';

        $getIdRaw = $_GET['id'] ?? null;
        if ($action === 'retry' && is_numeric($getIdRaw)) {
            $failedId = (int) $getIdRaw;
            $row      = $conn->executeQuery('SELECT body, headers FROM ' . $tableName . ' WHERE id = ? AND queue_name = ?', [$failedId, 'piwigo_failed'])->fetchAssociative();
            if ($row !== false) {
                $conn->executeStatement('UPDATE ' . $tableName . ' SET queue_name = ?, available_at = NOW(), delivered_at = NULL WHERE id = ?', ['piwigo_async', $failedId]);
                PageState::current()->addInfo('Job moved back to async queue.');
            }
            $this->util->redirect($this->urlGenerator->admin('queue'));
        }

        if ($action === 'purge_failed') {
            $this->util->checkPwgToken();
            $conn->executeStatement('DELETE FROM ' . $tableName . ' WHERE queue_name = ?', ['piwigo_failed']);
            PageState::current()->addInfo('Failed queue purged.');
            $this->util->redirect($this->urlGenerator->admin('queue'));
        }

        $stats       = [];
        $failedJobs  = [];
        $tableExists = false;

        try {
            $rows = $conn->executeQuery('SELECT queue_name, COUNT(*) AS cnt FROM ' . $tableName . ' WHERE delivered_at IS NULL GROUP BY queue_name')->fetchAllAssociative();
            $tableExists = true;
            foreach ($rows as $row) {
                $queueName         = is_string($row['queue_name']) ? $row['queue_name'] : '';
                $stats[$queueName] = is_numeric($row['cnt']) ? (int) $row['cnt'] : 0;
            }
            $failedJobs = $conn->executeQuery('SELECT id, body, created_at, available_at FROM ' . $tableName . ' WHERE queue_name = ? ORDER BY id DESC LIMIT 50', ['piwigo_failed'])->fetchAllAssociative();
        } catch (\Throwable) {
            $tableExists = false;
        }


        $pwg_token     = $this->util->getPwgToken();
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
