<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

use Piwigo\admin\inc\functions_admin;
use Piwigo\admin\inc\functions_metadata_admin;
use Piwigo\admin\inc\tabsheet;
use Piwigo\admin\EverythingSDK;
use Piwigo\admin\EverythingSiteReader;
use Piwigo\admin\LocalSiteReader;
use Piwigo\inc\functions;
use Piwigo\inc\functions_category;
use Piwigo\inc\functions_html;
use Piwigo\inc\functions_url;
use Piwigo\inc\functions_user;

if (! defined('PHPWG_ROOT_PATH')) {
    exit('Hacking attempt!');
}

// +-----------------------------------------------------------------------+
// | Check Access and exit when user status is not ok                      |
// +-----------------------------------------------------------------------+

if (! $conf->enable_synchronization) {
    exit('synchronization is disabled');
}

functions_user::check_status(ACCESS_ADMINISTRATOR);

if (! is_numeric($_GET['site'])) {
    exit('site param missing or invalid');
}

$site_id = $_GET['site'];

$query = <<<SQL
    SELECT galleries_url
    FROM sites
    WHERE id = {$site_id};
    SQL;
[$site_url] = $conf->sql_backend::pwg_db_fetch_row($conf->sql_backend::pwg_query($query));

if (! isset($site_url)) {
    exit('site ' . $site_id . ' does not exist');
}

$site_is_remote = functions_url::url_is_remote($site_url);

[$dbnow] = $conf->sql_backend::pwg_db_fetch_row($conf->sql_backend::pwg_query('SELECT NOW();'));
define('CURRENT_DATE', $dbnow);

$error_labels = [
    'PWG-ERROR-NO-FS' => [
        functions::l10n('File/directory read error'),
        functions::l10n('The file or directory cannot be accessed (either it does not exist or the access is denied)'),
    ],
];
$errors = [];
$infos = [];
$fs_sizes = [];

if ($site_is_remote) {
    functions_html::fatal_error('remote sites not supported');
} else {
    $site_reader = null;

    if ($conf->everything_dll_path !== '') {
        $dll_path = str_starts_with($conf->everything_dll_path, '/')
            || preg_match('/^[A-Za-z]:/', $conf->everything_dll_path)
            ? $conf->everything_dll_path
            : PHPWG_ROOT_PATH . $conf->everything_dll_path;

        $everything_sdk = EverythingSDK::create(
            $dll_path,
            $conf->everything_instance_name
        );

        if ($everything_sdk !== null) {
            $site_reader = new EverythingSiteReader($site_url, $everything_sdk);
        } else {
            functions_html::fatal_error('Everything SDK failed: ' . EverythingSDK::$lastError);
        }
    } else {
        $site_reader = new LocalSiteReader($site_url);
    }
}

if (isset($page['no_md5sum_number'])) {
    $page['messages'][] = '<a href="admin.php?page=batch_manager&amp;filter=prefilter-no_sync_md5sum">' . functions::l10n('Some checksums are missing.') . '<i class="icon-right"></i></a>';
}

// +-----------------------------------------------------------------------+
// | tabs                                                                  |
// +-----------------------------------------------------------------------+

$my_base_url = functions_url::get_root_url() . 'admin.php?page=';

$tabsheet = new tabsheet();
$tabsheet->set_id('site_update');
$tabsheet->select('synchronization');
$tabsheet->assign();

// +-----------------------------------------------------------------------+
// | Quick sync                                                            |
// +-----------------------------------------------------------------------+

if (isset($_GET['quick_sync'])) {
    functions::check_pwg_token();

    $_POST['sync'] = 'files';
    $_POST['display_info'] = '1';
    $_POST['add_to_caddie'] = '1';
    $_POST['privacy_level'] = '0';
    $_POST['sync_meta'] = '1';
    $_POST['simulate'] = '0';
    $_POST['subcats-included'] = '1';
    $_POST['submit'] = 'Quick Local Synchronization';
}

// +-----------------------------------------------------------------------+
// | SSE (Server-Sent Events) streaming mode                              |
// +-----------------------------------------------------------------------+

$sse_mode = isset($_GET['sse']);

function fmt_number(int $n): string
{
    return number_format($n, 0, '.', ',');
}

function sync_emit(string $event, array $data): void
{
    if (! $GLOBALS['sse_mode']) {
        return;
    }

    echo 'event: ' . $event . "\n";
    echo 'data: ' . json_encode($data, JSON_UNESCAPED_UNICODE) . "\n\n";
    flush();
}

if ($sse_mode && isset($_POST['submit'])) {
    while (ob_get_level()) {
        ob_end_clean();
    }

    header('Content-Type: text/event-stream');
    header('Cache-Control: no-cache');
    header('X-Accel-Buffering: no');
    ignore_user_abort(false);
    set_time_limit(0);
    session_write_close();
}

$general_failure = true;

if (isset($_POST['submit'])) {
    if ($site_reader->open()) {
        $general_failure = false;
    }

    // shall we simulate only
    $simulate = isset($_POST['simulate']) && $_POST['simulate'] == 1;

    if ($sse_mode && $general_failure) {
        sync_emit('error', ['message' => 'Failed to open site reader']);
        exit();
    }
}

