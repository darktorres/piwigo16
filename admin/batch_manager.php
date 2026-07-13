<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

use Piwigo\Admin\BatchManager\FilterResolver;
use Piwigo\Admin\tabsheet;
use Piwigo\Core\AccessLevel;
use Piwigo\Core\ValidationPattern;
use Piwigo\Db\DbConnection;
use Piwigo\Db\Tables;
use Piwigo\Template\Template;

// Bootstrap globals, set by include/common.inc.php.
/**
 * @var array<string, mixed> $conf
 * @var array<string, mixed> $page
 * @var Template $template
 * @var array<string, mixed> $user
 */
global $conf, $page, $template, $user;

/**
 * Management of elements set. Elements can belong to a category or to the
 * user caddie.
 */
if (! defined('PHPWG_ROOT_PATH')) {
    die('Hacking attempt!');
}

include_once PHPWG_ROOT_PATH . 'admin/include/functions.php';

// +-----------------------------------------------------------------------+
// | Check Access and exit when user status is not ok                      |
// +-----------------------------------------------------------------------+

check_status(AccessLevel::Administrator);

check_input_parameter('selection', $_POST, true, ValidationPattern::ID);
check_input_parameter('display', $_REQUEST, false, '/^(\d+|all)$/');

// $user['id'] (the logged in / guest user id) is always numeric here (DB
// primary key, or $conf['guest_id']); narrow once and reuse at every query
// site below instead of re-reading the offset (each re-read is `mixed`).
$user_id = is_numeric($user['id'] ?? null) ? (int) $user['id'] : 0;

// $conf['available_permission_levels'] and $conf['order_by'] are read from
// a loosely-typed config bag at several sites below; narrow each once and
// reuse the local variable everywhere instead of re-reading the offset.
$available_permission_levels = is_array($conf['available_permission_levels'] ?? null) ? $conf['available_permission_levels'] : [];
$conf_order_by = is_string($conf['order_by'] ?? null) ? $conf['order_by'] : '';

// +-----------------------------------------------------------------------+
// | specific actions                                                      |
// +-----------------------------------------------------------------------+

// used both for the action-specific redirects below and for the
// "category no longer exists" redirect further down
$get_page = is_string($_GET['page'] ?? null) ? $_GET['page'] : '';

