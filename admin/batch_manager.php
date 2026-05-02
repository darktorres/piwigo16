<?php

declare(strict_types=1);

use Piwigo\Admin\Tabsheet;

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

/**
 * Management of elements set. Elements can belong to a category or to the
 * user caddie.
 *
 */

if (!defined('PHPWG_ROOT_PATH')) {
    die('Hacking attempt!');
}

global $template, $user, $page, $persistent_cache, $lang, $logger, $pwg_loaded_plugins;


include_once(PHPWG_ROOT_PATH.'admin/include/functions.php');

// +-----------------------------------------------------------------------+
// | Check Access and exit when user status is not ok                      |
// +-----------------------------------------------------------------------+

check_status(ACCESS_ADMINISTRATOR);

check_input_parameter('selection', $_POST, true, PATTERN_ID);
check_input_parameter('display', $_REQUEST, false, '/^(\d+|all)$/');

// +-----------------------------------------------------------------------+
// | specific actions                                                      |
// +-----------------------------------------------------------------------+

if (isset($_GET['action'])) {
    if ('empty_caddie' == $_GET['action']) {
        $query = '
DELETE FROM '.CADDIE_TABLE.'
  WHERE user_id = '.$user['id'].'
;';
        pwg_query($query);

        $_SESSION['page_infos'] = [
          l10n('Information data registered in database'),
          ];

        redirect(get_root_url().'admin.php?page='.(is_scalar($_GET['page']) ? (string) $_GET['page'] : ''));
    }

    if ('delete_orphans' == $_GET['action'] and isset($_GET['nb_orphans_deleted'])) {
        check_input_parameter('nb_orphans_deleted', $_GET, false, '/^\d+$/');

        $nb_orphans_deleted = is_numeric($_GET['nb_orphans_deleted']) ? (int) $_GET['nb_orphans_deleted'] : 0;
        if ($nb_orphans_deleted > 0) {
            if (!is_array($_SESSION['page_infos'] ?? null)) {
                $_SESSION['page_infos'] = [];
            }
            /** @var array<mixed> $page_infos_ref */
            $page_infos_ref = &$_SESSION['page_infos'];
            $page_infos_ref[] = l10n_dec(
                '%d photo was deleted',
                '%d photos were deleted',
                $nb_orphans_deleted
            );

            redirect(get_root_url().'admin.php?page='.(is_scalar($_GET['page']) ? (string) $_GET['page'] : ''));
        }
    }

    if ('sync_md5sum' == $_GET['action'] and isset($_GET['nb_md5sum_added'])) {
        check_input_parameter('nb_md5sum_added', $_GET, false, '/^\d+$/');
        $nb_md5sum_added = is_numeric($_GET['nb_md5sum_added']) ? (int) $_GET['nb_md5sum_added'] : 0;
        if ($nb_md5sum_added > 0) {
            if (!is_array($_SESSION['page_infos'] ?? null)) {
                $_SESSION['page_infos'] = [];
            }
            /** @var array<mixed> $page_infos_ref */
            $page_infos_ref = &$_SESSION['page_infos'];
            $page_infos_ref[] = l10n_dec(
                '%d checksums were added',
                '%d checksums were added',
                $nb_md5sum_added
            );

            redirect(get_root_url().'admin.php?page='.(is_scalar($_GET['page']) ? (string) $_GET['page'] : ''));
        }
    }
}
// +-----------------------------------------------------------------------+
// |                      initialize current set                           |
// +-----------------------------------------------------------------------+

