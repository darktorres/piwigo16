<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin;

use Doctrine\DBAL\Connection;
use Piwigo\Admin\Tabsheet;
use Piwigo\Cache\RequestCache;
use Piwigo\Category\CategoryRepository;
use Piwigo\Config\Config;
use Piwigo\Core\PageState;
use Piwigo\Core\ServiceLocator;
use Piwigo\Db\SqlExpr;
use Piwigo\Image\DerivativeImage;
use Piwigo\Image\ImageRepository;
use Piwigo\Image\ImageStdParams;
use Piwigo\Image\SrcImage;
use Piwigo\Tag\TagRepository;
use Piwigo\Template\TemplateRegistry;
use Piwigo\Url\UrlGenerator;

final class BatchManagerController
{
    /** @var list<string> */
    public const array PAGES = [
        'batch_manager',
        'batch_manager_global',
        'batch_manager_unit',
        'queue',
    ];

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

        require_once PHPWG_ROOT_PATH . 'admin/include/functions.php';

        check_input_parameter('selection', $_POST, true, PATTERN_ID);
        check_input_parameter('display', $_REQUEST, false, '/^(\d+|all)$/');

        // ── Specific actions ──────────────────────────────────────────────────

        if (isset($_GET['action'])) {
            if ('empty_caddie' == $_GET['action']) {
                ServiceLocator::get(ImageRepository::class)->deleteUserCaddie(is_numeric($user['id']) ? (int) $user['id'] : 0);
                $_SESSION['page_infos'] = [l10n('Information data registered in database')];
                redirect(ServiceLocator::get(UrlGenerator::class)->admin() . '&page=' . (is_scalar($_GET['page']) ? (string) $_GET['page'] : ''));
            }

            if ('delete_orphans' == $_GET['action'] && isset($_GET['nb_orphans_deleted'])) {
                check_input_parameter('nb_orphans_deleted', $_GET, false, '/^\d+$/');
                $nb_orphans_deleted = is_numeric($_GET['nb_orphans_deleted']) ? (int) $_GET['nb_orphans_deleted'] : 0;
                if ($nb_orphans_deleted > 0) {
                    if (!is_array($_SESSION['page_infos'] ?? null)) {
                        $_SESSION['page_infos'] = [];
                    }
                    /** @var array<mixed> $page_infos_ref */
                    $page_infos_ref   = &$_SESSION['page_infos'];
                    $page_infos_ref[] = l10n_dec('%d photo was deleted', '%d photos were deleted', $nb_orphans_deleted);
                    redirect(ServiceLocator::get(UrlGenerator::class)->admin() . '&page=' . (is_scalar($_GET['page']) ? (string) $_GET['page'] : ''));
                }
            }

            if ('sync_md5sum' == $_GET['action'] && isset($_GET['nb_md5sum_added'])) {
                check_input_parameter('nb_md5sum_added', $_GET, false, '/^\d+$/');
                $nb_md5sum_added = is_numeric($_GET['nb_md5sum_added']) ? (int) $_GET['nb_md5sum_added'] : 0;
                if ($nb_md5sum_added > 0) {
                    if (!is_array($_SESSION['page_infos'] ?? null)) {
                        $_SESSION['page_infos'] = [];
                    }
                    /** @var array<mixed> $page_infos_ref */
                    $page_infos_ref   = &$_SESSION['page_infos'];
                    $page_infos_ref[] = l10n_dec('%d checksums were added', '%d checksums were added', $nb_md5sum_added);
                    redirect(ServiceLocator::get(UrlGenerator::class)->admin() . '&page=' . (is_scalar($_GET['page']) ? (string) $_GET['page'] : ''));
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
                check_input_parameter('filter_category', $_POST, false, PATTERN_ID);
                $bmf['category'] = $_POST['filter_category'];
                if (isset($_POST['filter_category_recursive'])) {
                    $bmf['category_recursive'] = true;
                }
            }

            if (isset($_POST['filter_tags_use'])) {
                $filter_tags_post = $_POST['filter_tags'] ?? null;
                if (is_array($filter_tags_post)) {
                    $filter_tags_raw = array_map(static fn (mixed $v): string => is_scalar($v) ? (string) $v : '', $filter_tags_post);
                } else {
                    $filter_tags_raw = is_scalar($filter_tags_post) ? (string) $filter_tags_post : '';
                }
                $bmf['tags'] = get_tag_ids($filter_tags_raw, false);
                if (isset($_POST['tag_mode']) && in_array($_POST['tag_mode'], ['AND', 'OR'])) {
                    $bmf['tag_mode'] = $_POST['tag_mode'];
                }
            }

            if (isset($_POST['filter_level_use'])) {
                check_input_parameter('filter_level', $_POST, false, '/^\d+$/');
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

            $_SESSION['bulk_manager_filter'] = trigger_change('batch_manager_register_filters', $bmf);
        } elseif (isset($_GET['filter'])) {
            if (!is_array($_GET['filter'])) {
                $_GET['filter'] = explode(',', is_scalar($_GET['filter']) ? (string) $_GET['filter'] : '');
            }
            /** @var array<string, mixed> $bmf */
            $bmf = [];

            foreach ($_GET['filter'] as $filter) {
                [$type, $value] = explode('-', is_scalar($filter) ? (string) $filter : '', 2) + [1 => ''];

                switch ($type) {
                    case 'prefilter':
                        if (preg_match('/^duplicates-?/', $value)) {
                            [, $duplicate_field] = explode('-', $value, 2);
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
                        $bmf = trigger_change('batch_manager_url_filter', $bmf, $filter);
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
                    $filter_sets[] = array_column(get_dbal_connection()->executeQuery('SELECT element_id FROM ' . CADDIE_TABLE . ' WHERE user_id = ' . $userId . ';')->fetchAllAssociative(), 'element_id');
                    break;
                case 'favorites':
                    $userId2       = is_numeric($user['id']) ? (int) $user['id'] : 0;
                    $filter_sets[] = array_column(get_dbal_connection()->executeQuery('SELECT image_id FROM ' . FAVORITES_TABLE . ' WHERE user_id = ' . $userId2 . ';')->fetchAllAssociative(), 'image_id');
                    break;
                case 'last_import':
                    $last_import_date = ServiceLocator::get(ImageRepository::class)->findMaxDateAvailable();
                    if (!empty($last_import_date)) {
                        $filter_sets[] = array_column(get_dbal_connection()->executeQuery('SELECT id FROM ' . IMAGES_TABLE . ' WHERE date_available BETWEEN ' . SqlExpr::recentPeriodExpr(1, $last_import_date) . ' AND \'' . $last_import_date . '\';')->fetchAllAssociative(), 'id');
                    }
                    break;
                case 'no_virtual_album':
                    $all_elements    = array_column(get_dbal_connection()->executeQuery('SELECT id FROM ' . IMAGES_TABLE . ';')->fetchAllAssociative(), 'id');
                    $linked_to_virtual = [];
                    $virtual_categories = array_column(get_dbal_connection()->executeQuery('SELECT id FROM ' . CATEGORIES_TABLE . ' WHERE dir IS NULL;')->fetchAllAssociative(), 'id');
                    if (!empty($virtual_categories)) {
                        $linked_to_virtual = array_column(get_dbal_connection()->executeQuery('SELECT DISTINCT(image_id) FROM ' . IMAGE_CATEGORY_TABLE . ' WHERE category_id IN (' . implode(',', array_map(fn (mixed $v): string => is_scalar($v) ? (string) $v : '0', $virtual_categories)) . ');')->fetchAllAssociative(), 'image_id');
                    }
                    $filter_sets[] = array_diff(array_map(fn (mixed $v): string => is_scalar($v) ? (string) $v : '0', $all_elements), array_map(fn (mixed $v): string => is_scalar($v) ? (string) $v : '0', $linked_to_virtual));
                    break;
                case 'no_album':
                    $filter_sets[] = get_orphans();
                    break;
                case 'no_sync_md5sum':
                    $filter_sets[] = get_photos_no_md5sum();
                    break;
                case 'no_tag':
                    $filter_sets[] = array_column(get_dbal_connection()->executeQuery('SELECT id FROM ' . IMAGES_TABLE . ' LEFT JOIN ' . IMAGE_TAG_TABLE . ' ON id = image_id WHERE tag_id is null;')->fetchAllAssociative(), 'id');
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
                    $query = 'SELECT GROUP_CONCAT(id) AS ids FROM ' . IMAGES_TABLE;
                    if (in_array('md5sum', $duplicates_on_fields)) {
                        $query .= ' WHERE md5sum IS NOT NULL';
                    }
                    $query .= ' GROUP BY ' . implode(',', $duplicates_on_fields) . ' HAVING COUNT(*) > 1;';
                    $ids = [];
                    foreach (array_column(get_dbal_connection()->executeQuery($query)->fetchAllAssociative(), 'ids') as $ids_string) {
                        $ids_string = rtrim(is_scalar($ids_string) ? (string) $ids_string : '', ',');
                        $ids = array_merge($ids, explode(',', $ids_string));
                    }
                    $filter_sets[] = $ids;
                    break;
                case 'all_photos':
                    if (count($bmf) == 1) {
                        $filter_sets[] = array_column(get_dbal_connection()->executeQuery('SELECT id FROM ' . IMAGES_TABLE . ' ' . Config::orderBy())->fetchAllAssociative(), 'id');
                    }
                    break;
                default:
                    $filter_sets = trigger_change('perform_batch_manager_prefilters', $filter_sets, $bmf_prefilter);
                    break;
            }
        }

        if (isset($bmf['category'])) {
            $bmf_category = is_numeric($bmf['category']) ? (int) $bmf['category'] : 0;
            if (!ServiceLocator::get(CategoryRepository::class)->existsById($bmf_category)) {
                unset($_SESSION['bulk_manager_filter']);
                redirect(ServiceLocator::get(UrlGenerator::class)->admin() . '&page=' . (is_scalar($_GET['page']) ? (string) $_GET['page'] : ''));
            }
            $categories   = isset($bmf['category_recursive']) ? get_subcat_ids([$bmf_category]) : [$bmf_category];
            $filter_sets[] = array_column(get_dbal_connection()->executeQuery('SELECT DISTINCT(image_id) FROM ' . IMAGE_CATEGORY_TABLE . ' WHERE category_id IN (' . implode(',', $categories) . ');')->fetchAllAssociative(), 'image_id');
        }

        if (isset($bmf['level'])) {
            $operator  = isset($bmf['level_include_lower']) ? '<=' : '=';
            $bmf_level = is_numeric($bmf['level']) ? (int) $bmf['level'] : 0;
            $filter_sets[] = array_column(get_dbal_connection()->executeQuery('SELECT id FROM ' . IMAGES_TABLE . ' WHERE level ' . $operator . ' ' . $bmf_level . ' ' . Config::orderBy())->fetchAllAssociative(), 'id');
        }

        if (!empty($bmf['tags'])) {
            $bmf_tags     = array_map(static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, is_array($bmf['tags']) ? $bmf['tags'] : []);
            $bmf_tag_mode = is_string($bmf['tag_mode'] ?? null) ? $bmf['tag_mode'] : 'AND';
            $filter_sets[] = get_image_ids_for_tags($bmf_tags, $bmf_tag_mode, null, null, false);
        }

        if (isset($bmf['dimension'])) {
            $bmf_dimension = is_array($bmf['dimension']) ? $bmf['dimension'] : [];
            $where_clause  = [];
            if (isset($bmf_dimension['min_width'])) {
                $where_clause[] = 'width >= '  . (is_scalar($bmf_dimension['min_width']) ? (string) $bmf_dimension['min_width'] : '0');
            }
            if (isset($bmf_dimension['max_width'])) {
                $where_clause[] = 'width <= '  . (is_scalar($bmf_dimension['max_width']) ? (string) $bmf_dimension['max_width'] : '0');
            }
            if (isset($bmf_dimension['min_height'])) {
                $where_clause[] = 'height >= ' . (is_scalar($bmf_dimension['min_height']) ? (string) $bmf_dimension['min_height'] : '0');
            }
            if (isset($bmf_dimension['max_height'])) {
                $where_clause[] = 'height <= ' . (is_scalar($bmf_dimension['max_height']) ? (string) $bmf_dimension['max_height'] : '0');
            }
            if (isset($bmf_dimension['min_ratio'])) {
                $where_clause[] = 'width/height >= ' . (is_scalar($bmf_dimension['min_ratio']) ? (string) $bmf_dimension['min_ratio'] : '0');
            }
            if (isset($bmf_dimension['max_ratio'])) {
                $max_ratio    = is_numeric($bmf_dimension['max_ratio']) ? (float) $bmf_dimension['max_ratio'] : 0.0;
                $where_clause[] = 'width/height < ' . ($max_ratio + 0.01);
            }
            if (!empty($where_clause)) {
                $filter_sets[] = array_column(get_dbal_connection()->executeQuery('SELECT id FROM ' . IMAGES_TABLE . ' WHERE ' . implode(' AND ', $where_clause) . ' ' . Config::orderBy())->fetchAllAssociative(), 'id');
            }
        }

        if (isset($bmf['filesize'])) {
            $bmf_filesize = is_array($bmf['filesize']) ? $bmf['filesize'] : [];
            $where_clause = [];
            if (isset($bmf_filesize['min'])) {
                $fs_min = is_numeric($bmf_filesize['min']) ? (float) $bmf_filesize['min'] : 0.0;
                $where_clause[] = 'filesize >= ' . (($fs_min - 0.1) * 1024);
            }
            if (isset($bmf_filesize['max'])) {
                $fs_max = is_numeric($bmf_filesize['max']) ? (float) $bmf_filesize['max'] : 0.0;
                $where_clause[] = 'filesize <= ' . (($fs_max + 0.1) * 1024);
            }
            if (!empty($where_clause)) {
                $filter_sets[] = array_column(get_dbal_connection()->executeQuery('SELECT id FROM ' . IMAGES_TABLE . ' WHERE ' . implode(' AND ', $where_clause) . ' ' . Config::orderBy())->fetchAllAssociative(), 'id');
            }
        }

        if (isset($bmf['search'])) {
            $bmf_search   = is_array($bmf['search']) ? $bmf['search'] : [];
            $bmf_search_q = is_string($bmf_search['q'] ?? null) ? $bmf_search['q'] : '';
            if (strlen($bmf_search_q) > 0) {
                require_once PHPWG_ROOT_PATH . 'include/functions_search.inc.php';
                $res       = get_quick_search_results_no_cache($bmf_search_q, ['permissions' => false]);
                $res_qs    = is_array($res['qs'] ?? null) ? $res['qs'] : [];
                if (!empty($res['items']) && !empty($res_qs['unmatched_terms'])) {
                    $tpl->assign('no_search_results', array_map(static fn (mixed $v): string => htmlspecialchars(is_scalar($v) ? (string) $v : ''), is_array($res_qs['unmatched_terms']) ? $res_qs['unmatched_terms'] : []));
                }
                $filter_sets[] = $res['items'];
            }
        }

        $filter_sets = trigger_change('batch_manager_perform_filters', $filter_sets, $bmf);

        $current_set = array_shift($filter_sets);
        foreach ($filter_sets as $set) {
            $a = is_array($current_set) ? array_map(static fn (mixed $v): string => is_scalar($v) ? (string) $v : '', $current_set) : [];
            $b = is_array($set) ? array_map(static fn (mixed $v): string => is_scalar($v) ? (string) $v : '', $set) : [];
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

        $GLOBALS['manager_link'] = $manager_link = ServiceLocator::get(UrlGenerator::class)->admin('batch_manager') . '&amp;mode=';

        if (isset($_GET['mode'])) {
            check_input_parameter('mode', $_GET, false, '/^(global|unit)$/');
            $page['tab'] = is_string($_GET['mode']) ? $_GET['mode'] : 'global';
        } else {
            $page['tab'] = 'global';
        }

        $tabsheet = new Tabsheet();
        $tabsheet->set_id('batch_manager');
        $tabsheet->select($page['tab']);
        $tabsheet->assign();

        // ── Dimensions ────────────────────────────────────────────────────────

        $widths = $heights = $ratios = [];
        foreach (ServiceLocator::get(ImageRepository::class)->findDistinctDimensions() as $row) {
            $row_width  = $row['width'];
            $row_height = $row['height'];
            if ($row_width > 0 && $row_height > 0) {
                $widths[]  = $row_width;
                $heights[] = $row_height;
                $ratios[]  = floor($row_width / $row_height * 100) / 100;
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
        $dimensions['bounds'] = ['min_width' => $widths[0], 'max_width' => end($widths), 'min_height' => $heights[0], 'max_height' => end($heights), 'min_ratio' => $ratios[0], 'max_ratio' => end($ratios)];

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
        foreach (array_keys($ratio_categories) as $rtype) {
            if (count($ratio_categories[$rtype]) > 0) {
                $dimensions['ratio_' . $rtype] = ['min' => $ratio_categories[$rtype][0], 'max' => end($ratio_categories[$rtype])];
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
        foreach (ServiceLocator::get(ImageRepository::class)->findDistinctFilesizes() as $filesize_kb) {
            $filesizes[] = sprintf('%.1f', $filesize_kb / 1024);
        }
        if (empty($filesizes)) {
            $filesizes = [0, 1, 2, 5, 8, 15];
        }
        $filesizes = array_unique($filesizes);
        sort($filesizes);

        $filesize['list']   = implode(',', $filesizes);
        $filesize['bounds'] = ['min' => $filesizes[0], 'max' => end($filesizes)];
        $bmf_filesize_sel   = is_array($bmf['filesize'] ?? null) ? $bmf['filesize'] : [];
        foreach (array_keys($filesize['bounds']) as $ftype) {
            $filesize['selected'][$ftype] = $bmf_filesize_sel[$ftype] ?? $filesize['bounds'][$ftype];
        }
        $tpl->assign('filesize', $filesize);

        $sliders_json = [
            'widths'    => ['values' => array_map(floatval(...), explode(',', $dimensions['widths'])),   'selected' => ['min' => $dimensions['selected']['min_width'],  'max' => $dimensions['selected']['max_width']],  'text' => l10n('between %d and %d pixels')],
            'heights'   => ['values' => array_map(floatval(...), explode(',', $dimensions['heights'])),  'selected' => ['min' => $dimensions['selected']['min_height'], 'max' => $dimensions['selected']['max_height']], 'text' => l10n('between %d and %d pixels')],
            'ratios'    => ['values' => array_map(floatval(...), explode(',', $dimensions['ratios'])),   'selected' => ['min' => $dimensions['selected']['min_ratio'],  'max' => $dimensions['selected']['max_ratio']],  'text' => l10n('between %.2f and %.2f')],
            'filesizes' => ['values' => array_map(floatval(...), explode(',', (string) $filesize['list'])),       'selected' => ['min' => $filesize['selected']['min'], 'max' => $filesize['selected']['max']],                  'text' => l10n('between %s and %s MB')],
        ];

        $filter_category_selected_val = $selected_category ?? null;
        $tpl->assign('batch_filter_page_data_json', json_encode([
            'sliders'                  => $sliders_json,
            'selected_filter_cat_ids'  => $filter_category_selected_val !== null ? [$filter_category_selected_val] : [],
            'str_select_album'         => l10n('Select at least one album'),
            'str_select_tag'           => l10n('Select at least one tag'),
        ], JSON_HEX_TAG | JSON_UNESCAPED_UNICODE));

        // ── Dispatch to tab ───────────────────────────────────────────────────

        $tab = (string) $page['tab'];
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

        require_once PHPWG_ROOT_PATH . 'admin/include/functions.php';

        if (!empty($_POST)) {
            check_pwg_token();
        }

        trigger_notify('loc_begin_element_set_global');

        check_input_parameter('del_tags', $_POST, true, PATTERN_ID);
        check_input_parameter('associate', $_POST, true, PATTERN_ID);
        check_input_parameter('move', $_POST, false, PATTERN_ID);
        check_input_parameter('dissociate', $_POST, false, PATTERN_ID);

        $collection = [];
        if (isset($_POST['nb_photos_deleted'])) {
            check_input_parameter('nb_photos_deleted', $_POST, false, '/^\d+$/');
            $collection = array_fill(0, is_numeric($_POST['nb_photos_deleted']) ? (int) $_POST['nb_photos_deleted'] : 0, null);
        } elseif (isset($_POST['setSelected'])) {
            $collection = explode(',', is_scalar($_POST['whole_set']) ? (string) $_POST['whole_set'] : '');
            foreach ($collection as $id) {
                if (!preg_match('/^\d+$/', $id)) {
                    fatal_error('[Hacking attempt] the input parameter "whole_set" is not valid');
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

        $redirect_url = ServiceLocator::get(UrlGenerator::class)->admin() . '&page=' . (is_scalar($_GET['page'] ?? null) ? (string) $_GET['page'] : '');

        /** @var array<int> $collection_int */
        $collection_int = array_map(static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $collection);

        if (isset($_POST['submit'])) {
            if (count($collection_int) == 0) {
                PageState::current()->addError(l10n('Select at least one photo'));
            }

            $action   = is_scalar($_POST['selectAction'] ?? null) ? (string) $_POST['selectAction'] : '';
            $redirect = false;

            if ('remove_from_caddie' == $action) {
                ServiceLocator::get(ImageRepository::class)->deleteUserCaddieByImageIds(is_numeric($user['id']) ? (int) $user['id'] : 0, $collection_int);
                $redirect = true;
            } elseif ('add_tags' == $action) {
                if (empty($_POST['add_tags'])) {
                    PageState::current()->addError(l10n('Select at least one tag'));
                } else {
                    $add_tags_raw = $_POST['add_tags'];
                    $add_tags_val = is_array($add_tags_raw) ? array_map(static fn (mixed $v): string => is_scalar($v) ? (string) $v : '', $add_tags_raw) : (is_scalar($add_tags_raw) ? (string) $add_tags_raw : '');
                    $tag_ids = get_tag_ids($add_tags_val);
                    add_tags($tag_ids, $collection_int);
                    if ('no_tag' == $page['prefilter']) {
                        $redirect = true;
                    }
                }
            } elseif ('del_tags' == $action) {
                $del_tags_post = is_array($_POST['del_tags'] ?? null) ? $_POST['del_tags'] : [];
                /** @var array<int> $del_tags_int */
                $del_tags_int = array_map(static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $del_tags_post);
                if (count($del_tags_int) > 0) {
                    $taglist_before = get_image_tag_ids($collection_int);
                    ServiceLocator::get(TagRepository::class)->deleteImageTagsByImageIdsAndTagIds($collection_int, $del_tags_int);
                    $taglist_after  = get_image_tag_ids($collection_int);
                    /** @var array<int> $images_to_update */
                    $images_to_update = array_map(static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, compare_image_tag_lists($taglist_before, $taglist_after));
                    update_images_lastmodified($images_to_update);
                    $bmf_tags_int = array_map(static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, is_array($bmf['tags'] ?? null) ? $bmf['tags'] : []);
                    if (count(array_intersect($bmf_tags_int, $del_tags_int)) > 0) {
                        $redirect = true;
                    }
                } else {
                    PageState::current()->addError(l10n('Select at least one tag'));
                }
            } elseif ('associate' == $action) {
                if (empty($_POST['associate'])) {
                    PageState::current()->addError(l10n('Select at least one album'));
                } else {
                    $associate_raw = is_array($_POST['associate']) ? array_map(fn ($v): int => is_numeric($v) ? (int) $v : 0, $_POST['associate']) : [];
                    associate_images_to_categories($collection_int, $associate_raw);
                    $_SESSION['page_infos'] = [l10n('Information data registered in database')];
                    if ('no_album' == $page['prefilter']) {
                        $redirect = true;
                    } elseif ('no_virtual_album' == $page['prefilter']) {
                        $associate_id  = is_scalar($_POST['associate']) ? (string) $_POST['associate'] : '';
                        $category_info = get_cat_info($associate_id);
                        if (empty($category_info['dir'])) {
                            $redirect = true;
                        }
                    }
                }
            } elseif ('move' == $action) {
                $move_id     = is_scalar($_POST['move'] ?? null) ? (string) $_POST['move'] : '';
                $move_id_int = is_numeric($move_id) ? (int) $move_id : 0;
                move_images_to_categories($collection_int, [$move_id_int]);
                $_SESSION['page_infos'] = [l10n('Information data registered in database')];
                if ('no_album' == $page['prefilter']) {
                    $redirect = true;
                } elseif ('no_virtual_album' == $page['prefilter']) {
                    $category_info = get_cat_info($move_id);
                    if (empty($category_info['dir'])) {
                        $redirect = true;
                    }
                } elseif (isset($bmf['category']) && $move_id != (is_scalar($bmf['category']) ? (string) $bmf['category'] : '')) {
                    $redirect = true;
                }
            } elseif ('dissociate' == $action) {
                $dissociate_raw = is_scalar($_POST['dissociate'] ?? null) ? (string) $_POST['dissociate'] : '';
                $nb_dissociated = dissociate_images_from_category($collection_int, $dissociate_raw);
                if ($nb_dissociated > 0) {
                    $_SESSION['page_infos'] = [l10n('Information data registered in database')];
                    $redirect = true;
                }
            } elseif ('author' == $action) {
                if (isset($_POST['remove_author'])) {
                    $_POST['author'] = null;
                }
                $datas = [];
                foreach ($collection_int as $image_id) {
                    $datas[] = ['id' => $image_id, 'author' => $_POST['author']];
                }
                mass_updates(IMAGES_TABLE, ['primary' => ['id'], 'update' => ['author']], $datas);
                pwg_activity('photo', $collection_int, 'edit', ['action' => 'author']);
            } elseif ('title' == $action) {
                if (isset($_POST['remove_title'])) {
                    $_POST['title'] = null;
                }
                $datas = [];
                foreach ($collection_int as $image_id) {
                    $datas[] = ['id' => $image_id, 'name' => $_POST['title']];
                }
                mass_updates(IMAGES_TABLE, ['primary' => ['id'], 'update' => ['name']], $datas);
                pwg_activity('photo', $collection_int, 'edit', ['action' => 'title']);
            } elseif ('date_creation' == $action) {
                $date_creation = (isset($_POST['remove_date_creation']) || empty($_POST['date_creation'])) ? null : $_POST['date_creation'];
                $datas = [];
                foreach ($collection_int as $image_id) {
                    $datas[] = ['id' => $image_id, 'date_creation' => $date_creation];
                }
                mass_updates(IMAGES_TABLE, ['primary' => ['id'], 'update' => ['date_creation']], $datas);
                pwg_activity('photo', $collection_int, 'edit', ['action' => 'date_creation']);
            } elseif ('level' == $action) {
                $datas = [];
                foreach ($collection_int as $image_id) {
                    $datas[] = ['id' => $image_id, 'level' => $_POST['level']];
                }
                mass_updates(IMAGES_TABLE, ['primary' => ['id'], 'update' => ['level']], $datas);
                pwg_activity('photo', $collection_int, 'edit', ['action' => 'privacy_level']);
                if (isset($bmf['level'])) {
                    $bmf_level_val  = is_numeric($bmf['level']) ? (int) $bmf['level'] : 0;
                    $post_level_val = is_numeric($_POST['level'] ?? null) ? (int) $_POST['level'] : 0;
                    if ($post_level_val < $bmf_level_val) {
                        $redirect = true;
                    }
                }
            } elseif ('add_to_caddie' == $action) {
                fill_caddie($collection_int);
            } elseif ('delete' == $action) {
                if (isset($_POST['confirm_deletion']) && 1 == $_POST['confirm_deletion']) {
                    if (count($collection_int) > 0) {
                        if (!is_array($_SESSION['page_infos'] ?? null)) {
                            $_SESSION['page_infos'] = [];
                        }
                        /** @var array<mixed> $page_infos_ref */
                        $page_infos_ref   = &$_SESSION['page_infos'];
                        $page_infos_ref[] = l10n_dec('%d photo was deleted', '%d photos were deleted', count($collection_int));
                        $redirect_url     = ServiceLocator::get(UrlGenerator::class)->admin() . '&page=' . (is_scalar($_GET['page'] ?? null) ? (string) $_GET['page'] : '');
                        $redirect         = true;
                    } else {
                        PageState::current()->addError(l10n('No photo can be deleted'));
                    }
                } else {
                    PageState::current()->addError(l10n('You need to confirm deletion'));
                }
            } elseif ('metadata' == $action) {
                PageState::current()->addInfo(l10n('Metadata synchronized from file') . ' <span class="badge">' . count($collection_int) . '</span>');
            } elseif ('delete_derivatives' == $action && !empty($_POST['del_derivatives_type'])) {
                foreach (ServiceLocator::get(ImageRepository::class)->findPathsAndRepresentativesByIds($collection_int) as $info) {
                    $del_types = is_array($_POST['del_derivatives_type']) ? $_POST['del_derivatives_type'] : [];
                    foreach ($del_types as $dtype) {
                        delete_element_derivatives($info, is_scalar($dtype) ? (string) $dtype : '');
                    }
                }
            } elseif ('generate_derivatives' == $action) {
                if ($_POST['regenerateSuccess'] != '0') {
                    PageState::current()->addInfo(l10n('%s photos have been regenerated', $_POST['regenerateSuccess']));
                }
                if ($_POST['regenerateError'] != '0') {
                    PageState::current()->addWarning(l10n('%s photos can not be regenerated', $_POST['regenerateError']));
                }
            }

            if (!in_array($action, ['remove_from_caddie', 'add_to_caddie', 'delete_derivatives', 'generate_derivatives'])) {
                invalidate_user_cache();
            }

            trigger_notify('element_set_global_action', $action, $collection_int);
            if ($redirect) {
                redirect($redirect_url);
            }
        }

        // ── Template ──────────────────────────────────────────────────────────

        $tpl->set_filenames(['batch_manager_global' => 'batch_manager_global.tpl']);
        $base_url = ServiceLocator::get(UrlGenerator::class)->admin();

        require PHPWG_ROOT_PATH . 'admin/include/batch_manager_filters.inc.php';

        $catElementsId = is_array($page['cat_elements_id']) ? $page['cat_elements_id'] : [];
        $pageStart     = is_int($page['start']) ? $page['start'] : 0;

        $tpl->assign('IN_CADDIE', 'caddie' == $page['prefilter']);

        if (count($catElementsId) > 0) {
            $tpl->assign('associated_tags', get_common_tags($catElementsId, -1));
        }

        $tpl->assign('DATE_CREATION', empty($_POST['date_creation']) ? date('Y-m-d') . ' 00:00:00' : $_POST['date_creation']);
        $tpl->assign(['level_options' => get_privacy_level_options(), 'level_options_selected' => 0]);

        require_once PHPWG_ROOT_PATH . 'admin/site_reader_local.php';
        $site_reader  = new \LocalSiteReader('./');
        $used_metadata = implode(', ', array_map(static fn (mixed $v): string => is_scalar($v) ? (string) $v : '', $site_reader->get_metadata_attributes()));
        $tpl->assign(['used_metadata' => $used_metadata]);

        $del_deriv_map = [];
        foreach (ImageStdParams::get_defined_type_map() as $params) {
            $del_deriv_map[$params->type] = l10n($params->type);
        }
        $gen_deriv_map  = $del_deriv_map;
        $del_deriv_map[IMG_CUSTOM] = l10n(IMG_CUSTOM);
        $tpl->assign(['del_derivatives_types' => $del_deriv_map, 'generate_derivatives_types' => $gen_deriv_map]);

        if (!empty($_GET['display'])) {
            $nbImages = 'all' == $_GET['display'] ? count($catElementsId) : (is_numeric($_GET['display']) ? (int) $_GET['display'] : 20);
        } elseif (in_array(Config::batchManagerImagesPerPageGlobal(), [20, 50, 100])) {
            $nbImages = Config::batchManagerImagesPerPageGlobal();
        } else {
            $nbImages = 20;
        }
        $page['nb_images'] = $nbImages;

        $nb_thumbs_page = 0;

        if (count($catElementsId) > 0) {
            $nav_bar = create_navigation_bar($base_url . get_query_string_diff(['start']), count($catElementsId), $pageStart, $nbImages);
            $tpl->assign('navbar', $nav_bar);

            $is_category      = isset($bmf['category']) && !isset($bmf['category_recursive']);
            $bmf_category_val = is_numeric($bmf['category'] ?? null) ? (int) $bmf['category'] : 0;

            if (is_string($bmf['prefilter'] ?? null) && 'duplicates' === $bmf['prefilter'] && isset($duplicates_on_fields)) {
                $order_by_fields = array_merge($duplicates_on_fields, ['id']);
                Config::override('order_by', ' ORDER BY ' . join(', ', $order_by_fields));
            }

            $query = 'SELECT id,path,representative_ext,file,filesize,level,name,width,height,rotation FROM ' . IMAGES_TABLE;
            if ($is_category) {
                $category_info = get_cat_info($bmf_category_val);
                Config::override('order_by', Config::orderByInsideCategory());
                if (!empty($category_info['image_order'])) {
                    Config::override('order_by', ' ORDER BY ' . (is_scalar($category_info['image_order']) ? (string) $category_info['image_order'] : ''));
                }
                $query .= ' JOIN ' . IMAGE_CATEGORY_TABLE . ' ON id = image_id';
            }

            $query .= ' WHERE id IN (' . implode(',', array_map(fn (mixed $v): string => is_scalar($v) ? (string) $v : '', $catElementsId)) . ')';
            if ($is_category) {
                $query .= ' AND category_id = ' . $bmf_category_val;
            }
            $query .= ' ' . Config::orderBy() . ' LIMIT ' . $nbImages . ' OFFSET ' . $pageStart . ';';

            $batchRows   = ServiceLocator::get(Connection::class)->executeQuery($query)->fetchAllAssociative();
            $thumb_params = ImageStdParams::get_by_type(IMG_SQUARE);

            foreach ($batchRows as $row) {
                $nb_thumbs_page++;
                $src_image   = new SrcImage($row);
                $ttitle      = render_element_name($row);
                $row_file    = is_scalar($row['file'] ?? null) ? (string) $row['file'] : '';
                if ($ttitle != get_name_from_file($row_file)) {
                    $ttitle .= ' (' . $row_file . ')';
                }
                $row_filesize = is_numeric($row['filesize'] ?? null) ? (float) $row['filesize'] : 0.0;
                $ttitle .= '<br>' . (is_scalar($row['width']) ? (string) $row['width'] : '') . '&times;' . (is_scalar($row['height']) ? (string) $row['height'] : '') . ' pixels, ' . sprintf('%.2f', $row_filesize / 1024) . 'MB';

                $tpl->append('thumbnails', array_merge($row, [
                    'thumb'    => new DerivativeImage($thumb_params, $src_image),
                    'TITLE'    => $ttitle,
                    'FILE_SRC' => DerivativeImage::url(IMG_LARGE, $src_image),
                    'U_EDIT'   => ServiceLocator::get(UrlGenerator::class)->admin('photo-' . (is_scalar($row['id']) ? (string) $row['id'] : '')),
                ]));
            }
            $tpl->assign('thumb_params', $thumb_params);
        }

        $cache_keys = get_admin_client_cache_keys(['tags', 'categories']);
        $tpl->assign([
            'nb_thumbs_page'                      => $nb_thumbs_page,
            'nb_thumbs_set'                        => count($catElementsId),
            'CACHE_KEYS'                           => $cache_keys,
            'batch_manager_global_page_data_json'  => json_encode([
                'CACHE_KEYS'              => $cache_keys,
                'ROOT_URL'                => get_root_url(),
                'associated_categories'   => $associated_categories ?? [],
                'str_create'              => l10n('Create'),
                'nb_thumbs_page'          => $nb_thumbs_page,
                'nb_thumbs_set'           => count($catElementsId),
                'all_elements'            => $catElementsId,
                'lang'                    => ['Cancel' => l10n('Cancel'), 'deleteProgressMessage' => l10n('Deletion in progress'), 'syncProgressMessage' => l10n('Synchronization in progress'), 'AreYouSure' => l10n('Are you sure?'), 'generateMsg' => l10n('Generate multiple size images')],
                'str_add_alb_associate'   => l10n('Add Album'),
                'str_select_alb_associate' => l10n('Select an album'),
                'applyOnDetails_pattern'  => l10n('on the %d selected photos'),
                'selectedMessage_pattern' => l10n('%d of %d photos selected'),
                'selectedMessage_none'    => l10n('No photo selected, %d photos in current set'),
                'selectedMessage_all'     => l10n('All %d photos are selected'),
            ], JSON_HEX_TAG | JSON_UNESCAPED_UNICODE),
        ]);

        trigger_notify('loc_end_element_set_global');
        $tpl->assign_var_from_handle('ADMIN_CONTENT', 'batch_manager_global');
    }

    // ── batch_manager_unit ────────────────────────────────────────────────────

    private function batchManagerUnit(): void
    {
        $tpl = TemplateRegistry::current();
        /** @var array<string, mixed> $page */
        $page = &$GLOBALS['page'];
        /** @var array<string, mixed> $user */
        $user = $GLOBALS['user'];
        /** @var array<string, mixed> $pwg_loaded_plugins */
        $pwg_loaded_plugins = is_array($GLOBALS['pwg_loaded_plugins'] ?? null) ? $GLOBALS['pwg_loaded_plugins'] : [];

        require_once PHPWG_ROOT_PATH . 'admin/include/functions.php';

        trigger_notify('loc_begin_element_set_unit');

        if (isset($_POST['submit'])) {
            check_pwg_token();
            check_input_parameter('element_ids', $_POST, false, '/^\d+(,\d+)*$/');
            $collection = explode(',', is_scalar($_POST['element_ids']) ? (string) $_POST['element_ids'] : '');

            $datas = [];
            foreach (ServiceLocator::get(ImageRepository::class)->findByIds(array_map(intval(...), $collection)) as $row) {
                $data         = [];
                $row_id_str   = is_scalar($row['id']) ? (string) $row['id'] : '';
                $data['id']   = $row['id'];
                $data['name'] = $_POST['name-' . $row_id_str];
                $data['author'] = $_POST['author-' . $row_id_str];
                $data['level'] = $_POST['level-' . $row_id_str];

                $desc_key = 'description-' . $row_id_str;
                $desc_val = $_POST[$desc_key] ?? null;
                $data['comment'] = Config::allowHtmlDescriptions() ? $desc_val : strip_tags(is_scalar($desc_val) ? (string) $desc_val : '');

                $data['date_creation'] = !empty($_POST['date_creation-' . $row_id_str]) ? $_POST['date_creation-' . $row_id_str] : null;

                $datas[] = $data;

                $tag_ids  = [];
                $tags_key = 'tags-' . (is_scalar($row['id']) ? (string) $row['id'] : '');
                if (!empty($_POST[$tags_key])) {
                    $tags_val = $_POST[$tags_key];
                    $tags_val = is_array($tags_val) ? array_map(static fn (mixed $v): string => is_scalar($v) ? (string) $v : '', $tags_val) : (is_scalar($tags_val) ? (string) $tags_val : '');
                    $tag_ids  = get_tag_ids($tags_val);
                }
                set_tags($tag_ids, is_numeric($row['id']) ? (int) $row['id'] : 0);
            }

            mass_updates(IMAGES_TABLE, ['primary' => ['id'], 'update' => ['name', 'author', 'level', 'comment', 'date_creation']], $datas);
            PageState::current()->addInfo(l10n('Photo informations updated'));
            invalidate_user_cache();
        }

        $collection = [];
        if (isset($_POST['nb_photos_deleted'])) {
            check_input_parameter('nb_photos_deleted', $_POST, false, '/^\d+$/');
            $collection = array_fill(0, is_numeric($_POST['nb_photos_deleted']) ? (int) $_POST['nb_photos_deleted'] : 0, null);
        } elseif (isset($_POST['setSelected'])) {
            $collection = explode(',', is_scalar($_POST['whole_set']) ? (string) $_POST['whole_set'] : '');
            foreach ($collection as $id) {
                if (!preg_match('/^\d+$/', $id)) {
                    fatal_error('[Hacking attempt] the input parameter "whole_set" is not valid');
                }
            }
        } elseif (isset($_POST['selection'])) {
            $collection = is_array($_POST['selection']) ? $_POST['selection'] : [];
        }

        $tpl->set_filenames(['batch_manager_unit' => 'batch_manager_unit.tpl']);
        $base_url = ServiceLocator::get(UrlGenerator::class)->admin();

        $tpl->assign([
            'U_ELEMENTS_PAGE' => $base_url . get_query_string_diff(['display', 'start']),
            'level_options'   => get_privacy_level_options(),
            'ADMIN_PAGE_TITLE' => l10n('Batch Manager'),
            'PWG_TOKEN'       => get_pwg_token(),
        ]);

        require PHPWG_ROOT_PATH . 'admin/include/batch_manager_filters.inc.php';

        $tpl->assign('page_data_json', json_encode([
            'str_are_you_sure' => l10n('Are you sure?'),
            'str_yes'          => l10n('Yes, delete'),
            'str_no'           => l10n('No, I have changed my mind'),
            'str_orphan'       => l10n('This photo is an orphan'),
            'str_meta_warning' => l10n('Warning ! Unsaved changes will be lost'),
            'str_meta_yes'     => l10n('I want to continue'),
            'str_title_ab'     => l10n('Associate to album'),
            'str_cancel'       => l10n('Cancel'),
        ], JSON_HEX_TAG | JSON_UNESCAPED_UNICODE));

        $tpl->assign('ACTIVE_PLUGINS', array_keys($pwg_loaded_plugins));

        $catElementsIdU = is_array($page['cat_elements_id']) ? $page['cat_elements_id'] : [];
        $pageStartU     = is_int($page['start']) ? $page['start'] : 0;
        if (!empty($_GET['display'])) {
            $nbImagesU = is_numeric($_GET['display']) ? (int) $_GET['display'] : 5;
        } elseif (in_array(Config::batchManagerImagesPerPageUnit(), [5, 10, 50])) {
            $nbImagesU = Config::batchManagerImagesPerPageUnit();
        } else {
            $nbImagesU = 5;
        }
        $page['nb_images'] = $nbImagesU;
        $tpl->assign('per_page', $nbImagesU);

        if (count($catElementsIdU) > 0) {
            $nav_bar = create_navigation_bar($base_url . get_query_string_diff(['start']), count($catElementsIdU), $pageStartU, $nbImagesU);
            $tpl->assign(['navbar' => $nav_bar]);

            $element_ids      = [];
            /** @var array<string, mixed> $bmf */
            $bmf              = is_array($_SESSION['bulk_manager_filter'] ?? null) ? $_SESSION['bulk_manager_filter'] : [];
            $is_category      = isset($bmf['category']) && !isset($bmf['category_recursive']);
            $bmf_category_val = is_numeric($bmf['category'] ?? null) ? (int) $bmf['category'] : 0;

            if (is_string($bmf['prefilter'] ?? null) && 'duplicates' == $bmf['prefilter']) {
                Config::override('order_by', ' ORDER BY file, id');
            }

            $query = 'SELECT * FROM ' . IMAGES_TABLE;
            if ($is_category) {
                $category_info = get_cat_info($bmf_category_val);
                Config::override('order_by', Config::orderByInsideCategory());
                if (!empty($category_info['image_order'])) {
                    Config::override('order_by', ' ORDER BY ' . (is_scalar($category_info['image_order']) ? (string) $category_info['image_order'] : ''));
                }
                $query .= ' JOIN ' . IMAGE_CATEGORY_TABLE . ' ON id = image_id';
            }

            $query .= ' WHERE id IN (' . implode(',', array_map(fn (mixed $v): string => is_scalar($v) ? (string) $v : '', $catElementsIdU)) . ')';
            if ($is_category) {
                $query .= ' AND category_id = ' . $bmf_category_val;
            }
            $query .= ' ' . Config::orderBy() . ' LIMIT ' . $nbImagesU . ' OFFSET ' . $pageStartU . ';';

            $images         = get_dbal_connection()->executeQuery($query)->fetchAllAssociative();
            $added_by_ids   = array_unique(array_map(fn (mixed $v): string => is_scalar($v) ? (string) $v : '0', array_column($images, 'added_by')));
            $added_by_username_of = [];
            if (count($added_by_ids) > 0) {
                $added_by_username_of = array_column(get_dbal_connection()->executeQuery('SELECT ' . Config::userFields()['username'] . ' AS username, ' . Config::userFields()['id'] . ' AS id FROM ' . USERS_TABLE . ' WHERE ' . Config::userFields()['id'] . ' IN (' . implode(',', $added_by_ids) . ');')->fetchAllAssociative(), 'username', 'id');
            }

            $storage_category_id = null;

            foreach ($images as $row) {
                $element_ids[] = is_scalar($row['id']) ? (string) $row['id'] : '0';
                $src_image     = new SrcImage($row);
                $image_file    = $row['file'];
                $tag_selection = get_taglist_from_rows(ServiceLocator::get(TagRepository::class)->findTagsByImageId(is_numeric($row['id'] ?? null) ? (int) $row['id'] : 0));
                $legend        = render_element_name($row);
                $row_file_str  = is_scalar($row['file'] ?? null) ? (string) $row['file'] : '';
                if ($legend != get_name_from_file($row_file_str)) {
                    $legend .= ' (' . $row_file_str . ')';
                }
                $extTab        = explode('.', is_scalar($row['path'] ?? null) ? (string) $row['path'] : '');

                $related_categories   = [];
                $related_category_ids = [];
                $row_id_int           = is_numeric($row['id'] ?? null) ? (int) $row['id'] : 0;
                $media_image          = get_image_infos($row_id_int, true);

                foreach (ServiceLocator::get(CategoryRepository::class)->findCategoryInfosByImageId($row_id_int) as $item) {
                    $item_uppercats = is_scalar($item['uppercats'] ?? null) ? (string) $item['uppercats'] : '';
                    $name = get_cat_display_name_cache($item_uppercats, ServiceLocator::get(UrlGenerator::class)->admin() . '&page=album-');
                    if ($item['category_id'] == $storage_category_id) {
                        $tpl->assign('STORAGE_CATEGORY', $name);
                    }
                    $item_cat_id = is_numeric($item['category_id'] ?? null) ? (int) $item['category_id'] : 0;
                    $related_categories[$item_cat_id] = ['name' => $name, 'unlinkable' => $item_cat_id != $storage_category_id];
                    $related_category_ids[] = $item_cat_id;
                }

                $row_id_str = is_scalar($row['id']) ? (string) $row['id'] : '0';
                $authorizeds = array_diff(
                    array_map(fn (mixed $v): string => is_scalar($v) ? (string) $v : '0', array_column(get_dbal_connection()->executeQuery('SELECT category_id FROM ' . IMAGE_CATEGORY_TABLE . ' WHERE image_id = ' . $row_id_str . ';')->fetchAllAssociative(), 'category_id')),
                    explode(',', calculate_permissions($user['id'], is_string($user['status']) ? $user['status'] : ''))
                );

                $catNames = RequestCache::remember('cat_names', 'all', static fn (): array => array_column(get_dbal_connection()->executeQuery('SELECT id, name, permalink FROM ' . CATEGORIES_TABLE . ';')->fetchAllAssociative(), null, 'id') ?: []);
                $url_img  = null;
                if (isset($row['cat_id']) && in_array($row['cat_id'], $authorizeds)) {
                    $url_img = make_picture_url(['image_id' => $row['id'], 'image_file' => $image_file, 'category' => (is_array($catNames) && (is_int($row['cat_id']) || is_string($row['cat_id']))) ? ($catNames[$row['cat_id']] ?? null) : null]);
                } else {
                    foreach ($authorizeds as $category) {
                        $url_img = make_picture_url(['image_id' => $row['id'], 'image_file' => $image_file, 'category' => is_array($catNames) ? ($catNames[$category] ?? null) : null]);
                        break;
                    }
                }

                $admin_photo_base_url = ServiceLocator::get(UrlGenerator::class)->admin('photo-' . $row_id_str);
                $admin_url_start      = $admin_photo_base_url . '-properties';
                $admin_url_start     .= isset($row['cat_id']) ? '&amp;cat_id=' . (is_scalar($row['cat_id']) ? (string) $row['cat_id'] : '') : '';
                $selected_level       = $row['level'] ?? $row['level'];

                $userLevel  = is_numeric($user['level'] ?? null) ? (int) $user['level'] : 0;
                $mediaLevelRaw = is_array($media_image) ? ($media_image['level'] ?? null) : null;
                $mediaLevel    = is_numeric($mediaLevelRaw) ? (int) $mediaLevelRaw : 0;

                $tpl->append('elements', array_merge($row, [
                    'ID'                    => $row['id'],
                    'TN_SRC'                => DerivativeImage::url(IMG_MEDIUM, $src_image),
                    'FILE_SRC'              => DerivativeImage::url(IMG_LARGE, $src_image),
                    'LEGEND'                => $legend,
                    'U_EDIT'                => ServiceLocator::get(UrlGenerator::class)->admin('photo-' . $row_id_str),
                    'NAME'                  => htmlspecialchars(is_scalar($row['name']) ? (string) $row['name'] : ''),
                    'AUTHOR'                => htmlspecialchars(is_scalar($row['author']) ? (string) $row['author'] : ''),
                    'LEVEL'                 => !empty($row['level']) ? $row['level'] : '0',
                    'DESCRIPTION'           => htmlspecialchars(is_scalar($row['comment']) ? (string) $row['comment'] : ''),
                    'DATE_CREATION'         => $row['date_creation'],
                    'TAGS'                  => $tag_selection,
                    'is_svg'                => (strtoupper(end($extTab)) == 'SVG'),
                    'TITLE'                 => render_element_name($row),
                    'DIMENSIONS'            => (is_scalar($row['width']) ? (string) $row['width'] : '') . 'x' . (is_scalar($row['height']) ? (string) $row['height'] : '') . ' px',
                    'FORMAT'                => ($row['width'] >= $row['height']) ? 1 : 0,
                    'FILESIZE'              => l10n('%.2f MB', (is_numeric($row['filesize'] ?? null) ? (float) $row['filesize'] : 0.0) / 1024),
                    'REGISTRATION_DATE'     => format_date(is_string($row['date_available'] ?? null) ? $row['date_available'] : (is_int($row['date_available'] ?? null) ? $row['date_available'] : null)),
                    'EXT'                   => l10n('%s file type', end($extTab)),
                    'POST_DATE'             => l10n('Added on %s', format_date(is_string($row['date_available'] ?? null) ? $row['date_available'] : (is_int($row['date_available'] ?? null) ? $row['date_available'] : null), ['day', 'month', 'year'])),
                    'AGE'                   => l10n(ucfirst(time_since(is_string($row['date_available'] ?? null) ? $row['date_available'] : (is_int($row['date_available'] ?? null) ? $row['date_available'] : null), 'year'))),
                    'ADDED_BY'              => l10n('Added by %s', $added_by_username_of[is_scalar($row['added_by']) ? (string) $row['added_by'] : ''] ?? l10n('N/A')),
                    'STATS'                 => l10n('Visited %d times', $row['hit']),
                    'FILE'                  => l10n('%s', $row['file']),
                    'related_categories'    => $related_categories,
                    'related_category_ids'  => json_encode($related_category_ids),
                    'U_JUMPTO'              => (isset($url_img) && $userLevel >= $mediaLevel) ? $url_img : null,
                    'tag_selection'         => $tag_selection,
                    'U_DOWNLOAD'            => ServiceLocator::get(UrlGenerator::class)->actionDownload((int) $row_id_str, 'e', get_pwg_token()),
                    'U_HISTORY'             => ServiceLocator::get(UrlGenerator::class)->admin('history') . '&amp;filter_image_id=' . $row_id_str,
                    'U_ACTIVITY'            => ServiceLocator::get(UrlGenerator::class)->admin('user_activity') . '&photo=' . $row_id_str,
                    'U_DELETE'              => $admin_url_start . '&amp;delete=1&amp;pwg_token=' . get_pwg_token(),
                    'U_SYNC'                => $admin_url_start . '&amp;sync_metadata=1',
                    'PATH'                  => $row['path'],
                    'level_options_selected' => [$selected_level],
                ]));
            }

            $tpl->assign(['ELEMENT_IDS' => implode(',', $element_ids)]);
        }

        $cache_keys = get_admin_client_cache_keys(['tags', 'categories']);
        $tpl->assign([
            'CACHE_KEYS'                          => $cache_keys,
            'batch_manager_unit_page_data_json'   => json_encode([
                'CACHE_KEYS'            => $cache_keys,
                'ROOT_URL'              => get_root_url(),
                'associated_categories' => $associated_categories ?? [],
                'str_create'            => l10n('Create'),
                'active_plugins'        => array_keys($pwg_loaded_plugins),
            ], JSON_HEX_TAG | JSON_UNESCAPED_UNICODE),
        ]);

        trigger_notify('loc_end_element_set_unit');
        $tpl->assign_var_from_handle('ADMIN_CONTENT', 'batch_manager_unit');
    }

    // ── queue ─────────────────────────────────────────────────────────────────

    private function queue(): void
    {
        $tpl = TemplateRegistry::current();

        fs_quick_check();

        $tableName = Config::dbPrefix() . 'messenger_messages';
        $conn      = ServiceLocator::get(Connection::class);

        $action = is_string($_GET['action'] ?? null) ? $_GET['action'] : '';

        if ($action === 'retry' && is_numeric($_GET['id'] ?? null)) {
            $failedId = (int) $_GET['id'];
            $row      = $conn->executeQuery('SELECT body, headers FROM ' . $tableName . ' WHERE id = ? AND queue_name = ?', [$failedId, 'piwigo_failed'])->fetchAssociative();
            if ($row !== false) {
                $conn->executeStatement('UPDATE ' . $tableName . ' SET queue_name = ?, available_at = NOW(), delivered_at = NULL WHERE id = ?', ['piwigo_async', $failedId]);
                PageState::current()->addInfo('Job moved back to async queue.');
            }
            redirect(ServiceLocator::get(UrlGenerator::class)->admin('queue'));
        }

        if ($action === 'purge_failed') {
            check_pwg_token();
            $conn->executeStatement('DELETE FROM ' . $tableName . ' WHERE queue_name = ?', ['piwigo_failed']);
            PageState::current()->addInfo('Failed queue purged.');
            redirect(ServiceLocator::get(UrlGenerator::class)->admin('queue'));
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

        $tpl->set_filenames(['queue' => 'queue.tpl']);

        $pwg_token     = get_pwg_token();
        $pendingAsync  = $stats['piwigo_async'] ?? 0;
        $pendingFailed = $stats['piwigo_failed'] ?? 0;

        $failedJobsForTpl = array_map(static function (array $row): array {
            /** @var array<string, mixed> $body */
            $body  = json_decode(is_string($row['body']) ? $row['body'] : '{}', true) ?? [];
            $class = is_string($body['type'] ?? null) ? basename(str_replace('\\', '/', $body['type'])) : 'Unknown';
            return ['id' => is_numeric($row['id']) ? (int) $row['id'] : 0, 'class' => $class, 'created_at' => is_string($row['created_at']) ? $row['created_at'] : '', 'U_RETRY' => ServiceLocator::get(UrlGenerator::class)->admin('queue') . '&action=retry&id=' . (is_numeric($row['id']) ? (int) $row['id'] : 0)];
        }, $failedJobs);

        $tpl->assign([
            'table_exists'   => $tableExists,
            'pending_async'  => $pendingAsync,
            'pending_failed' => $pendingFailed,
            'failed_jobs'    => $failedJobsForTpl,
            'U_PURGE_FAILED' => ServiceLocator::get(UrlGenerator::class)->admin('queue') . '&action=purge_failed&pwg_token=' . $pwg_token,
            'worker_command' => 'bin/piwigo messenger:consume async --time-limit=3600 --memory-limit=256M',
        ]);

        $tpl->assign_var_from_handle('ADMIN_CONTENT', 'queue');
    }
}