if (isset($_GET['action'])) {
    if ($_GET['action'] == 'empty_caddie') {
        $query = '
DELETE FROM ' . Tables::caddie() . '
  WHERE user_id = ' . $user_id . '
;';
        pwg_query($query);

        $_SESSION['page_infos'] = [
            l10n('Information data registered in database'),
        ];

        redirect(get_root_url() . 'admin.php?page=' . $get_page);
    }

    if ($_GET['action'] == 'delete_orphans' and isset($_GET['nb_orphans_deleted'])) {
        check_input_parameter('nb_orphans_deleted', $_GET, false, '/^\d+$/');
        $nb_orphans_deleted = is_numeric($_GET['nb_orphans_deleted']) ? (int) $_GET['nb_orphans_deleted'] : 0;

        if ($nb_orphans_deleted > 0) {
            if (! isset($_SESSION['page_infos']) || ! is_array($_SESSION['page_infos'])) {
                $_SESSION['page_infos'] = [];
            }
            $_SESSION['page_infos'][] = l10n_dec(
                '%d photo was deleted',
                '%d photos were deleted',
                $nb_orphans_deleted
            );

            redirect(get_root_url() . 'admin.php?page=' . $get_page);
        }
    }

    if ($_GET['action'] == 'sync_md5sum' and isset($_GET['nb_md5sum_added'])) {
        check_input_parameter('nb_md5sum_added', $_GET, false, '/^\d+$/');
        $nb_md5sum_added = is_numeric($_GET['nb_md5sum_added']) ? (int) $_GET['nb_md5sum_added'] : 0;

        if ($nb_md5sum_added > 0) {
            if (! isset($_SESSION['page_infos']) || ! is_array($_SESSION['page_infos'])) {
                $_SESSION['page_infos'] = [];
            }
            $_SESSION['page_infos'][] = l10n_dec(
                '%d checksums were added',
                '%d checksums were added',
                $nb_md5sum_added
            );

            redirect(get_root_url() . 'admin.php?page=' . $get_page);
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
    $_SESSION['bulk_manager_filter'] = [];

    if (isset($_POST['filter_prefilter_use'])) {
        $_SESSION['bulk_manager_filter']['prefilter'] = $_POST['filter_prefilter'];

        if ($_POST['filter_prefilter'] == 'duplicates') {
            $has_options = false;

            if (isset($_POST['filter_duplicates_checksum'])) {
                $_SESSION['bulk_manager_filter']['duplicates_checksum'] = true;
                $has_options = true;
            }

            if (isset($_POST['filter_duplicates_date'])) {
                $_SESSION['bulk_manager_filter']['duplicates_date'] = true;
                $has_options = true;
            }

            if (isset($_POST['filter_duplicates_dimensions'])) {
                $_SESSION['bulk_manager_filter']['duplicates_dimensions'] = true;
                $has_options = true;
            }

            if (! $has_options or isset($_POST['filter_duplicates_filename'])) {
                $_SESSION['bulk_manager_filter']['duplicates_filename'] = true;
            }
        }
    }

    if (isset($_POST['filter_category_use'])) {
        check_input_parameter('filter_category', $_POST, false, ValidationPattern::ID);

        $_SESSION['bulk_manager_filter']['category'] = $_POST['filter_category'];

        if (isset($_POST['filter_category_recursive'])) {
            $_SESSION['bulk_manager_filter']['category_recursive'] = true;
        }
    }

    if (isset($_POST['filter_tags_use'])) {
        $raw_filter_tags = $_POST['filter_tags'] ?? '';
        if (is_array($raw_filter_tags)) {
            $filter_tags = [];
            foreach ($raw_filter_tags as $raw_filter_tag) {
                if (is_string($raw_filter_tag)) {
                    $filter_tags[] = $raw_filter_tag;
                }
            }
        } else {
            $filter_tags = is_string($raw_filter_tags) ? $raw_filter_tags : '';
        }
        $_SESSION['bulk_manager_filter']['tags'] = get_tag_ids($filter_tags, false);

        if (isset($_POST['tag_mode']) and in_array($_POST['tag_mode'], ['AND', 'OR'])) {
            $_SESSION['bulk_manager_filter']['tag_mode'] = $_POST['tag_mode'];
        }
    }

    if (isset($_POST['filter_level_use'])) {
        check_input_parameter('filter_level', $_POST, false, '/^\d+$/');

        if (in_array($_POST['filter_level'], $available_permission_levels)) {
            $_SESSION['bulk_manager_filter']['level'] = $_POST['filter_level'];

            if (isset($_POST['filter_level_include_lower'])) {
                $_SESSION['bulk_manager_filter']['level_include_lower'] = true;
            }
        }
    }

    if (isset($_POST['filter_dimension_use'])) {
        foreach (['min_width', 'max_width', 'min_height', 'max_height'] as $type) {
            if (filter_var($_POST['filter_dimension_' . $type], FILTER_VALIDATE_INT) !== false) {
                $_SESSION['bulk_manager_filter']['dimension'][$type] = $_POST['filter_dimension_' . $type];
            }
        }
        foreach (['min_ratio', 'max_ratio'] as $type) {
            if (filter_var($_POST['filter_dimension_' . $type], FILTER_VALIDATE_FLOAT) !== false) {
                $_SESSION['bulk_manager_filter']['dimension'][$type] = $_POST['filter_dimension_' . $type];
            }
        }
    }

    if (isset($_POST['filter_filesize_use'])) {
        foreach (['min', 'max'] as $type) {
            if (filter_var($_POST['filter_filesize_' . $type], FILTER_VALIDATE_FLOAT) !== false) {
                $_SESSION['bulk_manager_filter']['filesize'][$type] = $_POST['filter_filesize_' . $type];
            }
        }
    }

    if (isset($_POST['filter_search_use'])) {
        // $_SESSION['bulk_manager_filter'] was reset to [] at the top of
        // this block, so 'search' can't already exist here.
        $_SESSION['bulk_manager_filter']['search'] = [];
        $_SESSION['bulk_manager_filter']['search']['q'] = $_POST['q'];
    }

    $_SESSION['bulk_manager_filter'] = trigger_change('batch_manager_register_filters', $_SESSION['bulk_manager_filter']);
}
// filters from url
elseif (isset($_GET['filter'])) {
    if (! is_array($_GET['filter'])) {
        $_GET['filter'] = explode(',', is_scalar($_GET['filter']) ? (string) $_GET['filter'] : '');
    }

    // Built up locally (instead of writing straight into
    // $_SESSION['bulk_manager_filter']) because PHPStan cannot keep a
    // precise array shape for a superglobal offset that is mutated with
    // dynamic keys across loop iterations; the whole array is committed to
    // the session once, after the loop.
    /** @var array<string, mixed> $url_filter */
    $url_filter = [];

    foreach ($_GET['filter'] as $filter) {
        [$type, $value] = explode('-', is_scalar($filter) ? (string) $filter : '', 2);

        switch ($type) {
            case 'prefilter':
                if ((bool) preg_match('/^duplicates-?/', $value)) {
                    [, $duplicate_field] = explode('-', $value, 2);
                    $url_filter['prefilter'] = 'duplicates';

                    if (in_array($duplicate_field, ['filename', 'checksum', 'date', 'dimensions'])) {
                        $url_filter['duplicates_' . $duplicate_field] = true;
                    }
                } else {
                    $url_filter['prefilter'] = $value;
                }
                break;

            case 'album': case 'category': case 'cat':
                if (is_numeric($value)) {
                    $url_filter['category'] = $value;
                }
                break;

            case 'tag':
                if (is_numeric($value)) {
                    $url_filter['tags'] = [$value];
                    $url_filter['tag_mode'] = 'AND';
                }
                break;

            case 'level':
                if (is_numeric($value) && in_array($value, $available_permission_levels)) {
                    $url_filter['level'] = $value;
                }
                break;

            case 'search':
                $url_filter['search'] = [
                    'q' => $value,
                ];
                break;

            case 'dimension':
                // filter=dimension-w10..1000-h100..5000-r0.70..2
                $dim_map = [
                    'w' => 'width',
                    'h' => 'height',
                    'r' => 'ratio',
                ];
                // accumulated locally: a single 'dimension' filter token can
                // set width/height/ratio bounds together, across several
                // iterations of this inner loop.
                $dimension = is_array($url_filter['dimension'] ?? null) ? $url_filter['dimension'] : [];
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
                        foreach ($values as $bound_value) {
                            if (filter_var($bound_value, $filter_to_validate_for_type[$type]) === false) {
                                $valid = false;
                            }
                        }

                        if ($valid) {
                            [
                                $dimension['min_' . $type],
                                $dimension['max_' . $type]
                            ] = $values;
                        }
                    }
                }
                $url_filter['dimension'] = $dimension;
                break;

            case 'filesize':
                // filter=filesize-1..10
                $values = explode('..', $value);

                $valid = true;
                foreach ($values as $bound_value) {
                    if (filter_var($bound_value, FILTER_VALIDATE_FLOAT) === false) {
                        $valid = false;
                    }
                }

                if ($valid) {
                    $url_filter['filesize'] = [
                        'min' => $values[0],
                        'max' => $values[1],
                    ];
                }

                break;

            default:
                $url_filter_result = trigger_change('batch_manager_url_filter', $url_filter, $filter);
                $url_filter = is_array($url_filter_result) ? $url_filter_result : $url_filter;
                break;
        }
    }

    $_SESSION['bulk_manager_filter'] = $url_filter;
}