// +-----------------------------------------------------------------------+
// |                      directories / categories                         |
// +-----------------------------------------------------------------------+
if (isset($_POST['submit']) &&
   ($_POST['sync'] == 'dirs' || $_POST['sync'] == 'files')
) {
    $counts['new_categories'] = 0;
    $counts['del_categories'] = 0;
    $counts['del_elements'] = 0;
    $counts['new_elements'] = 0;
    $counts['upd_elements'] = 0;
}

if (isset($_POST['submit']) &&
   ($_POST['sync'] == 'dirs' || $_POST['sync'] == 'files') &&
    ! $general_failure
) {
    sync_emit('phase_start', ['phase' => 'dirs']);
    $t_dirs_phase = microtime(true);
    $start = functions::get_moment();
    // which categories to update ?
    $query = <<<SQL
        SELECT id, uppercats, global_rank, status, visible
        FROM categories
        WHERE dir IS NOT NULL
            AND site_id = {$site_id}

        SQL;

    if (isset($_POST['cat']) &&
        is_numeric($_POST['cat'])
    ) {
        if (isset($_POST['subcats-included']) &&
            $_POST['subcats-included'] == 1
        ) {
            $db_regex = $conf->sql_backend::DB_REGEX_OPERATOR;
            $query .= <<<SQL
                AND uppercats {$db_regex} '(^|,){$_POST['cat']}(,|$)'

                SQL;
        } else {
            $query .= <<<SQL
                AND id = {$_POST['cat']}

                SQL;
        }
    }

    $query = trim($query) . ';';
    $db_categories = $conf->sql_backend::query2array($query, 'id');

    // get category full directories in an array for comparison with file
    // system directory tree
    $db_fulldirs = functions_admin::get_fulldirs(array_keys($db_categories));

    // what is the base directory to search file system sub-directories ?
    $basedir = isset($_POST['cat']) && is_numeric($_POST['cat']) ? $db_fulldirs[$_POST['cat']] : rtrim($site_url, '/');

    // we need to have fulldirs as keys to make efficient comparison
    $db_fulldirs = array_flip($db_fulldirs);

    // finding next rank for each id_uppercat. By default, each category id
    // has 1 for next rank on its sub-categories to create
    $next_rank['NULL'] = 1;

    $query = <<<SQL
        SELECT id
        FROM categories;
        SQL;
    $result = $conf->sql_backend::pwg_query($query);

    while ($row = $conf->sql_backend::pwg_db_fetch_assoc($result)) {
        $next_rank[$row['id']] = 1;
    }

    // let's see if some categories already have some sub-categories...
    $query = <<<SQL
        SELECT id_uppercat, MAX(sort_rank) + 1 AS next_rank
        FROM categories
        GROUP BY id_uppercat;
        SQL;
    $result = $conf->sql_backend::pwg_query($query);

    while ($row = $conf->sql_backend::pwg_db_fetch_assoc($result)) {
        // for the id_uppercat NULL, we write 'NULL' and not the empty string
        if (! isset($row['id_uppercat']) ||
            $row['id_uppercat'] == ''
        ) {
            $row['id_uppercat'] = 'NULL';
        }

        $next_rank[$row['id_uppercat']] = $row['next_rank'];
    }

    // next category id available
    $next_id = $conf->sql_backend::pwg_db_nextval('id', 'categories');

    // retrieve sub-directories fulldirs from the site reader
    $dir_callback = $sse_mode
        ? function (string $dir) {
            sync_emit('phase_progress', [
                'phase' => 'dirs',
                'dir' => $dir,
            ]);
        }
        : null;
    $fs_fulldirs = $site_reader->get_full_directories($basedir, $dir_callback);

    // get_full_directories doesn't include the base directory, so if it's a
    // category directory, we need to include it in our array
    if (isset($_POST['cat'])) {
        $fs_fulldirs[] = $basedir;
    }

    // If $_POST['subcats-included'] != 1 ("Search in sub-albums" is unchecked)
    // $db_fulldirs doesn't include any subdirectories and $fs_fulldirs does
    // So $fs_fulldirs will be limited to the selected basedir
    // (if that one is in $fs_fulldirs)
    if (! isset($_POST['subcats-included']) ||
        $_POST['subcats-included'] != 1
    ) {
        $fs_fulldirs = array_intersect($fs_fulldirs, array_keys($db_fulldirs));
    }

    $inserts = [];
    // new categories are the directories not present yet in the database
    foreach (array_diff($fs_fulldirs, array_keys($db_fulldirs)) as $fulldir) {
        $dir = $conf->sql_backend::pwg_db_real_escape_string(basename($fulldir));
        $insert = [
            'id' => $next_id++,
            'dir' => $dir,
            'name' => str_replace('_', ' ', $dir),
            'site_id' => $site_id,
            'commentable' =>
                $conf->sql_backend::boolean_to_string($conf->newcat_default_commentable),
            'status' => $conf->newcat_default_status,
            'visible' => $conf->sql_backend::boolean_to_string($conf->newcat_default_visible),
        ];

        if (isset($db_fulldirs[dirname($fulldir)])) {
            $parent = $db_fulldirs[dirname($fulldir)];

            $insert['id_uppercat'] = $parent;
            $insert['uppercats'] = $db_categories[$parent]['uppercats'] . ',' . $insert['id'];
            $insert['sort_rank'] = $next_rank[$parent]++;
            $insert['global_rank'] = $db_categories[$parent]['global_rank'] . '.' . $insert['sort_rank'];

            if ($db_categories[$parent]['status'] == 'private') {
                $insert['status'] = 'private';
            }

            if ($db_categories[$parent]['visible'] == 'false') {
                $insert['visible'] = 'false';
            }
        } else {
            $insert['uppercats'] = $insert['id'];
            $insert['sort_rank'] = $next_rank['NULL']++;
            $insert['global_rank'] = $insert['sort_rank'];
        }

        $inserts[] = $insert;
        $infos[] = [
            'path' => $fulldir,
            'info' => functions::l10n('added'),
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
    }

    if ($inserts !== []) {
        if (! $simulate) {
            $dbfields = [
                'id', 'dir', 'name', 'site_id', 'id_uppercat', 'uppercats', 'commentable',
                'visible', 'status', 'sort_rank', 'global_rank',
            ];
            $conf->sql_backend::mass_inserts('categories', $dbfields, $inserts);

            // add default permissions to categories
            $category_ids = [];
            $category_up = [];

            foreach ($inserts as $category) {
                $category_ids[] = $category['id'];

                if (! empty($category['id_uppercat'])) {
                    $category_up[] = $category['id_uppercat'];
                }
            }

            functions::pwg_activity('album', $category_ids, 'add', [
                'sync' => true,
            ]);

            $category_up = implode(', ', array_unique($category_up));

            if ($conf->inheritance_by_default &&
                ! empty($category_up)
            ) {
                $query = <<<SQL
                    SELECT *
                    FROM group_access
                    WHERE cat_id IN ({$category_up});
                    SQL;
                $result = $conf->sql_backend::pwg_query($query);

                if (! empty($result)) {
                    $granted_grps = [];

                    while ($row = $conf->sql_backend::pwg_db_fetch_assoc($result)) {
                        if (! isset($granted_grps[$row['cat_id']])) {
                            $granted_grps[$row['cat_id']] = [];
                        }

                        // TODO: explanation
                        $granted_grps[] = [
                            $row['cat_id'] => array_push($granted_grps[$row['cat_id']], $row['group_id']),
                        ];
                    }
                }

                $query = <<<SQL
                    SELECT *
                    FROM user_access
                    WHERE cat_id IN ({$category_up});
                    SQL;
                $result = $conf->sql_backend::pwg_query($query);

                if (! empty($result)) {
                    $granted_users = [];

                    while ($row = $conf->sql_backend::pwg_db_fetch_assoc($result)) {
                        if (! isset($granted_users[$row['cat_id']])) {
                            $granted_users[$row['cat_id']] = [];
                        }

                        // TODO: explanation
                        $granted_users[] = [
                            $row['cat_id'] => array_push($granted_users[$row['cat_id']], $row['user_id']),
                        ];
                    }
                }

                $insert_granted_users = [];
                $insert_granted_grps = [];

                foreach ($category_ids as $ids) {
                    $parent_id = $db_categories[$ids]['parent'];

                    while (in_array($parent_id, $category_ids)) {
                        $parent_id = $db_categories[$parent_id]['parent'];
                    }

                    if ($db_categories[$ids]['status'] == 'private' &&
                        $parent_id !== null
                    ) {
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

                $conf->sql_backend::mass_inserts('group_access', ['group_id', 'cat_id'], $insert_granted_grps);
                $insert_granted_users = array_unique($insert_granted_users, SORT_REGULAR);
                $conf->sql_backend::mass_inserts('user_access', ['user_id', 'cat_id'], $insert_granted_users);
            } else {
                functions_admin::add_permission_on_category($category_ids, functions_admin::get_admins());
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
            'info' => functions::l10n('deleted'),
        ];

        if (substr_compare($fulldir, '../', 0, 3) == 0) {
            $fulldir = substr($fulldir, 3);
        }

        $to_delete_derivative_dirs[] = './' . PWG_DERIVATIVE_DIR . $fulldir;
    }

    if ($to_delete !== []) {
        if (! $simulate) {
            functions_admin::delete_categories($to_delete);

            foreach ($to_delete_derivative_dirs as $to_delete_dir) {
                if (is_dir($to_delete_dir)) {
                    functions_admin::clear_derivative_cache_rec($to_delete_dir, '#.+#');
                }
            }
        }

        $counts['del_categories'] = count($to_delete);
    }

    sync_emit('phase_complete', [
        'phase' => 'dirs',
        'elapsed' => round(microtime(true) - $t_dirs_phase, 1),
        'new' => $counts['new_categories'],
        'deleted' => $counts['del_categories'],
    ]);

    $template->append('footer_elements', '<!-- scanning dirs : '
      . functions::get_elapsed_time($start, functions::get_moment())
      . ' -->');
}

// +-----------------------------------------------------------------------+
// |                           files / elements                            |
// +-----------------------------------------------------------------------+
if (isset($_POST['submit']) &&
    $_POST['sync'] == 'files' &&
    ! $general_failure
) {
    $start_files = functions::get_moment();
    $start = $start_files;

    sync_emit('phase_start', ['phase' => 'files']);
    $t_files_phase = microtime(true);

    sync_emit('substep_start', ['phase' => 'files', 'id' => 'scan', 'label' => 'Scanning filesystem']);
    $t_fs_scan = microtime(true);
    $scan_callback = $sse_mode
        ? function (string $dir, int $count) {
            sync_emit('substep_progress', [
                'phase' => 'files',
                'id' => 'scan',
                'detail' => $dir . ($count > 0 ? ' — ' . fmt_number($count) . ' files' : ''),
            ]);
        }
        : null;
    $fs = $site_reader->get_elements($basedir, 0, $scan_callback);
    $t_fs_scan = microtime(true) - $t_fs_scan;
    sync_emit('substep_complete', [
        'phase' => 'files', 'id' => 'scan',
        'detail' => fmt_number(count($fs)) . ' files found',
        'elapsed' => round($t_fs_scan, 1),
    ]);

    $template->append('footer_elements', '<!-- get_elements: '
      . functions::get_elapsed_time($start, functions::get_moment())
      . ' -->');

    $cat_ids = array_diff(array_keys($db_categories), $to_delete);

    $db_elements = [];

    sync_emit('substep_start', ['phase' => 'files', 'id' => 'db', 'label' => 'Loading database records']);
    $t_db_query = microtime(true);

    if ($cat_ids !== []) {
        $wrappedCatIds = wordwrap(
            implode(', ', $cat_ids),
            160
        );

        $query = <<<SQL
            SELECT id, path
            FROM images
            WHERE storage_category_id IN ({$wrappedCatIds});
            SQL;
        $db_elements = $conf->sql_backend::query2array($query, 'id', 'path');
    }

    sync_emit('substep_complete', [
        'phase' => 'files', 'id' => 'db',
        'detail' => fmt_number(count($db_elements)) . ' records',
        'elapsed' => round(microtime(true) - $t_db_query, 1),
    ]);

    // next element id available
    $next_element_id = $conf->sql_backend::pwg_db_nextval('id', 'images');

    $start = functions::get_moment();

    $inserts = [];
    $insert_links = [];
    $insert_formats = [];
    $formats_to_delete = [];

    sync_emit('substep_start', ['phase' => 'files', 'id' => 'diff', 'label' => 'Comparing filesystem vs database']);
    $t_diff = microtime(true);

    $new_paths = array_diff(array_keys($fs), $db_elements);
    sync_emit('substep_complete', [
        'phase' => 'files', 'id' => 'diff',
        'detail' => fmt_number(count($new_paths)) . ' new, '
            . fmt_number(count($fs) - count($new_paths)) . ' existing',
        'elapsed' => round(microtime(true) - $t_diff, 1),
    ]);

    foreach ($new_paths as $path) {
        $insert = [];
        // storage category must exist
        $dirname = dirname($path);

        if (! isset($db_fulldirs[$dirname])) {
            continue;
        }

        $filename = basename($path);

        $insert = [
            'id' => $next_element_id++,
            'file' => $conf->sql_backend::pwg_db_real_escape_string($filename),
            'name' => $conf->sql_backend::pwg_db_real_escape_string(functions::get_name_from_file($filename)),
            'date_available' => CURRENT_DATE,
            'path' => $conf->sql_backend::pwg_db_real_escape_string($path),
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
            'info' => functions::l10n('added'),
        ];

        if ($conf->enable_formats) {
            foreach ($fs[$path]['formats'] as $ext => $filesize) {
                $insert_formats[] = [
                    'image_id' => $insert['id'],
                    'ext' => $ext,
                    'filesize' => $filesize,
                ];

                $infos[] = [
                    'path' => $insert['path'],
                    'info' => functions::l10n('format %s added', $ext),
                ];
            }
        }

        $caddiables[] = $insert['id'];
    }

    // search new/removed formats on photos already registered in database
    if ($conf->enable_formats) {
        $db_elements_flip = array_flip($db_elements);

        $existing_ids = [];

        foreach (array_keys(array_intersect_key($fs, $db_elements_flip)) as $path) {
            $existing_ids[] = $db_elements_flip[$path];
        }


        if ($existing_ids !== []) {
            $db_formats = [];

            // find formats for existing photos (already in database)
            $existingIdsList = implode(', ', $existing_ids);
            $query = <<<SQL
                SELECT *
                FROM image_format
                WHERE image_id IN ({$existingIdsList});
                SQL;
            $result = $conf->sql_backend::pwg_query($query);

            while ($row = $conf->sql_backend::pwg_db_fetch_assoc($result)) {
                if (! isset($db_formats[$row['image_id']])) {
                    $db_formats[$row['image_id']] = [];
                }

                $db_formats[$row['image_id']][$row['ext']] = $row['format_id'];
            }

            // first we search the formats that were removed
            foreach ($db_formats as $image_id => $formats) {
                $image_formats_to_delete = array_diff_key($formats, $fs[$db_elements[$image_id]]['formats']);

                foreach ($image_formats_to_delete as $ext => $format_id) {
                    $formats_to_delete[] = $format_id;

                    $infos[] = [
                        'path' => $db_elements[$image_id],
                        'info' => functions::l10n('format %s removed', $ext),
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

                $image_formats_to_insert = array_diff_key($fs[$path]['formats'], $formats);

                foreach ($image_formats_to_insert as $ext => $filesize) {
                    $insert_formats[] = [
                        'image_id' => $image_id,
                        'ext' => $ext,
                        'filesize' => $filesize,
                    ];

                    $infos[] = [
                        'path' => $db_elements[$image_id],
                        'info' => functions::l10n('format %s added', $ext),
                    ];
                }
            }
        }
    }

    if (! $simulate) {
        // inserts all new elements
        if ($inserts !== []) {
            sync_emit('substep_start', [
                'phase' => 'files', 'id' => 'insert',
                'label' => 'Inserting ' . fmt_number(count($inserts)) . ' new photos',
            ]);
            $t_mass_insert = microtime(true);
            $insert_progress = $sse_mode
                ? function (int $done, int $total) {
                    sync_emit('substep_progress', [
                        'phase' => 'files',
                        'id' => 'insert',
                        'detail' => fmt_number($done) . ' / ' . fmt_number($total) . ' photos inserted',
                    ]);
                }
                : null;
            $conf->sql_backend::mass_inserts(
                'images',
                array_keys($inserts[0]),
                $inserts,
                [],
                $insert_progress
            );

            sync_emit('substep_progress', [
                'phase' => 'files',
                'id' => 'insert',
                'detail' => 'Inserting album links...',
            ]);

            // inserts all links between new elements and their storage category
            $conf->sql_backend::mass_inserts(
                'image_category',
                array_keys($insert_links[0]),
                $insert_links
            );
            functions::pwg_activity('photo', $caddiables, 'add', [
                'sync' => true,
            ]);

            // add new photos to caddie
            if (isset($_POST['add_to_caddie']) &&
                $_POST['add_to_caddie'] == 1
            ) {
                functions::fill_caddie($caddiables);
            }

            sync_emit('substep_complete', [
                'phase' => 'files', 'id' => 'insert',
                'detail' => fmt_number(count($inserts)) . ' photos inserted',
                'elapsed' => round(microtime(true) - $t_mass_insert, 1),
            ]);
        }

        // inserts all formats
        if ($insert_formats !== []) {
            $conf->sql_backend::mass_inserts(
                'image_format',
                array_keys($insert_formats[0]),
                $insert_formats
            );
        }

        if ($formats_to_delete !== []) {
            $formatsToDeleteList = implode(', ', $formats_to_delete);
            $query = <<<SQL
                DELETE FROM image_format
                WHERE format_id IN ({$formatsToDeleteList});
                SQL;
            $conf->sql_backend::pwg_query($query);
        }
    }

    $counts['new_elements'] = count($inserts);

    sync_emit('substep_start', ['phase' => 'files', 'id' => 'delete', 'label' => 'Checking for deleted files']);
    $t_delete_check = microtime(true);

    // delete elements that are in database but not in the filesystem
    $to_delete_elements = [];

    foreach (array_diff($db_elements, array_keys($fs)) as $path) {
        $to_delete_elements[] = array_search($path, $db_elements, true);
        $infos[] = [
            'path' => $path,
            'info' => functions::l10n('deleted'),
        ];
    }

    if ($to_delete_elements !== []) {
        if (! $simulate) {
            functions_admin::delete_elements($to_delete_elements);
        }

        $counts['del_elements'] = count($to_delete_elements);
    }

    sync_emit('substep_complete', [
        'phase' => 'files', 'id' => 'delete',
        'detail' => count($to_delete_elements) > 0
            ? fmt_number(count($to_delete_elements)) . ' deleted'
            : 'no deletions',
        'elapsed' => round(microtime(true) - $t_delete_check, 1),
    ]);

    sync_emit('substep_start', ['phase' => 'files', 'id' => 'cache', 'label' => 'Building filesize cache']);
    $t_cache = microtime(true);

    // Build filesize cache from scan results for the metadata phase.
    // The scan already called stat() on every file (via is_file), so
    // filesize() was captured for free from the stat cache.
    $fs_sizes = [];

    foreach ($fs as $path => $info) {
        if (isset($info['fs_filesize'])) {
            $fs_sizes[$path] = (int) $info['fs_filesize'];
        }
    }

    sync_emit('substep_complete', [
        'phase' => 'files', 'id' => 'cache',
        'detail' => fmt_number(count($fs_sizes)) . ' entries',
        'elapsed' => round(microtime(true) - $t_cache, 1),
    ]);

    sync_emit('phase_complete', [
        'phase' => 'files',
        'elapsed' => round(microtime(true) - $t_files_phase, 1),
        'new' => $counts['new_elements'],
        'deleted' => $counts['del_elements'],
    ]);

    $template->append('footer_elements', '<!-- scanning files : '
      . functions::get_elapsed_time($start_files, functions::get_moment())
      . ' -->');
}

// +-----------------------------------------------------------------------+
// |                          synchronize files                            |
// +-----------------------------------------------------------------------+
if (isset($_POST['submit']) &&
   ($_POST['sync'] == 'dirs' || $_POST['sync'] == 'files') &&
    ! $general_failure
) {
    if (! $simulate) {
        sync_emit('substep_start', ['phase' => 'files', 'id' => 'categories', 'label' => 'Updating album metadata']);
        $t_cat_update = microtime(true);
        $start = functions::get_moment();
        functions_admin::update_category();
        $template->append('footer_elements', '<!-- \Piwigo\admin\inc\functions::update_category(all) : '
          . functions::get_elapsed_time($start, functions::get_moment())
          . ' -->');
        $start = functions::get_moment();
        functions_admin::update_global_rank();
        $template->append('footer_elements', '<!-- ordering categories : '
          . functions::get_elapsed_time($start, functions::get_moment())
          . ' -->');
        sync_emit('substep_complete', [
            'phase' => 'files', 'id' => 'categories',
            'detail' => 'done',
            'elapsed' => round(microtime(true) - $t_cat_update, 1),
        ]);
    }

    if ($_POST['sync'] == 'files') {
        $start = functions::get_moment();
        $opts['category_id'] = '';
        $opts['recursive'] = true;

        if (isset($_POST['cat'])) {
            $opts['category_id'] = $_POST['cat'];

            if (! isset($_POST['subcats-included']) ||
                $_POST['subcats-included'] != 1
            ) {
                $opts['recursive'] = false;
            }
        }

        sync_emit('substep_start', ['phase' => 'files', 'id' => 'attrs', 'label' => 'Checking file attributes']);
        $t_update_phase = microtime(true);
        $files = functions_metadata_admin::get_filelist(
            $opts['category_id'],
            $site_id,
            $opts['recursive']
        );
        $template->append('footer_elements', '<!-- get_filelist : '
          . functions::get_elapsed_time($start, functions::get_moment())
          . ' -->');
        $start = functions::get_moment();

        $datas = [];

        foreach ($files as $id => $file) {
            $data = $site_reader->get_element_update_attributes($file['path']);

            if (! is_array($data)) {
                continue;
            }

            // Skip if representative_ext hasn't changed
            $existing_rep = $file['representative_ext'] ?? null;
            $new_rep = $data['representative_ext'] ?? null;

            if ($existing_rep === $new_rep) {
                continue;
            }

            $data['id'] = $id;
            $datas[] = $data;
        }
        $counts['upd_elements'] = count($datas);

        if (! $simulate &&
            $datas !== []
        ) {
            $conf->sql_backend::mass_updates(
                'images',
                [
                    'primary' => ['id'],
                    'update' => $site_reader->get_update_attributes(),
                ],
                $datas
            );
        }

        sync_emit('substep_complete', [
            'phase' => 'files', 'id' => 'attrs',
            'detail' => count($datas) > 0 ? fmt_number(count($datas)) . ' updated' : 'no changes',
            'elapsed' => round(microtime(true) - $t_update_phase, 1),
        ]);

        $template->append('footer_elements', '<!-- update files : '
          . functions::get_elapsed_time($start, functions::get_moment())
          . ' -->');
    }// end if sync files
}

// +-----------------------------------------------------------------------+
// |                          synchronize files                            |
// +-----------------------------------------------------------------------+
if (isset($_POST['submit']) &&
   ($_POST['sync'] == 'dirs' ||
    $_POST['sync'] == 'files')
) {
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
if (isset($_POST['submit']) &&
    isset($_POST['sync_meta']) &&
    ! $general_failure
) {
    // sync only never synchronized files ?
    $opts['only_new'] = ! isset($_POST['meta_all']);
    $opts['category_id'] = '';
    $opts['recursive'] = true;

    if (isset($_POST['cat'])) {
        $opts['category_id'] = $_POST['cat'];
        // recursive ?
        if (! isset($_POST['subcats-included']) ||
            $_POST['subcats-included'] != 1
        ) {
            $opts['recursive'] = false;
        }
    }

    sync_emit('phase_start', ['phase' => 'meta']);
    $t_meta_phase = microtime(true);

    sync_emit('substep_start', ['phase' => 'meta', 'id' => 'filelist', 'label' => 'Loading file list']);
    $start = functions::get_moment();
    $t_filelist = microtime(true);
    $files = functions_metadata_admin::get_filelist(
        $opts['category_id'],
        $site_id,
        $opts['recursive'],
        $opts['only_new']
    );
    $t_filelist = microtime(true) - $t_filelist;
    sync_emit('substep_complete', [
        'phase' => 'meta', 'id' => 'filelist',
        'detail' => fmt_number(count($files)) . ' candidates',
        'elapsed' => round($t_filelist, 1),
    ]);

    $template->append('footer_elements', '<!-- get_filelist : '
      . functions::get_elapsed_time($start, functions::get_moment())
      . ' -->');

    sync_emit('substep_start', ['phase' => 'meta', 'id' => 'extract', 'label' => 'Extracting metadata', 'has_progress' => true]);
    $t_extract = microtime(true);

    $start = functions::get_moment();
    $datas = [];
    $tags_of = [];
    $tag_names_by_image = [];
    $meta_progress_count = 0;
    $meta_skipped = 0;
    $profiling = $conf->sync_profiling;

    if ($profiling) {
        $prof_meta_count = 0;
        $prof_meta_total = 0;
        $prof_meta_times = [];
    }

    foreach ($files as $id => $element_infos) {
        if ($profiling) {
            $t_img = microtime(true);
        }

        // Inject cached filesize from scan phase to avoid redundant stat() calls
        $scan_path = $element_infos['path'];

        if (isset($fs_sizes[$scan_path])) {
            $element_infos['fs_filesize'] = $fs_sizes[$scan_path];
        }

        $data = $site_reader->get_element_metadata($element_infos);

        if ($profiling) {
            $t_img_elapsed = microtime(true) - $t_img;
            $prof_meta_count++;
            $prof_meta_total += $t_img_elapsed;
            $prof_meta_times[] = $t_img_elapsed;

            if ($prof_meta_count <= 10 || $prof_meta_count % 100 === 0) {
                $logger->debug('[sync][meta] get_element_metadata', [
                    'n' => $prof_meta_count,
                    'id' => $id,
                    'path' => $element_infos['path'],
                    'elapsed_s' => round($t_img_elapsed, 4),
                ]);
            }
        }

        $meta_progress_count++;

        if ($data === null) {
            // File unchanged (filesize matches DB), skip
            $meta_skipped++;

            if ($sse_mode && $meta_progress_count % 100 === 0) {
                sync_emit('phase_progress', [
                    'phase' => 'meta',
                    'current' => $meta_progress_count,
                    'total' => count($files),
                    'updated' => count($datas),
                    'skipped' => $meta_skipped,
                    'file' => basename($element_infos['path']),
                ]);
            }

            continue;
        }

        if (is_array($data)) {
            $data['date_metadata_update'] = CURRENT_DATE;
            $data['id'] = $id;
            $datas[] = $data;

            // Collect tag names for batch resolution after the loop
            foreach (['keywords', 'tags'] as $key) {
                if (isset($data[$key])) {
                    if (! isset($tag_names_by_image[$id])) {
                        $tag_names_by_image[$id] = [];
                    }

                    foreach (explode(',', $data[$key]) as $tag_name) {
                        $tag_names_by_image[$id][] = trim($tag_name);
                    }
                }
            }
        } else {
            $errors[] = [
                'path' => $element_infos['path'],
                'type' => 'PWG-ERROR-NO-FS',
            ];
        }

        if ($sse_mode && $meta_progress_count % 100 === 0) {
            sync_emit('phase_progress', [
                'phase' => 'meta',
                'current' => $meta_progress_count,
                'total' => count($files),
                'updated' => count($datas),
                'skipped' => $meta_skipped,
                'file' => basename($element_infos['path']),
            ]);
        }
    }

    if ($profiling && $prof_meta_count > 0) {
        sort($prof_meta_times);
        $p50 = $prof_meta_times[(int) floor($prof_meta_count * 0.5)] ?? 0;
        $p95 = $prof_meta_times[(int) floor($prof_meta_count * 0.95)] ?? 0;
        $p99 = $prof_meta_times[(int) floor($prof_meta_count * 0.99)] ?? 0;
        $logger->info('[sync][meta] metadata extraction loop summary', [
            'images_processed' => $prof_meta_count,
            'images_success' => count($datas),
            'images_error' => count($errors),
            'total_s' => round($prof_meta_total, 4),
            'avg_s' => round($prof_meta_total / $prof_meta_count, 4),
            'min_s' => round($prof_meta_times[0], 4),
            'max_s' => round($prof_meta_times[$prof_meta_count - 1], 4),
            'p50_s' => round($p50, 4),
            'p95_s' => round($p95, 4),
            'p99_s' => round($p99, 4),
        ]);
    }

    sync_emit('substep_complete', [
        'phase' => 'meta', 'id' => 'extract',
        'detail' => fmt_number(count($datas)) . ' updated, ' . fmt_number($meta_skipped) . ' skipped',
        'elapsed' => round(microtime(true) - $t_extract, 1),
    ]);

    // Batch-resolve all collected tag names at once
    if ($tag_names_by_image !== []) {
        $all_tag_names = [];

        foreach ($tag_names_by_image as $img_tag_names) {
            foreach ($img_tag_names as $tn) {
                $all_tag_names[] = $tn;
            }
        }

        $t_tags = microtime(true);
        $tag_name_to_id = functions_admin::batch_tag_ids_from_tag_names($all_tag_names);
        $t_tags = microtime(true) - $t_tags;

        foreach ($tag_names_by_image as $img_id => $img_tag_names) {
            if (! isset($tags_of[$img_id])) {
                $tags_of[$img_id] = [];
            }

            foreach ($img_tag_names as $tn) {
                if (isset($tag_name_to_id[$tn])) {
                    $tags_of[$img_id][] = $tag_name_to_id[$tn];
                }
            }
        }

        if ($profiling) {
            $logger->info('[sync][meta] tag lookup summary', [
                'tag_lookups' => count($all_tag_names),
                'total_s' => round($t_tags, 4),
                'images_with_tags' => count($tags_of),
            ]);
        }
    }

    // log per-operation aggregate breakdown from get_sync_metadata
    if ($profiling && isset($GLOBALS['sync_meta_prof'])) {
        foreach ($GLOBALS['sync_meta_prof'] as $op => $stats) {
            $logger->info('[sync][meta][aggregate] ' . $op, [
                'calls' => $stats['count'],
                'total_s' => round($stats['total'], 4),
                'avg_s' => round($stats['total'] / $stats['count'], 5),
                'max_s' => round($stats['max'], 5),
                'max_file' => $stats['max_file'],
            ]);
        }

        unset($GLOBALS['sync_meta_prof']);
    }

    if (! $simulate) {
        sync_emit('substep_start', ['phase' => 'meta', 'id' => 'db_update', 'label' => 'Updating database']);
        $t_db_update = microtime(true);

        if ($datas !== []) {
            $conf->sql_backend::mass_updates(
                'images',
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
                isset($_POST['meta_empty_overrides']) ? 0 : $conf->sql_backend::MASS_UPDATES_SKIP_EMPTY
            );
        }

        functions_admin::set_tags_of($tags_of);

        sync_emit('substep_complete', [
            'phase' => 'meta', 'id' => 'db_update',
            'detail' => fmt_number(count($datas)) . ' rows, ' . count($tags_of) . ' tagged',
            'elapsed' => round(microtime(true) - $t_db_update, 1),
        ]);
    }

    sync_emit('phase_complete', [
        'phase' => 'meta',
        'elapsed' => round(microtime(true) - $t_meta_phase, 1),
        'updated' => count($datas),
        'candidates' => count($files),
        'skipped' => $meta_skipped,
    ]);

    $template->append('footer_elements', '<!-- metadata update : '
      . functions::get_elapsed_time($start, functions::get_moment())
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
// | SSE: emit final results and exit                                      |
// +-----------------------------------------------------------------------+
if ($sse_mode && isset($_POST['submit'])) {
    $sse_results = ['simulate' => $simulate ?? false];

    if (isset($counts)) {
        $sse_results['update'] = [
            'new_categories' => $counts['new_categories'],
            'del_categories' => $counts['del_categories'],
            'new_elements' => $counts['new_elements'] ?? 0,
            'del_elements' => $counts['del_elements'] ?? 0,
            'upd_elements' => $counts['upd_elements'] ?? 0,
            'errors' => count($errors),
        ];
    }

    if (isset($t_meta_phase)) {
        $sse_results['metadata'] = [
            'updated' => count($datas),
            'candidates' => count($files),
            'errors' => count($errors),
        ];
    }

    sync_emit('complete', $sse_results);
    exit();
}

// +-----------------------------------------------------------------------+
// |                        template initialization                        |
// +-----------------------------------------------------------------------+
$template->set_filenames([
    'update' => 'site_update.tpl',
]);
$result_title = '';

if (isset($simulate) &&
    $simulate
) {
    $result_title .= '[' . functions::l10n('Simulation') . '] ';
}

// used_metadata string is displayed to inform admin which metadata will be
// used from files for synchronization
$used_metadata = implode(', ', $site_reader->get_metadata_attributes());

if ($site_is_remote &&
    ! isset($_POST['submit'])
) {
    $used_metadata .= ' + ...';
}

$template->assign(
    [
        'SITE_URL' => $site_url,
        'U_SITE_MANAGER' => functions_url::get_root_url() . 'admin.php?page=site_manager',
        'L_RESULT_UPDATE' => $result_title . functions::l10n('Search for new images in the directories'),
        'L_RESULT_METADATA' => $result_title . functions::l10n('Metadata synchronization results'),
        'METADATA_LIST' => $used_metadata,
        'U_HELP' => functions_url::get_root_url() . 'admin/popuphelp.php?page=synchronize',
        'ADMIN_PAGE_TITLE' => functions::l10n('Synchronize'),
    ]
);

// +-----------------------------------------------------------------------+
// |                        introduction : choices                         |
// +-----------------------------------------------------------------------+
if (isset($_POST['submit'])) {
    $tpl_introduction = [
        'sync' => $_POST['sync'],
        'sync_meta' => isset($_POST['sync_meta']),
        'display_info' => isset($_POST['display_info']) && $_POST['display_info'] == 1,
        'add_to_caddie' => isset($_POST['add_to_caddie']) && $_POST['add_to_caddie'] == 1,
        'subcats_included' => isset($_POST['subcats-included']) && $_POST['subcats-included'] == 1,
        'privacy_level_selected' => (int) $_POST['privacy_level'],
        'meta_all' => isset($_POST['meta_all']),
        'meta_empty_overrides' => isset($_POST['meta_empty_overrides']),
    ];

    $cat_selected = isset($_POST['cat']) && is_numeric($_POST['cat']) ? [$_POST['cat']] : [];
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
        functions::check_input_parameter('cat_id', $_GET, false, PATTERN_ID);

        $cat_selected = [$_GET['cat_id']];
        $tpl_introduction['sync'] = 'files';
    }
}

$tpl_introduction['privacy_level_options'] = functions::get_privacy_level_options();

$template->assign('introduction', $tpl_introduction);

$query = <<<SQL
    SELECT id, name, uppercats, global_rank
    FROM categories
    WHERE site_id = {$site_id};
    SQL;
functions_category::display_select_cat_wrapper(
    $query,
    $cat_selected,
    'category_options',
    false
);

if ($errors !== []) {
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

if ($infos !== [] &&
    isset($_POST['display_info']) &&
    $_POST['display_info'] == 1
) {
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
