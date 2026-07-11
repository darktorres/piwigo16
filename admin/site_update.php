<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

use Piwigo\Admin\tabsheet;
use Piwigo\Site\LocalSiteReader;

if (! defined('PHPWG_ROOT_PATH')) {
    die('Hacking attempt!');
}

// Bootstrap globals, set by include/common.inc.php.
global $conf, $logger, $template, $user;
/**
 * @var array<string, mixed> $conf
 * @var \Logger $logger
 * @var \Template $template
 * @var array<string, mixed> $user
 */

include_once PHPWG_ROOT_PATH . 'admin/include/functions.php';

// +-----------------------------------------------------------------------+
// | Check Access and exit when user status is not ok                      |
// +-----------------------------------------------------------------------+

if (! (bool) $conf['enable_synchronization']) {
    die('synchronization is disabled');
}

check_status(ACCESS_ADMINISTRATOR);

if (! is_numeric($_GET['site'])) {
    die('site param missing or invalid');
}
$site_id = (int) $_GET['site'];

$query = '
SELECT galleries_url
  FROM ' . SITES_TABLE . '
  WHERE id = ' . $site_id;
$row = pwg_db_fetch_row(pwg_query($query));
$site_url = $row !== null ? $row[0] : null;
if (! isset($site_url)) {
    die('site ' . $site_id . ' does not exist');
}
$site_is_remote = url_is_remote($site_url);

$row = pwg_db_fetch_row(pwg_query('SELECT NOW();'));
assert($row !== null);
[$dbnow] = $row;
define('CURRENT_DATE', $dbnow);

$error_labels = [
    'PWG-UPDATE-1' => [
        l10n('wrong filename'),
        l10n('The name of directories and files must be composed of letters, numbers, "-", "_" or "."'),
    ],
    'PWG-ERROR-NO-FS' => [
        l10n('File/directory read error'),
        l10n('The file or directory cannot be accessed (either it does not exist or the access is denied)'),
    ],
];
$errors = [];
$infos = [];
$counts = [
    'new_categories' => 0,
    'del_categories' => 0,
    'del_elements' => 0,
    'new_elements' => 0,
    'upd_elements' => 0,
];
// $basedir/$db_categories/$db_fulldirs/$to_delete are always set by the
// "directories / categories" block below whenever sync is 'dirs' or 'files'
// — the only values the "files / elements" block (which reads them) also
// requires.
$basedir = '';
$db_categories = [];
$db_fulldirs = [];
$to_delete = [];
// $simulate is only set once $_POST['submit'] is set (see below), which
// also gates every later block that reads it.
$simulate = false;

if ($site_is_remote) {
    fatal_error('remote sites not supported');
} else {
    $site_reader = new LocalSiteReader($site_url);
}

/** @var array<string, mixed> $page */
if (isset($page['no_md5sum_number'])) {
    $template->assign(
        [
            'save_error' => '<a href="admin.php?page=batch_manager&amp;filter=prefilter-no_sync_md5sum">' . l10n('Some checksums are missing.') . '<i class="icon-right"></i></a>',
        ]
    );

}

// +-----------------------------------------------------------------------+
// | tabs                                                                  |
// +-----------------------------------------------------------------------+

$my_base_url = get_root_url() . 'admin.php?page=';

$tabsheet = new tabsheet();
$tabsheet->set_id('site_update');
$tabsheet->select('synchronization');
$tabsheet->assign();

// +-----------------------------------------------------------------------+
// | Quick sync                                                            |
// +-----------------------------------------------------------------------+

if (isset($_GET['quick_sync'])) {
    check_pwg_token();

    $_POST['sync'] = 'files';
    $_POST['display_info'] = '1';
    $_POST['add_to_caddie'] = '1';
    $_POST['privacy_level'] = '0';
    $_POST['sync_meta'] = '1';
    $_POST['simulate'] = '0';
    $_POST['subcats-included'] = '1';
    $_POST['submit'] = 'Quick Local Synchronization';
}

$general_failure = true;
if (isset($_POST['submit'])) {

    if ($site_reader->open()) {
        $general_failure = false;
    }

    // shall we simulate only
    if (isset($_POST['simulate']) and $_POST['simulate'] == 1) {
        $simulate = true;
    } else {
        $simulate = false;
    }
}