if (empty($_SESSION['bulk_manager_filter'])) {
    $_SESSION['bulk_manager_filter'] = [
        'prefilter' => 'caddie',
    ];
}

if (! is_array($_SESSION['bulk_manager_filter'])) {
    // Defensive: bulk_manager_filter is only ever written as an array by
    // this file (the $_POST/$_GET branches above, or the default fallback
    // just above); this guards against corrupted/foreign session state,
    // and lets PHPStan track a real array shape for the reads below.
    $_SESSION['bulk_manager_filter'] = [
        'prefilter' => 'caddie',
    ];
}

/** @var array<string, mixed> $bulk_filter */
$bulk_filter = $_SESSION['bulk_manager_filter'];

// echo '<pre>'; print_r($bulk_filter); echo '</pre>';

// depending on the current filter (in session), we find the appropriate photos
$filter_resolver = new FilterResolver(DbConnection::build());

$filter_sets = [];
if (isset($bulk_filter['prefilter']) && is_string($bulk_filter['prefilter'])) {
    $prefilter = $bulk_filter['prefilter'];

    // $duplicates_on_fields is only ever set for the 'duplicates' prefilter
    // (matching the legacy switch's own scoping) -- admin/batch_manager_
    // global.php reads it back (via PHP's shared include() scope) for its
    // own duplicates-mode thumbnail ordering.
    if ($prefilter === 'duplicates') {
        $duplicates_on_fields = $filter_resolver->duplicateFieldsFromFilter($bulk_filter);
    }

    $prefilter_result = match ($prefilter) {
        // get_orphans()/get_photos_no_md5sum() are existing, already-tested
        // free functions -- not duplicated into FilterResolver.
        'no_album' => get_orphans(),
        'no_sync_md5sum' => get_photos_no_md5sum(),
        default => $filter_resolver->resolvePrefilter($prefilter, $bulk_filter, $user_id, $conf_order_by),
    };

    if ($prefilter_result !== null) {
        $filter_sets[] = $prefilter_result;
    } else {
        $filter_sets = trigger_change('perform_batch_manager_prefilters', $filter_sets, $prefilter);
        if (! is_array($filter_sets)) {
            // Plugin handlers must return the (possibly extended) list of id
            // sets; fall back to an empty set of filters rather than
            // propagating a non-array value into array-only code below.
            $filter_sets = [];
        }
    }
}