// filters from form
if (isset($_POST['submitFilter'])) {
    // echo '<pre>'; print_r($_POST); echo '</pre>';
    unset($_REQUEST['start']); // new photo set must reset the page
    /** @var array<string, mixed> $bmf */
    $bmf = [];

    if (isset($_POST['filter_prefilter_use'])) {
        $bmf['prefilter'] = $_POST['filter_prefilter'];

        if ('duplicates' == $_POST['filter_prefilter']) {
            $has_options = false;

            if (isset($_POST['filter_duplicates_checksum'])) {
                $bmf['duplicates_checksum'] = true;
                $has_options = true;
            }

            if (isset($_POST['filter_duplicates_date'])) {
                $bmf['duplicates_date'] = true;
                $has_options = true;
            }

            if (isset($_POST['filter_duplicates_dimensions'])) {
                $bmf['duplicates_dimensions'] = true;
                $has_options = true;
            }

            if (!$has_options or isset($_POST['filter_duplicates_filename'])) {
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

        if (isset($_POST['tag_mode']) and in_array($_POST['tag_mode'], ['AND', 'OR'])) {
            $bmf['tag_mode'] = $_POST['tag_mode'];
        }
    }

    if (isset($_POST['filter_level_use'])) {
        check_input_parameter('filter_level', $_POST, false, '/^\d+$/');

        if (in_array($_POST['filter_level'], \Piwigo\Core\Config::availablePermissionLevels())) {
            $bmf['level'] = $_POST['filter_level'];

            if (isset($_POST['filter_level_include_lower'])) {
                $bmf['level_include_lower'] = true;
            }
        }
    }

    if (isset($_POST['filter_dimension_use'])) {
        /** @var array<string, mixed> $dim_filter */
        $dim_filter = [];
        foreach (['min_width','max_width','min_height','max_height'] as $type) {
            if (filter_var($_POST['filter_dimension_'.$type], FILTER_VALIDATE_INT) !== false) {
                $dim_filter[$type] = $_POST['filter_dimension_'. $type ];
            }
        }
        foreach (['min_ratio','max_ratio'] as $type) {
            if (filter_var($_POST['filter_dimension_'.$type], FILTER_VALIDATE_FLOAT) !== false) {
                $dim_filter[$type] = $_POST['filter_dimension_'. $type ];
            }
        }
        $bmf['dimension'] = $dim_filter;
    }

    if (isset($_POST['filter_filesize_use'])) {
        /** @var array<string, mixed> $fs_filter */
        $fs_filter = [];
        foreach (['min','max'] as $type) {
            if (filter_var($_POST['filter_filesize_'.$type], FILTER_VALIDATE_FLOAT) !== false) {
                $fs_filter[$type] = $_POST['filter_filesize_'. $type ];
            }
        }
        $bmf['filesize'] = $fs_filter;
    }

    if (isset($_POST['filter_search_use'])) {
        $bmf['search'] = ['q' => $_POST['q']];
    }

    $_SESSION['bulk_manager_filter'] = trigger_change('batch_manager_register_filters', $bmf);
}
// filters from url
elseif (isset($_GET['filter'])) {
    if (!is_array($_GET['filter'])) {
        $_GET['filter'] = explode(',', is_scalar($_GET['filter']) ? (string) $_GET['filter'] : '');
    }

    /** @var array<string, mixed> $bmf */
    $bmf = [];

    foreach ($_GET['filter'] as $filter) {
        [$type, $value] = explode('-', is_scalar($filter) ? (string) $filter : '', 2);

        switch ($type) {
            case 'prefilter':
                if (preg_match('/^duplicates-?/', $value)) {
                    list(, $duplicate_field) = explode('-', $value, 2);
                    $bmf['prefilter'] = 'duplicates';

                    if (in_array($duplicate_field, ['filename', 'checksum', 'date', 'dimensions'])) {
                        $bmf['duplicates_'.$duplicate_field] = true;
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
                if (is_numeric($value) && in_array($value, \Piwigo\Core\Config::availablePermissionLevels())) {
                    $bmf['level'] = $value;
                }
                break;

            case 'search':
                $bmf['search'] = ['q' => $value];
                break;

            case 'dimension':
                // filter=dimension-w10..1000-h100..5000-r0.70..2
                $dim_map = ['w' => 'width','h' => 'height','r' => 'ratio'];
                /** @var array<string, string> $url_dim_filter */
                $url_dim_filter = is_array($bmf['dimension'] ?? null) ? $bmf['dimension'] : [];
                foreach (explode('-', $value) as $part) {
                    $values = explode('..', substr($part, 1));
                    if (isset($dim_map[$part[0]])) {
                        $type = $dim_map[$part[0]];

                        $filter_to_validate_for_type = [
                          'width' => FILTER_VALIDATE_INT,
                          'height' => FILTER_VALIDATE_INT,
                          'ratio' => FILTER_VALIDATE_FLOAT,
                        ];

                        $valid = true;
                        foreach ($values as $value) {
                            if (filter_var($value, $filter_to_validate_for_type[$type]) === false) {
                                $valid = false;
                            }
                        }

                        if ($valid) {
                            [$url_dim_filter['min_'.$type], $url_dim_filter['max_'.$type]] = $values;
                        }
                    }
                }
                $bmf['dimension'] = $url_dim_filter;
                break;

            case 'filesize':
                // filter=filesize-1..10
                $values = explode('..', $value);

                $valid = true;
                foreach ($values as $value) {
                    if (filter_var($value, FILTER_VALIDATE_FLOAT) === false) {
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
    $_SESSION['bulk_manager_filter'] = [
      'prefilter' => 'caddie',
      ];
}

// echo '<pre>'; print_r($_SESSION['bulk_manager_filter']); echo '</pre>';

/** @var array<string, mixed> $bmf */
$bmf = is_array($_SESSION['bulk_manager_filter']) ? $_SESSION['bulk_manager_filter'] : [];

// depending on the current filter (in session), we find the appropriate photos
$filter_sets = [];
$bmf_prefilter = is_string($bmf['prefilter'] ?? null) ? $bmf['prefilter'] : '';
if ($bmf_prefilter !== '') {
    switch ($bmf_prefilter) {
        case 'caddie':
            $query = '
SELECT element_id
  FROM '.CADDIE_TABLE.'
  WHERE user_id = '.$user['id'].'
;';
            $filter_sets[] = query2array($query, null, 'element_id');

            break;

        case 'favorites':
            $query = '
SELECT image_id
  FROM '.FAVORITES_TABLE.'
  WHERE user_id = '.$user['id'].'
;';
            $filter_sets[] = query2array($query, null, 'image_id');

            break;

        case 'last_import':
            $query = '
SELECT MAX(date_available) AS date
  FROM '.IMAGES_TABLE.'
;';
            $row = pwg_db_fetch_assoc(pwg_query($query));
            if (!empty($row['date'])) {
                $last_import_date = (string) $row['date'];
                $query = '
SELECT id
  FROM '.IMAGES_TABLE.'
  WHERE date_available BETWEEN '.pwg_db_get_recent_period_expression(1, $last_import_date).' AND \''.$last_import_date.'\'
;';
                $filter_sets[] = query2array($query, null, 'id');
            }

            break;

        case 'no_virtual_album':
            // we are searching elements not linked to any virtual category
            $query = '
 SELECT id
   FROM '.IMAGES_TABLE.'
 ;';
            $all_elements = query2array($query, null, 'id');

            $linked_to_virtual = [];

            $query = '
 SELECT id
   FROM '.CATEGORIES_TABLE.'
   WHERE dir IS NULL
 ;';
            $virtual_categories = query2array($query, null, 'id');
            if (!empty($virtual_categories)) {
                $query = '
 SELECT DISTINCT(image_id)
   FROM '.IMAGE_CATEGORY_TABLE.'
   WHERE category_id IN ('.implode(',', $virtual_categories).')
 ;';
                $linked_to_virtual = query2array($query, null, 'image_id');
            }

            $filter_sets[] = array_diff($all_elements, $linked_to_virtual);

            break;

        case 'no_album':
            $filter_sets[] = get_orphans();
            break;
        case 'no_sync_md5sum':
            $filter_sets[] = get_photos_no_md5sum();
            break;

        case 'no_tag':
            $query = '
SELECT
    id
  FROM '.IMAGES_TABLE.'
    LEFT JOIN '.IMAGE_TAG_TABLE.' ON id = image_id
  WHERE tag_id is null
;';
            $filter_sets[] = query2array($query, null, 'id');

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

            // TODO improve this algorithm, because GROUP_CONCAT is truncated at
            // 1024 chars. So if you have more than ~250 duplicates for a given
            // combination of "duplicates_on_fields" you won't get all the
            // duplicates.

            $query = '
SELECT
    GROUP_CONCAT(id) AS ids
  FROM '.IMAGES_TABLE;

            if (in_array('md5sum', $duplicates_on_fields)) {
                $query .= '
  WHERE md5sum IS NOT NULL
';
            }

            $query .= '
  GROUP BY '.implode(',', $duplicates_on_fields).'
  HAVING COUNT(*) > 1
;';
            $array_of_ids_string = query2array($query, null, 'ids');

            $ids = [];

            foreach ($array_of_ids_string as $ids_string) {
                $ids_string = rtrim((string) $ids_string, ',');
                $ids = array_merge($ids, explode(',', $ids_string));
            }

            $filter_sets[] = $ids;

            break;

        case 'all_photos':
            if (count($bmf) == 1) {// make the query only if this is the only filter
                $query = '
SELECT id
  FROM '.IMAGES_TABLE.'
  '.\Piwigo\Core\Config::orderBy();

                $filter_sets[] = query2array($query, null, 'id');
            }
            break;

        default:
            $filter_sets = trigger_change('perform_batch_manager_prefilters', $filter_sets, $bmf_prefilter);
            break;
    }
}

if (isset($bmf['category'])) {
    $categories = [];
    $bmf_category = is_numeric($bmf['category']) ? (int) $bmf['category'] : 0;

    // we need to check the category still exists (it may have been deleted since it was added in the session)
    $query = '
SELECT COUNT(*)
  FROM '.CATEGORIES_TABLE.'
  WHERE id = '.$bmf_category.'
;';
    [$counter] = pwg_db_fetch_row(pwg_query($query)) ?? [null];
    if (0 == $counter) {
        unset($_SESSION['bulk_manager_filter']);
        redirect(get_root_url().'admin.php?page='.(is_scalar($_GET['page']) ? (string) $_GET['page'] : ''));
    }

    if (isset($bmf['category_recursive'])) {
        $categories = get_subcat_ids([$bmf_category]);
    } else {
        $categories = [$bmf_category];
    }

    $query = '
 SELECT DISTINCT(image_id)
   FROM '.IMAGE_CATEGORY_TABLE.'
   WHERE category_id IN ('.implode(',', $categories).')
 ;';
    $filter_sets[] = query2array($query, null, 'image_id');
}

if (isset($bmf['level'])) {
    $operator = '=';
    if (isset($bmf['level_include_lower'])) {
        $operator = '<=';
    }
    $bmf_level = is_numeric($bmf['level']) ? (int) $bmf['level'] : 0;

    $query = '
SELECT id
  FROM '.IMAGES_TABLE.'
  WHERE level '.$operator.' '.$bmf_level.'
  '.\Piwigo\Core\Config::orderBy();

    $filter_sets[] = query2array($query, null, 'id');
}

if (!empty($bmf['tags'])) {
    $bmf_tags_raw = is_array($bmf['tags']) ? $bmf['tags'] : [];
    $bmf_tags = array_map(static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $bmf_tags_raw);
    $bmf_tag_mode = is_string($bmf['tag_mode'] ?? null) ? $bmf['tag_mode'] : 'AND';
    $filter_sets[] = get_image_ids_for_tags(
        $bmf_tags,
        $bmf_tag_mode,
        null,
        null,
        false // we don't apply permissions in administration screens
    );
}

if (isset($bmf['dimension'])) {
    $bmf_dimension = is_array($bmf['dimension']) ? $bmf['dimension'] : [];
    $where_clause = [];
    if (isset($bmf_dimension['min_width'])) {
        $where_clause[] = 'width >= '.(is_scalar($bmf_dimension['min_width']) ? (string) $bmf_dimension['min_width'] : '0');
    }
    if (isset($bmf_dimension['max_width'])) {
        $where_clause[] = 'width <= '.(is_scalar($bmf_dimension['max_width']) ? (string) $bmf_dimension['max_width'] : '0');
    }
    if (isset($bmf_dimension['min_height'])) {
        $where_clause[] = 'height >= '.(is_scalar($bmf_dimension['min_height']) ? (string) $bmf_dimension['min_height'] : '0');
    }
    if (isset($bmf_dimension['max_height'])) {
        $where_clause[] = 'height <= '.(is_scalar($bmf_dimension['max_height']) ? (string) $bmf_dimension['max_height'] : '0');
    }
    if (isset($bmf_dimension['min_ratio'])) {
        $where_clause[] = 'width/height >= '.(is_scalar($bmf_dimension['min_ratio']) ? (string) $bmf_dimension['min_ratio'] : '0');
    }
    if (isset($bmf_dimension['max_ratio'])) {
        // max_ratio is a floor value, so must be a bit increased
        $max_ratio = is_numeric($bmf_dimension['max_ratio']) ? (float) $bmf_dimension['max_ratio'] : 0.0;
        $where_clause[] = 'width/height < '.($max_ratio + 0.01);
    }

    $query = '
SELECT id
  FROM '.IMAGES_TABLE.'
  WHERE '.implode(' AND ', $where_clause).'
  '.\Piwigo\Core\Config::orderBy();

    $filter_sets[] = query2array($query, null, 'id');
}

if (isset($bmf['filesize'])) {
    $bmf_filesize = is_array($bmf['filesize']) ? $bmf['filesize'] : [];
    $where_clause = [];

    if (isset($bmf_filesize['min'])) {
        $fs_min = is_numeric($bmf_filesize['min']) ? (float) $bmf_filesize['min'] : 0.0;
        // to counter the effect of converting kB to mB and rounding, we need to go slightly lower for the minimum value
        $where_clause[] = 'filesize >= '.(($fs_min - 0.1) * 1024);
    }

    if (isset($bmf_filesize['max'])) {
        $fs_max = is_numeric($bmf_filesize['max']) ? (float) $bmf_filesize['max'] : 0.0;
        // to counter the effect of converting kB to mB and rounding, we need to go slightly higher for the maximum value
        $where_clause[] = 'filesize <= '.(($fs_max + 0.1) * 1024);
    }

    $query = '
SELECT id
  FROM '.IMAGES_TABLE.'
  WHERE '.implode(' AND ', $where_clause).'
  '.\Piwigo\Core\Config::orderBy();

    $filter_sets[] = query2array($query, null, 'id');
}

if (isset($bmf['search'])) {
    $bmf_search = is_array($bmf['search']) ? $bmf['search'] : [];
    $bmf_search_q = is_string($bmf_search['q'] ?? null) ? $bmf_search['q'] : '';
    if (strlen($bmf_search_q) > 0) {
        include_once(PHPWG_ROOT_PATH .'include/functions_search.inc.php');
        $res = get_quick_search_results_no_cache($bmf_search_q, ['permissions' => false]);
        $res_qs = is_array($res['qs'] ?? null) ? $res['qs'] : [];
        if (!empty($res['items']) && !empty($res_qs['unmatched_terms'])) {
            $unmatched = is_array($res_qs['unmatched_terms']) ? $res_qs['unmatched_terms'] : [];
            $template->assign('no_search_results', array_map(
                static fn (mixed $v): string => htmlspecialchars(is_scalar($v) ? (string) $v : ''),
                $unmatched
            ));
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


// +-----------------------------------------------------------------------+
// |                       first element to display                        |
// +-----------------------------------------------------------------------+

// $page['start'] contains the number of the first element in its
// category. For exampe, $page['start'] = 12 means we must show elements #12
// and $page['nb_images'] next elements

if (!isset($_REQUEST['start'])
    or !is_numeric($_REQUEST['start'])
    or $_REQUEST['start'] < 0
    or (isset($_REQUEST['display']) and 'all' == $_REQUEST['display'])) {
    $page['start'] = 0;
} else {
    $page['start'] = (int) $_REQUEST['start'];
}


// +-----------------------------------------------------------------------+
// |                                 Tabs                                  |
// +-----------------------------------------------------------------------+
$manager_link = get_root_url().'admin.php?page=batch_manager&amp;mode=';

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


// +-----------------------------------------------------------------------+
// |                              dimensions                               |
// +-----------------------------------------------------------------------+

$widths = [];
$heights = [];
$ratios = [];
$dimensions = [];

// get all width, height and ratios
$query = '
SELECT
  DISTINCT width, height
  FROM '.IMAGES_TABLE.'
  WHERE width IS NOT NULL
    AND height IS NOT NULL
;';
$result = pwg_query($query);

if (pwg_db_num_rows($result)) {
    while ($row = pwg_db_fetch_assoc($result)) {
        $row_width = is_numeric($row['width']) ? (int) $row['width'] : 0;
        $row_height = is_numeric($row['height']) ? (int) $row['height'] : 0;
        if ($row_width > 0 && $row_height > 0) {
            $widths[] = $row_width;
            $heights[] = $row_height;
            $ratios[] = floor($row_width / $row_height * 100) / 100;
        }
    }
}
if (empty($widths)) { // arbitrary values, only used when no photos on the gallery
    $widths = [600, 1920, 3500];
    $heights = [480, 1080, 2300];
    $ratios = [1.25, 1.52, 1.78];
}

foreach (['widths','heights','ratios'] as $type) {
    ${$type} = array_unique(${$type});
    sort(${$type});
    $dimensions[$type] = implode(',', ${$type});
}

$dimensions['bounds'] = [
  'min_width' => $widths[0],
  'max_width' => end($widths),
  'min_height' => $heights[0],
  'max_height' => end($heights),
  'min_ratio' => $ratios[0],
  'max_ratio' => end($ratios),
  ];

// find ratio categories
$ratio_categories = [
  'portrait' => [],
  'square' => [],
  'landscape' => [],
  'panorama' => [],
  ];

foreach ($ratios as $ratio) {
    if ($ratio < 0.95) {
        $ratio_categories['portrait'][] = $ratio;
    } elseif ($ratio >= 0.95 and $ratio <= 1.05) {
        $ratio_categories['square'][] = $ratio;
    } elseif ($ratio > 1.05 and $ratio < 2) {
        $ratio_categories['landscape'][] = $ratio;
    } elseif ($ratio >= 2) {
        $ratio_categories['panorama'][] = $ratio;
    }
}

foreach (array_keys($ratio_categories) as $type) {
    if (count($ratio_categories[$type]) > 0) {
        $dimensions['ratio_'.$type] = [
          'min' => $ratio_categories[$type][0],
          'max' => end($ratio_categories[$type]),
          ];
    }
}

// selected=bound if nothing selected
$dimensions['selected'] = [];
$bmf_dimension_sel = is_array($bmf['dimension'] ?? null) ? $bmf['dimension'] : [];
foreach (array_keys($dimensions['bounds']) as $type) {
    $dimensions['selected'][$type] = $bmf_dimension_sel[$type] ?? $dimensions['bounds'][$type];
}

$template->assign('dimensions', $dimensions);

// +-----------------------------------------------------------------------+
// | filesize                                                              |
// +-----------------------------------------------------------------------+

$filesizes = [];
$filesize = [];

$query = '
SELECT
  filesize
  FROM '.IMAGES_TABLE.'
  WHERE filesize IS NOT NULL
  GROUP BY filesize
;';
$result = pwg_query($query);

while ($row = pwg_db_fetch_assoc($result)) {
    $filesizes[] = sprintf('%.1f', (is_numeric($row['filesize']) ? (float) $row['filesize'] : 0.0) / 1024);
}

if (empty($filesizes)) { // arbitrary values, only used when no photos on the gallery
    $filesizes = [0, 1, 2, 5, 8, 15];
}

$filesizes = array_unique($filesizes);
sort($filesizes);

$filesize['list'] = implode(',', $filesizes);

$filesize['bounds'] = [
  'min' => $filesizes[0],
  'max' => end($filesizes),
  ];

// selected=bound if nothing selected
$bmf_filesize_sel = is_array($bmf['filesize'] ?? null) ? $bmf['filesize'] : [];
foreach (array_keys($filesize['bounds']) as $type) {
    $filesize['selected'][$type] = $bmf_filesize_sel[$type] ?? $filesize['bounds'][$type];
}

$template->assign('filesize', $filesize);

// +-----------------------------------------------------------------------+
// |                         open specific mode                            |
// +-----------------------------------------------------------------------+

// Build typed slider data for batchManagerFilter JSON block
$dim_widths  = is_string($dimensions['widths']) ? $dimensions['widths'] : '';
$dim_heights = is_string($dimensions['heights']) ? $dimensions['heights'] : '';
$dim_ratios  = is_string($dimensions['ratios']) ? $dimensions['ratios'] : '';
$sliders_json = [
    'widths' => [
        'values'   => array_map('floatval', explode(',', $dim_widths)),
        'selected' => ['min' => $dimensions['selected']['min_width'], 'max' => $dimensions['selected']['max_width']],
        'text'     => l10n('between %d and %d pixels'),
    ],
    'heights' => [
        'values'   => array_map('floatval', explode(',', $dim_heights)),
        'selected' => ['min' => $dimensions['selected']['min_height'], 'max' => $dimensions['selected']['max_height']],
        'text'     => l10n('between %d and %d pixels'),
    ],
    'ratios' => [
        'values'   => array_map('floatval', explode(',', $dim_ratios)),
        'selected' => ['min' => $dimensions['selected']['min_ratio'], 'max' => $dimensions['selected']['max_ratio']],
        'text'     => l10n('between %.2f and %.2f'),
    ],
    'filesizes' => [
        'values'   => array_map('floatval', explode(',', $filesize['list'])),
        'selected' => ['min' => $filesize['selected']['min'], 'max' => $filesize['selected']['max']],
        'text'     => l10n('between %s and %s MB'),
    ],
];

$filter_category_selected_val = isset($selected_category) ? $selected_category : null;

$template->assign('batch_filter_page_data_json', json_encode([
    'sliders'                => $sliders_json,
    'selected_filter_cat_ids' => $filter_category_selected_val !== null ? [$filter_category_selected_val] : [],
    'str_select_album'       => l10n('Select at least one album'),
    'str_select_tag'         => l10n('Select at least one tag'),
], JSON_HEX_TAG | JSON_UNESCAPED_UNICODE));

include(PHPWG_ROOT_PATH.'admin/batch_manager_'.(string) $page['tab'].'.php');