// +-----------------------------------------------------------------------+
// |                      directories / categories                         |
// +-----------------------------------------------------------------------+
if (isset($_POST['submit'])
    and ($_POST['sync'] == 'dirs' or $_POST['sync'] == 'files')
    and ! $general_failure) {
    $start = get_moment();
    // which categories to update ?
    $query = '
SELECT id, uppercats, global_rank, status, visible
  FROM ' . CATEGORIES_TABLE . '
  WHERE dir IS NOT NULL
    AND site_id = ' . $site_id;
    if (isset($_POST['cat']) and is_numeric($_POST['cat'])) {
        if (isset($_POST['subcats-included']) and $_POST['subcats-included'] == 1) {
            $query .= '
    AND uppercats ' . DB_REGEX_OPERATOR . ' \'(^|,)' . $_POST['cat'] . '(,|$)\'
';
        } else {
            $query .= '
    AND id = ' . $_POST['cat'] . '
';
        }
    }
    // hash_from_query()'s declared return type is under-typed (array<int|string,
    // mixed>) — each row is really the fetch_assoc() result for id, uppercats,
    // global_rank, status, visible (all string|null), but this same array is
    // later reused (below) to hold freshly-inserted categories keyed by their
    // new int id, whose entries additionally carry an int 'parent' and int
    // 'id'/'rank'/'global_rank' fields. array<string, mixed> is the honest
    // common shape for both origins; individual fields are narrowed with
    // is_string()/is_int() at each point of use below.
    $db_categories = hash_from_query($query, 'id');
    /** @var array<int|string, array<string, mixed>> $db_categories */

    // get categort full directories in an array for comparison with file
    // system directory tree
    $db_fulldirs = get_fulldirs(array_map(intval(...), array_keys($db_categories)));

    // what is the base directory to search file system sub-directories ?
    if (isset($_POST['cat']) and is_numeric($_POST['cat'])) {
        $basedir = $db_fulldirs[(int) $_POST['cat']];
    } else {
        // preg_replace() can return null on a regex engine error; the base
        // directory is never allowed to be null downstream (LocalSiteReader
        // expects a real string path).
        $basedir = preg_replace('#/*$#', '', $site_url) ?? '';
    }

    // we need to have fulldirs as keys to make efficient comparison
    $db_fulldirs = array_flip($db_fulldirs);

    // finding next rank for each id_uppercat. By default, each category id
    // has 1 for next rank on its sub-categories to create
    $next_rank = [
        'NULL' => 1,
    ];

    $query = '
SELECT id
  FROM ' . CATEGORIES_TABLE;
    $result = pwg_query($query);
    while ((bool) ($row = pwg_db_fetch_assoc($result))) {
        // id is a NOT NULL primary key; skip defensively rather than use a
        // null value as an invalid array key.
        if ($row['id'] === null) {
            continue;
        }
        $next_rank[$row['id']] = 1;
    }

    // let's see if some categories already have some sub-categories...
    $query = '
SELECT id_uppercat, MAX(`rank`)+1 AS next_rank
  FROM ' . CATEGORIES_TABLE . '
  GROUP BY id_uppercat';
    $result = pwg_query($query);
    while ((bool) ($row = pwg_db_fetch_assoc($result))) {
        // for the id_uppercat NULL, we write 'NULL' and not the empty string
        if (! isset($row['id_uppercat']) or $row['id_uppercat'] == '') {
            $row['id_uppercat'] = 'NULL';
        }
        // next_rank is a computed "MAX(`rank`)+1" aggregate, always a
        // positive numeric string; fall back to the same default used above
        // for categories without any sub-category yet.
        $row_next_rank = $row['next_rank'];
        $next_rank[$row['id_uppercat']] = is_numeric($row_next_rank) ? (int) $row_next_rank : 1;
    }

    // next category id available
    $next_id = pwg_db_nextval('id', CATEGORIES_TABLE);

    // retrieve sub-directories fulldirs from the site reader
    // get_full_directories() is declared to return mixed[], but in practice
    // it always forwards get_fs_directories()'s string[] result; filter
    // defensively so this array's element type is a real string.
    $fs_fulldirs = array_filter($site_reader->get_full_directories($basedir), is_string(...));

    // get_full_directories doesn't include the base directory, so if it's a
    // category directory, we need to include it in our array
    if (isset($_POST['cat'])) {
        $fs_fulldirs[] = $basedir;
    }
    // If $_POST['subcats-included'] != 1 ("Search in sub-albums" is unchecked)
    // $db_fulldirs doesn't include any subdirectories and $fs_fulldirs does
    // So $fs_fulldirs will be limited to the selected basedir
    // (if that one is in $fs_fulldirs)
    if (! isset($_POST['subcats-included']) or $_POST['subcats-included'] != 1) {
        $fs_fulldirs = array_intersect($fs_fulldirs, array_keys($db_fulldirs));
    }
    $inserts = [];
    // new categories are the directories not present yet in the database
    foreach (array_diff($fs_fulldirs, array_keys($db_fulldirs)) as $fulldir) {
        $dir = basename($fulldir);
        // sync_chars_regex is a config default, always a regex string; treat
        // a non-string config value the same as a non-matching name below.
        $sync_chars_regex = $conf['sync_chars_regex'];
        if (is_string($sync_chars_regex) && (bool) preg_match($sync_chars_regex, $dir)) {
            $insert = [
                'id' => $next_id++,
                'dir' => $dir,
                'name' => str_replace('_', ' ', $dir),
                'site_id' => $site_id,
                'commentable' => boolean_to_string($conf['newcat_default_commentable']),
                'status' => $conf['newcat_default_status'],
                'visible' => boolean_to_string($conf['newcat_default_visible']),
            ];

            if (isset($db_fulldirs[dirname($fulldir)])) {
                $parent = $db_fulldirs[dirname($fulldir)];

                // $db_categories[$parent] can be either a raw DB row
                // (uppercats/global_rank as string|null) or a previously
                // inserted category from an earlier iteration of this same
                // loop (uppercats as string, global_rank as int|string) —
                // narrow to the concatenable subset either way.
                $parent_uppercats = $db_categories[$parent]['uppercats'] ?? null;
                $parent_uppercats = is_string($parent_uppercats) ? $parent_uppercats : '';
                $parent_global_rank = $db_categories[$parent]['global_rank'] ?? null;
                if (! is_string($parent_global_rank) && ! is_int($parent_global_rank)) {
                    $parent_global_rank = '';
                }

                $insert['id_uppercat'] = $parent;
                $insert['uppercats'] =
                  $parent_uppercats . ',' . $insert['id'];
                $insert['rank'] = $next_rank[$parent]++;
                $insert['global_rank'] =
                  $parent_global_rank . '.' . $insert['rank'];
                if ($db_categories[$parent]['status'] == 'private') {
                    $insert['status'] = 'private';
                }
                if ($db_categories[$parent]['visible'] == 'false') {
                    $insert['visible'] = 'false';
                }
            } else {
                $insert['uppercats'] = $insert['id'];
                $insert['rank'] = $next_rank['NULL']++;
                $insert['global_rank'] = $insert['rank'];
            }

            $inserts[] = $insert;
            $infos[] = [
                'path' => $fulldir,
                'info' => l10n('added'),
            ];

            // add the new category to $db_categories and $db_fulldirs array
            $db_categories[$insert['id']] =
              [
                  'id' => $insert['id'],
                  'parent' => $parent ?? null,
                  'status' => $insert['status'],
                  'visible' => $insert['visible'],
                  'uppercats' => $insert['uppercats'],
                  'global_rank' => $insert['global_rank'],
              ];
            $db_fulldirs[$fulldir] = $insert['id'];
            $next_rank[$insert['id']] = 1;
        } else {
            $errors[] = [
                'path' => $fulldir,
                'type' => 'PWG-UPDATE-1',
            ];
        }
    }

    if (count($inserts) > 0) {
        if (! $simulate) {
            $dbfields = [
                'id', 'dir', 'name', 'site_id', 'id_uppercat', 'uppercats', 'commentable',
                'visible', 'status', 'rank', 'global_rank',
            ];
            mass_inserts(CATEGORIES_TABLE, $dbfields, $inserts);

            // add default permissions to categories
            $category_ids = [];
            $category_up = [];
            foreach ($inserts as $category) {
                $category_ids[] = $category['id'];
                if (! empty($category['id_uppercat'])) {
                    $category_up[] = $category['id_uppercat'];
                }
            }

            pwg_activity('album', $category_ids, 'add', [
                'sync' => true,
            ]);

            $category_up = implode(',', array_unique($category_up));
            if ((bool) $conf['inheritance_by_default'] and ! empty($category_up)) {
                // predeclared so both stay real arrays below even if a
                // query below ever returns an empty/falsy result set.
                $granted_grps = [];
                $granted_users = [];
                $query = '
          SELECT *
          FROM ' . GROUP_ACCESS_TABLE . '
          WHERE cat_id IN (' . $category_up . ')
        ;';
                $result = pwg_query($query);
                if (! empty($result)) {
                    $granted_grps = [];
                    while ((bool) ($row = pwg_db_fetch_assoc($result))) {
                        // cat_id is a NOT NULL foreign key; skip defensively
                        // if it's ever missing/non-numeric rather than using
                        // it as an invalid array key.
                        $cat_id = $row['cat_id'];
                        if (! is_numeric($cat_id)) {
                            continue;
                        }
                        $cat_id = (int) $cat_id;
                        if (! isset($granted_grps[$cat_id])) {
                            $granted_grps[$cat_id] = [];
                        }
                        // TODO: explanaition
                        array_push(
                            $granted_grps,
                            [
                                $cat_id => array_push($granted_grps[$cat_id], $row['group_id']),
                            ]
                        );
                    }
                }
                $query = '
          SELECT *
          FROM ' . USER_ACCESS_TABLE . '
          WHERE cat_id IN (' . $category_up . ')
        ;';
                $result = pwg_query($query);
                if (! empty($result)) {
                    $granted_users = [];
                    while ((bool) ($row = pwg_db_fetch_assoc($result))) {
                        // cat_id is a NOT NULL foreign key; skip defensively
                        // if it's ever missing/non-numeric rather than using
                        // it as an invalid array key.
                        $cat_id = $row['cat_id'];
                        if (! is_numeric($cat_id)) {
                            continue;
                        }
                        $cat_id = (int) $cat_id;
                        if (! isset($granted_users[$cat_id])) {
                            $granted_users[$cat_id] = [];
                        }
                        // TODO: explanaition
                        array_push(
                            $granted_users,
                            [
                                $cat_id => array_push($granted_users[$cat_id], $row['user_id']),
                            ]
                        );
                    }
                }
                $insert_granted_users = [];
                $insert_granted_grps = [];
                foreach ($category_ids as $ids) {
                    // 'parent' only exists on the freshly-inserted-category
                    // shape of $db_categories entries (see above); narrow to
                    // int so it's safe to use as an array key below.
                    $parent_id = $db_categories[$ids]['parent'] ?? null;
                    $parent_id = is_int($parent_id) ? $parent_id : null;
                    while ($parent_id !== null && in_array($parent_id, $category_ids)) {
                        $parent_id = $db_categories[$parent_id]['parent'] ?? null;
                        $parent_id = is_int($parent_id) ? $parent_id : null;
                    }
                    if ($db_categories[$ids]['status'] == 'private' and $parent_id !== null) {
                        if (isset($granted_grps[$parent_id])) {
                            foreach ($granted_grps[$parent_id] as $granted_grp) {
                                $insert_granted_grps[] = [
                                    'group_id' => $granted_grp,
                                    'cat_id' => $ids,
                                ];
                            }
                        }
                        if (isset($granted_users[$parent_id])) {
                            foreach ($granted_users[$parent_id] as $granted_user) {
                                $insert_granted_users[] = [
                                    'user_id' => $granted_user,
                                    'cat_id' => $ids,
                                ];
                            }
                        }
                    }
                }
                mass_inserts(GROUP_ACCESS_TABLE, ['group_id', 'cat_id'], $insert_granted_grps);
                $insert_granted_users = array_unique($insert_granted_users, SORT_REGULAR);
                mass_inserts(USER_ACCESS_TABLE, ['user_id', 'cat_id'], $insert_granted_users);
            } else {
                add_permission_on_category($category_ids, get_admins());
            }
        }

        $counts['new_categories'] = count($inserts);
    }

    // to delete categories
    $to_delete = [];
    $to_delete_derivative_dirs = [];

    foreach (array_diff(array_keys($db_fulldirs), $fs_fulldirs) as $fulldir) {
        $to_delete[] = $db_fulldirs[$fulldir];
        unset($db_fulldirs[$fulldir]);

        $infos[] = [
            'path' => $fulldir,
            'info' => l10n('deleted'),
        ];

        if (substr_compare($fulldir, '../', 0, 3) == 0) {
            $fulldir = substr($fulldir, 3);
        }
        $to_delete_derivative_dirs[] = PHPWG_ROOT_PATH . PWG_DERIVATIVE_DIR . $fulldir;
    }

    if (count($to_delete) > 0) {
        if (! $simulate) {
            delete_categories($to_delete);
            foreach ($to_delete_derivative_dirs as $to_delete_dir) {
                if (is_dir($to_delete_dir)) {
                    clear_derivative_cache_rec($to_delete_dir, '#.+#');
                }
            }
        }
        $counts['del_categories'] = count($to_delete);
    }

    $template->append('footer_elements', '<!-- scanning dirs : '
      . get_elapsed_time($start, get_moment())
      . ' -->');
}
// +-----------------------------------------------------------------------+
// |                           files / elements                            |
// +-----------------------------------------------------------------------+
if (isset($_POST['submit']) and $_POST['sync'] == 'files'
      and ! $general_failure) {
    $start_files = get_moment();
    $start = $start_files;

    $fs = $site_reader->get_elements($basedir);

    $template->append('footer_elements', '<!-- get_elements: '
      . get_elapsed_time($start, get_moment())
      . ' -->');

    $cat_ids = array_diff(array_keys($db_categories), $to_delete);

    $db_elements = [];

    if (count($cat_ids) > 0) {
        $query = '
SELECT id, path
  FROM ' . IMAGES_TABLE . '
  WHERE storage_category_id IN ('
          . wordwrap(
              implode(', ', $cat_ids),
              160,
              "\n"
          ) . ')';
        // simple_hash_from_query()'s declared return type is under-typed
        // (array<int|string, mixed>); path is a NOT NULL varchar column, so
        // filter defensively to guarantee real strings here.
        $db_elements = array_filter(simple_hash_from_query($query, 'id', 'path'), is_string(...));
    }

    // next element id available
    $next_element_id = pwg_db_nextval('id', IMAGES_TABLE);

    $start = get_moment();

    $inserts = [];
    $insert_links = [];
    $insert_formats = [];
    $formats_to_delete = [];
    $caddiables = [];

    foreach (array_diff(array_keys($fs), $db_elements) as $path) {
        $insert = [];
        // storage category must exist
        $dirname = dirname($path);
        if (! isset($db_fulldirs[$dirname])) {
            continue;
        }
        $filename = basename($path);
        // sync_chars_regex is a config default, always a regex string; treat
        // a non-string config value the same as a non-matching name below.
        $sync_chars_regex = $conf['sync_chars_regex'];
        if (! is_string($sync_chars_regex) || ! (bool) preg_match($sync_chars_regex, $filename)) {
            $errors[] = [
                'path' => $path,
                'type' => 'PWG-UPDATE-1',
            ];

            continue;
        }

        $insert = [
            'id' => $next_element_id++,
            'file' => pwg_db_real_escape_string($filename),
            'name' => pwg_db_real_escape_string(get_name_from_file($filename)),
            'date_available' => CURRENT_DATE,
            'path' => pwg_db_real_escape_string($path),
            'representative_ext' => $fs[$path]['representative_ext'],
            'storage_category_id' => $db_fulldirs[$dirname],
            'added_by' => $user['id'],
        ];

        if ($_POST['privacy_level'] != 0) {
            $insert['level'] = $_POST['privacy_level'];
        }

        $inserts[] = $insert;

        $insert_links[] = [
            'image_id' => $insert['id'],
            'category_id' => $insert['storage_category_id'],
        ];

        $infos[] = [
            'path' => $insert['path'],
            'info' => l10n('added'),
        ];

        if ((bool) $conf['enable_formats']) {
            // 'formats' is only known as mixed here (get_elements()'s
            // declared value type is array<string, mixed>), but it's always
            // the get_formats() float[] result when set.
            $element_formats = $fs[$path]['formats'] ?? null;
            if (is_array($element_formats)) {
                foreach ($element_formats as $ext => $filesize) {
                    $insert_formats[] = [
                        'image_id' => $insert['id'],
                        'ext' => $ext,
                        'filesize' => $filesize,
                    ];

                    $infos[] = [
                        'path' => $insert['path'],
                        'info' => l10n('format %s added', $ext),
                    ];
                }
            }
        }

        $caddiables[] = $insert['id'];
    }

    // search new/removed formats on photos already registered in database
    if ((bool) $conf['enable_formats']) {
        $db_elements_flip = array_flip($db_elements);

        $existing_ids = [];

        foreach (array_intersect_key($fs, $db_elements_flip) as $path => $existing) {
            $existing_ids[] = $db_elements_flip[$path];
        }

        $logger->debug('existing_ids', 'sync', $existing_ids);

        if (count($existing_ids) > 0) {
            $db_formats = [];

            // find formats for existing photos (already in database)
            $query = '
SELECT *
  FROM ' . IMAGE_FORMAT_TABLE . '
  WHERE image_id IN (' . implode(',', $existing_ids) . ')
;';
            $result = pwg_query($query);
            while ((bool) ($row = pwg_db_fetch_assoc($result))) {
                // image_id/ext are NOT NULL columns; skip defensively rather
                // than use a null/non-scalar value as an array key.
                $format_image_id = $row['image_id'];
                $format_ext = $row['ext'];
                if (! is_numeric($format_image_id) || ! is_string($format_ext)) {
                    continue;
                }
                $format_image_id = (int) $format_image_id;
                if (! isset($db_formats[$format_image_id])) {
                    $db_formats[$format_image_id] = [];
                }

                $db_formats[$format_image_id][$format_ext] = $row['format_id'];
            }

            // first we search the formats that were removed
            foreach ($db_formats as $image_id => $formats) {
                // 'formats' is only known as mixed here (get_elements()'s
                // declared value type is array<string, mixed>).
                $element_formats = $fs[$db_elements[$image_id]]['formats'] ?? null;
                $image_formats_to_delete = array_diff_key($formats, is_array($element_formats) ? $element_formats : []);
                $logger->debug('image_formats_to_delete', 'sync', $image_formats_to_delete);
                foreach ($image_formats_to_delete as $ext => $format_id) {
                    $formats_to_delete[] = $format_id;

                    $infos[] = [
                        'path' => $db_elements[$image_id],
                        'info' => l10n('format %s removed', $ext),
                    ];
                }
            }

            // then we search for new formats on existing photos
            foreach ($existing_ids as $image_id) {
                $path = $db_elements[$image_id];

                $formats = [];
                if (isset($db_formats[$image_id])) {
                    $formats = $db_formats[$image_id];
                }

                // 'formats' is only known as mixed here (get_elements()'s
                // declared value type is array<string, mixed>).
                $element_formats = $fs[$path]['formats'] ?? null;
                $image_formats_to_insert = array_diff_key(is_array($element_formats) ? $element_formats : [], $formats);
                $logger->debug('image_formats_to_insert', 'sync', $image_formats_to_insert);
                foreach ($image_formats_to_insert as $ext => $filesize) {
                    $insert_formats[] = [
                        'image_id' => $image_id,
                        'ext' => $ext,
                        'filesize' => $filesize,
                    ];

                    $infos[] = [
                        'path' => $db_elements[$image_id],
                        'info' => l10n('format %s added', $ext),
                    ];
                }
            }
        }
    }

    if (! $simulate) {
        // inserts all new elements
        if (count($inserts) > 0) {
            mass_inserts(
                IMAGES_TABLE,
                array_keys($inserts[0]),
                $inserts
            );

            // inserts all links between new elements and their storage category
            mass_inserts(
                IMAGE_CATEGORY_TABLE,
                array_keys($insert_links[0]),
                $insert_links
            );

            pwg_activity('photo', $caddiables, 'add', [
                'sync' => true,
            ]);

            // add new photos to caddie
            if (isset($_POST['add_to_caddie']) and $_POST['add_to_caddie'] == 1) {
                fill_caddie($caddiables);
            }
        }

        // inserts all formats
        if (count($insert_formats) > 0) {
            mass_inserts(
                IMAGE_FORMAT_TABLE,
                array_keys($insert_formats[0]),
                $insert_formats
            );
        }

        if (count($formats_to_delete) > 0) {
            $query = '
DELETE
  FROM ' . IMAGE_FORMAT_TABLE . '
  WHERE format_id IN (' . implode(',', $formats_to_delete) . ')
;';
            pwg_query($query);
        }
    }

    $counts['new_elements'] = count($inserts);

    // delete elements that are in database but not in the filesystem
    $to_delete_elements = [];
    foreach (array_diff($db_elements, array_keys($fs)) as $path) {
        // $path is sourced from $db_elements itself (via array_diff), so
        // it's always found in it
        $element_id = array_search($path, $db_elements);
        assert($element_id !== false);
        $to_delete_elements[] = (int) $element_id;
        $infos[] = [
            'path' => $path,
            'info' => l10n('deleted'),
        ];
    }
    if (count($to_delete_elements) > 0) {
        if (! $simulate) {
            delete_elements($to_delete_elements);
        }
        $counts['del_elements'] = count($to_delete_elements);
    }

    $template->append('footer_elements', '<!-- scanning files : '
      . get_elapsed_time($start_files, get_moment())
      . ' -->');
}

// +-----------------------------------------------------------------------+
// |                          synchronize files                            |
// +-----------------------------------------------------------------------+
if (isset($_POST['submit'])
    and ($_POST['sync'] == 'dirs' or $_POST['sync'] == 'files')
    and ! $general_failure) {
    if (! $simulate) {
        $start = get_moment();
        update_category('all');
        $template->append('footer_elements', '<!-- update_category(all) : '
          . get_elapsed_time($start, get_moment())
          . ' -->');
        $start = get_moment();
        update_global_rank();
        $template->append('footer_elements', '<!-- ordering categories : '
          . get_elapsed_time($start, get_moment())
          . ' -->');
    }

    if ($_POST['sync'] == 'files') {
        $start = get_moment();
        $opts = [
            'category_id' => '',
            'recursive' => true,
        ];
        if (isset($_POST['cat'])) {
            $cat = $_POST['cat'];
            $opts['category_id'] = is_string($cat) || is_int($cat) ? $cat : '';
            if (! isset($_POST['subcats-included']) or $_POST['subcats-included'] != 1) {
                $opts['recursive'] = false;
            }
        }
        $files = get_filelist(
            $opts['category_id'],
            $site_id,
            $opts['recursive'],
            false
        );
        $template->append('footer_elements', '<!-- get_filelist : '
          . get_elapsed_time($start, get_moment())
          . ' -->');
        $start = get_moment();

        $datas = [];
        foreach ($files as $id => $file) {
            // get_filelist() returns hash_from_query($query, 'id'), i.e.
            // each row from query2array() with key_name set and value_name
            // null: always the full fetch_assoc() row array (string keys =
            // id/path/representative_ext column names, string|null values).
            assert(is_array($file));
            /** @var array<string, string|null> $file */
            $path = $file['path'] ?? '';
            $data = $site_reader->get_element_update_attributes($path);
            $data['id'] = $id;
            $datas[] = $data;
        } // end foreach file

        $counts['upd_elements'] = count($datas);
        if (! $simulate and count($datas) > 0) {
            mass_updates(
                IMAGES_TABLE,
                // fields
                [
                    'primary' => ['id'],
                    'update' => $site_reader->get_update_attributes(),
                ],
                $datas
            );
        }
        $template->append('footer_elements', '<!-- update files : '
          . get_elapsed_time($start, get_moment())
          . ' -->');
    }// end if sync files
}

// +-----------------------------------------------------------------------+
// |                          synchronize files                            |
// +-----------------------------------------------------------------------+
if (isset($_POST['submit'])
    and ($_POST['sync'] == 'dirs' or $_POST['sync'] == 'files')) {
    $template->assign(
        'update_result',
        [
            'NB_NEW_CATEGORIES' => $counts['new_categories'],
            'NB_DEL_CATEGORIES' => $counts['del_categories'],
            'NB_NEW_ELEMENTS' => $counts['new_elements'],
            'NB_DEL_ELEMENTS' => $counts['del_elements'],
            'NB_UPD_ELEMENTS' => $counts['upd_elements'],
            'NB_ERRORS' => count($errors),
        ]
    );
}

// +-----------------------------------------------------------------------+
// |                          synchronize metadata                         |
// +-----------------------------------------------------------------------+
if (isset($_POST['submit']) and isset($_POST['sync_meta'])
         and ! $general_failure) {
    // sync only never synchronized files ?
    $opts = [
        'only_new' => isset($_POST['meta_all']) ? false : true,
        'category_id' => '',
        'recursive' => true,
    ];

    if (isset($_POST['cat'])) {
        $cat = $_POST['cat'];
        $opts['category_id'] = is_string($cat) || is_int($cat) ? $cat : '';
        // recursive ?
        if (! isset($_POST['subcats-included']) or $_POST['subcats-included'] != 1) {
            $opts['recursive'] = false;
        }
    }
    $start = get_moment();
    $files = get_filelist(
        $opts['category_id'],
        $site_id,
        $opts['recursive'],
        $opts['only_new']
    );

    $template->append('footer_elements', '<!-- get_filelist : '
      . get_elapsed_time($start, get_moment())
      . ' -->');

    $start = get_moment();
    $datas = [];
    $tags_of = [];

    foreach ($files as $id => $element_infos) {
        // get_filelist() returns hash_from_query($query, 'id'), i.e. each
        // row from query2array() with key_name set and value_name null:
        // always the full fetch_assoc() row array (string keys = column
        // names, string|null values).
        assert(is_array($element_infos));
        /** @var array<string, string|null> $element_infos */
        $data = $site_reader->get_element_metadata($element_infos);

        if (is_array($data)) {
            $data['date_metadata_update'] = CURRENT_DATE;
            $data['id'] = $id;
            $datas[] = $data;

            foreach (['keywords', 'tags'] as $key) {
                if (isset($data[$key]) && is_string($data[$key])) {
                    if (! isset($tags_of[$id])) {
                        $tags_of[$id] = [];
                    }

                    foreach (explode(',', $data[$key]) as $tag_name) {
                        $tags_of[$id][] = tag_id_from_tag_name($tag_name);
                    }
                }
            }
        } else {
            $errors[] = [
                'path' => $element_infos['path'],
                'type' => 'PWG-ERROR-NO-FS',
            ];
        }
    }

    if (! $simulate) {
        if (count($datas) > 0) {
            mass_updates(
                IMAGES_TABLE,
                // fields
                [
                    'primary' => ['id'],
                    'update' => array_unique(
                        array_merge(
                            array_diff(
                                $site_reader->get_metadata_attributes(),
                                // keywords and tags fields are managed separately
                                ['keywords', 'tags']
                            ),
                            ['date_metadata_update']
                        )
                    ),
                ],
                $datas,
                isset($_POST['meta_empty_overrides']) ? 0 : MASS_UPDATES_SKIP_EMPTY
            );
        }
        set_tags_of($tags_of);
    }

    $template->append('footer_elements', '<!-- metadata update : '
      . get_elapsed_time($start, get_moment())
      . ' -->');

    $template->assign(
        'metadata_result',
        [
            'NB_ELEMENTS_DONE' => count($datas),
            'NB_ELEMENTS_CANDIDATES' => count($files),
            'NB_ERRORS' => count($errors),
        ]
    );
}

// +-----------------------------------------------------------------------+
// |                        template initialization                        |
// +-----------------------------------------------------------------------+
$template->set_filenames([
    'update' => 'site_update.tpl',
]);
$result_title = '';
if ($simulate) {
    $result_title .= '[' . l10n('Simulation') . '] ';
}

// used_metadata string is displayed to inform admin which metadata will be
// used from files for synchronization
$used_metadata = implode(', ', $site_reader->get_metadata_attributes());

$template->assign(
    [
        'SITE_URL' => $site_url,
        'U_SITE_MANAGER' => get_root_url() . 'admin.php?page=site_manager',
        'L_RESULT_UPDATE' => $result_title . l10n('Search for new images in the directories'),
        'L_RESULT_METADATA' => $result_title . l10n('Metadata synchronization results'),
        'METADATA_LIST' => $used_metadata,
        'U_HELP' => get_root_url() . 'admin/popuphelp.php?page=synchronize',
        'ADMIN_PAGE_TITLE' => l10n('Synchronize'),
    ]
);

// +-----------------------------------------------------------------------+
// |                        introduction : choices                         |
// +-----------------------------------------------------------------------+
if (isset($_POST['submit'])) {
    $privacy_level_selected = 0;
    if (isset($_POST['privacy_level']) and is_numeric($_POST['privacy_level'])) {
        $privacy_level_selected = (int) $_POST['privacy_level'];
    }

    $tpl_introduction = [
        'sync' => $_POST['sync'],
        'sync_meta' => isset($_POST['sync_meta']) ? true : false,
        'display_info' => isset($_POST['display_info']) and $_POST['display_info'] == 1,
        'add_to_caddie' => isset($_POST['add_to_caddie']) and $_POST['add_to_caddie'] == 1,
        'subcats_included' => isset($_POST['subcats-included']) and $_POST['subcats-included'] == 1,
        'privacy_level_selected' => $privacy_level_selected,
        'meta_all' => isset($_POST['meta_all']) ? true : false,
        'meta_empty_overrides' => isset($_POST['meta_empty_overrides']) ? true : false,
    ];

    if (isset($_POST['cat']) and is_numeric($_POST['cat'])) {
        $cat_selected = [$_POST['cat']];
    } else {
        $cat_selected = [];
    }
} else {
    $tpl_introduction = [
        'sync' => 'dirs',
        'sync_meta' => true,
        'display_info' => false,
        'add_to_caddie' => false,
        'subcats_included' => true,
        'privacy_level_selected' => 0,
        'meta_all' => false,
        'meta_empty_overrides' => false,
    ];

    $cat_selected = [];

    if (isset($_GET['cat_id'])) {
        check_input_parameter('cat_id', $_GET, false, PATTERN_ID);

        $cat_selected = [$_GET['cat_id']];
        $tpl_introduction['sync'] = 'files';
    }
}

$tpl_introduction['privacy_level_options'] = get_privacy_level_options();

$template->assign('introduction', $tpl_introduction);

$query = '
SELECT id,name,uppercats,global_rank
  FROM ' . CATEGORIES_TABLE . '
  WHERE site_id = ' . $site_id;
display_select_cat_wrapper(
    $query,
    $cat_selected,
    'category_options',
    false
);

if (count($errors) > 0) {
    foreach ($errors as $error) {
        $template->append(
            'sync_errors',
            [
                'ELEMENT' => $error['path'],
                'LABEL' => $error['type'] . ' (' . $error_labels[$error['type']][0] . ')',
            ]
        );
    }

    foreach ($error_labels as $error_type => $error_description) {
        $template->append(
            'sync_error_captions',
            [
                'TYPE' => $error_type,
                'LABEL' => $error_description[1],
            ]
        );
    }
}

if (count($infos) > 0
    and isset($_POST['display_info'])
    and $_POST['display_info'] == 1) {
    foreach ($infos as $info) {
        $template->append(
            'sync_infos',
            [
                'ELEMENT' => $info['path'],
                'LABEL' => $info['info'],
            ]
        );
    }
}

// +-----------------------------------------------------------------------+
// |                          sending html code                            |
// +-----------------------------------------------------------------------+
$template->assign_var_from_handle('ADMIN_CONTENT', 'update');