if (isset($bulk_filter['category']) && is_numeric($bulk_filter['category'])) {
    $category_id = (int) $bulk_filter['category'];

    // we need to check the category still exists (it may have been deleted since it was added in the session)
    if (! $filter_resolver->categoryExists($category_id)) {
        unset($_SESSION['bulk_manager_filter']);
        redirect(get_root_url() . 'admin.php?page=' . $get_page);
    }

    $categories = isset($bulk_filter['category_recursive'])
        ? get_subcat_ids([$category_id])
        : [$category_id];
    $categories = array_values(array_map(intval(...), array_filter($categories, is_numeric(...))));

    $filter_sets[] = $filter_resolver->categoryImageIds($categories);
}

if (isset($bulk_filter['level']) && is_numeric($bulk_filter['level'])) {
    $filter_sets[] = $filter_resolver->levelPhotoIds(
        (int) $bulk_filter['level'],
        isset($bulk_filter['level_include_lower']),
        $conf_order_by
    );
}

if (! empty($bulk_filter['tags']) && is_array($bulk_filter['tags'])) {
    $filter_tag_ids = [];
    foreach ($bulk_filter['tags'] as $filter_tag_id) {
        if (is_numeric($filter_tag_id)) {
            $filter_tag_ids[] = (int) $filter_tag_id;
        }
    }

    $filter_tag_mode = is_string($bulk_filter['tag_mode'] ?? null) ? $bulk_filter['tag_mode'] : 'AND';

    $filter_sets[] = get_image_ids_for_tags(
        $filter_tag_ids,
        $filter_tag_mode,
        null,
        null,
        false // we don't apply permissions in administration screens
    );
}

if (isset($bulk_filter['dimension']) && is_array($bulk_filter['dimension'])) {
    // $bulk_filter is only known as array<string, mixed>, so a nested array
    // offset only narrows to array<mixed, mixed> after is_array() --
    // rebuild with only string keys so dimensionPhotoIds()'s declared
    // array<string, mixed> parameter type-checks against a real, verified
    // shape rather than a trust-me cast.
    $filter_dimension = [];
    foreach ($bulk_filter['dimension'] as $dimension_key => $dimension_value) {
        if (is_string($dimension_key)) {
            $filter_dimension[$dimension_key] = $dimension_value;
        }
    }
    $dimension_ids = $filter_resolver->dimensionPhotoIds($filter_dimension, $conf_order_by);
    if ($dimension_ids !== null) {
        $filter_sets[] = $dimension_ids;
    }
}

if (isset($bulk_filter['filesize']) && is_array($bulk_filter['filesize'])) {
    $filter_filesize = [];
    foreach ($bulk_filter['filesize'] as $filesize_key => $filesize_value) {
        if (is_string($filesize_key)) {
            $filter_filesize[$filesize_key] = $filesize_value;
        }
    }
    $filesize_ids = $filter_resolver->filesizePhotoIds($filter_filesize, $conf_order_by);
    if ($filesize_ids !== null) {
        $filter_sets[] = $filesize_ids;
    }
}

if (isset($bulk_filter['search']) && is_array($bulk_filter['search'])
    && isset($bulk_filter['search']['q']) && is_string($bulk_filter['search']['q'])
    && (bool) strlen($bulk_filter['search']['q'])) {
    include_once PHPWG_ROOT_PATH . 'include/functions_search.inc.php';
    $res = get_quick_search_results_no_cache($bulk_filter['search']['q'], [
        'permissions' => false,
    ]);
    if (! empty($res['items']) && is_array($res['qs']) && ! empty($res['qs']['unmatched_terms']) && is_array($res['qs']['unmatched_terms'])) {
        $unmatched_terms = array_filter($res['qs']['unmatched_terms'], is_string(...));
        $template->assign('no_search_results', array_map(htmlspecialchars(...), $unmatched_terms));
    }
    $filter_sets[] = is_array($res['items']) ? $res['items'] : [];
}

$filter_sets = trigger_change('batch_manager_perform_filters', $filter_sets, $bulk_filter);
if (! is_array($filter_sets)) {
    // Plugin handlers must return the (possibly extended) list of id sets;
    // fall back to an empty set of filters rather than propagating a
    // non-array value into the array-only code below.
    $filter_sets = [];
}

$current_set = array_shift($filter_sets);
// filter sets are always image id lists (either this file's own search
// results or a plugin-returned replacement set), so only scalar elements
// are ever meaningful here -- array_intersect() also requires
// string-castable values.
$current_set = is_array($current_set) ? array_filter($current_set, is_scalar(...)) : [];
foreach ($filter_sets as $set) {
    if (is_array($set)) {
        $current_set = array_intersect($current_set, array_filter($set, is_scalar(...)));
    }
}
$page['cat_elements_id'] = empty($current_set) ? [] : $current_set;

// +-----------------------------------------------------------------------+
// |                       first element to display                        |
// +-----------------------------------------------------------------------+

// $page['start'] contains the number of the first element in its
// category. For exampe, $page['start'] = 12 means we must show elements #12
// and $page['nb_images'] next elements

if (! isset($_REQUEST['start'])
    or ! is_numeric($_REQUEST['start'])
    or $_REQUEST['start'] < 0
    or (isset($_REQUEST['display']) and $_REQUEST['display'] == 'all')) {
    $page['start'] = 0;
} else {
    $page['start'] = $_REQUEST['start'];
}

// +-----------------------------------------------------------------------+
// |                                 Tabs                                  |
// +-----------------------------------------------------------------------+
$manager_link = get_root_url() . 'admin.php?page=batch_manager&amp;mode=';

if (isset($_GET['mode'])) {
    check_input_parameter('mode', $_GET, false, '/^(global|unit)$/');
    $page['tab'] = is_string($_GET['mode']) ? $_GET['mode'] : 'global';
} else {
    $page['tab'] = 'global';
}

$tabsheet = new tabsheet();
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
  FROM ' . Tables::images() . '
  WHERE width IS NOT NULL
    AND height IS NOT NULL
;';
$result = pwg_query($query);

if ((bool) pwg_db_num_rows($result)) {
    while ((bool) ($row = pwg_db_fetch_assoc($result))) {
        if (is_numeric($row['width']) && is_numeric($row['height']) && $row['width'] > 0 && $row['height'] > 0) {
            $widths[] = $row['width'];
            $heights[] = $row['height'];
            $ratios[] = floor((float) $row['width'] / (float) $row['height'] * 100) / 100;
        }
    }
}
if (empty($widths)) { // arbitrary values, only used when no photos on the gallery
    $widths = [600, 1920, 3500];
    $heights = [480, 1080, 2300];
    $ratios = [1.25, 1.52, 1.78];
}

$dimension_arrays = [
    'widths' => &$widths,
    'heights' => &$heights,
    'ratios' => &$ratios,
];
foreach ($dimension_arrays as $type => &$dimension_values) {
    $dimension_values = array_unique($dimension_values);
    sort($dimension_values);
    $dimensions[$type] = implode(',', $dimension_values);
}
unset($dimension_values);

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
        $dimensions['ratio_' . $type] = [
            'min' => $ratio_categories[$type][0],
            'max' => end($ratio_categories[$type]),
        ];
    }
}

// selected=bound if nothing selected
$selected_dimension = isset($bulk_filter['dimension']) && is_array($bulk_filter['dimension']) ? $bulk_filter['dimension'] : [];
foreach (array_keys($dimensions['bounds']) as $type) {
    $dimensions['selected'][$type] = $selected_dimension[$type]
      ?? $dimensions['bounds'][$type]
    ;
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
  FROM ' . Tables::images() . '
  WHERE filesize IS NOT NULL
  GROUP BY filesize
;';
$result = pwg_query($query);

while ((bool) ($row = pwg_db_fetch_assoc($result))) {
    if (is_numeric($row['filesize'])) {
        $filesizes[] = sprintf('%.1f', (float) $row['filesize'] / 1024);
    }
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
$selected_filesize = isset($bulk_filter['filesize']) && is_array($bulk_filter['filesize']) ? $bulk_filter['filesize'] : [];
foreach (array_keys($filesize['bounds']) as $type) {
    $filesize['selected'][$type] = $selected_filesize[$type]
      ?? $filesize['bounds'][$type]
    ;
}

$template->assign('filesize', $filesize);

// +-----------------------------------------------------------------------+
// |                         open specific mode                            |
// +-----------------------------------------------------------------------+

include PHPWG_ROOT_PATH . 'admin/batch_manager_' . $page['tab'] . '.php';
