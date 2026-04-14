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
    $_POST['add_to_caddie'] = '1';
    $_POST['privacy_level'] = '0';
    $_POST['sync_meta'] = '1';
    $_POST['simulate'] = '0';
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

    // --- substep: load DB categories ---
    sync_emit('substep_start', ['phase' => 'dirs', 'id' => 'db', 'label' => 'Loading database categories']);
    $t_sub = microtime(true);

    $query = <<<SQL
        SELECT id, uppercats, global_rank, status, visible
        FROM categories
        WHERE dir IS NOT NULL
            AND site_id = {$site_id};
        SQL;
    $db_categories = $conf->sql_backend::query2array($query, 'id');
    $db_fulldirs = functions_admin::get_fulldirs(array_keys($db_categories));
    $basedir = rtrim($site_url, '/');
    $db_fulldirs = array_flip($db_fulldirs);

    $next_rank['NULL'] = 1;

    $query = <<<SQL
        SELECT id
        FROM categories;
        SQL;
    $result = $conf->sql_backend::pwg_query($query);

    while ($row = $conf->sql_backend::pwg_db_fetch_assoc($result)) {
        $next_rank[$row['id']] = 1;
    }

    $query = <<<SQL
        SELECT id_uppercat, MAX(sort_rank) + 1 AS next_rank
        FROM categories
        GROUP BY id_uppercat;
        SQL;
    $result = $conf->sql_backend::pwg_query($query);

    while ($row = $conf->sql_backend::pwg_db_fetch_assoc($result)) {
        if (! isset($row['id_uppercat']) || $row['id_uppercat'] == '') {
            $row['id_uppercat'] = 'NULL';
        }

        $next_rank[$row['id_uppercat']] = $row['next_rank'];
    }

    $next_id = $conf->sql_backend::pwg_db_nextval('id', 'categories');

    sync_emit('substep_complete', [
        'phase' => 'dirs', 'id' => 'db',
        'detail' => fmt_number(count($db_categories)) . ' albums in database',
        'elapsed' => round(microtime(true) - $t_sub, 1),
    ]);

    // --- substep: scan filesystem ---
    sync_emit('substep_start', ['phase' => 'dirs', 'id' => 'scan', 'label' => 'Scanning directories']);
    $t_sub = microtime(true);

    $dir_callback = $sse_mode
        ? function (string $dir) {
            sync_emit('substep_progress', [
                'phase' => 'dirs',
                'id' => 'scan',
                'detail' => $dir,
            ]);
        }
        : null;
    $fs_fulldirs = $site_reader->get_full_directories($basedir, $dir_callback);
    $logger->info('[sync][dirs] get_full_directories done', ['count' => count($fs_fulldirs)]);

    sync_emit('substep_complete', [
        'phase' => 'dirs', 'id' => 'scan',
        'detail' => fmt_number(count($fs_fulldirs)) . ' directories found',
        'elapsed' => round(microtime(true) - $t_sub, 1),
    ]);

    // --- substep: diff ---
    sync_emit('substep_start', ['phase' => 'dirs', 'id' => 'diff', 'label' => 'Comparing filesystem vs database']);
    $t_sub = microtime(true);

    $new_dirs = array_diff($fs_fulldirs, array_keys($db_fulldirs));
    $del_dirs = array_diff(array_keys($db_fulldirs), $fs_fulldirs);
    $logger->info('[sync][dirs] diff computed', ['new' => count($new_dirs), 'del' => count($del_dirs)]);

    sync_emit('substep_complete', [
        'phase' => 'dirs', 'id' => 'diff',
        'detail' => fmt_number(count($new_dirs)) . ' new, ' . fmt_number(count($del_dirs)) . ' to delete',
        'elapsed' => round(microtime(true) - $t_sub, 1),
    ]);

    $inserts = [];
    // new categories are the directories not present yet in the database
    foreach ($new_dirs as $fulldir) {
        $dir = basename($fulldir);
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
        sync_emit('substep_start', [
            'phase' => 'dirs', 'id' => 'insert',
            'label' => 'Inserting ' . fmt_number(count($inserts)) . ' new albums',
        ]);
        $t_sub = microtime(true);
        $logger->info('[sync][dirs] inserting new categories', ['count' => count($inserts)]);
        if (! $simulate) {
            $dbfields = [
                'id', 'dir', 'name', 'site_id', 'id_uppercat', 'uppercats', 'commentable',
                'visible', 'status', 'sort_rank', 'global_rank',
            ];
            $t_insert = microtime(true);
            $conf->sql_backend::mass_inserts('categories', $dbfields, $inserts, ['bulk' => true]);
            $logger->info('[sync][dirs] mass_inserts done', ['elapsed_s' => round(microtime(true) - $t_insert, 2)]);

            // add default permissions to categories
            $category_ids = [];
            $category_up = [];

            foreach ($inserts as $category) {
                $category_ids[] = $category['id'];

                if (! empty($category['id_uppercat'])) {
                    $category_up[] = $category['id_uppercat'];
                }
            }

            // Log a single summary activity instead of one row per category.
            $t_activity = microtime(true);
            functions::pwg_activity('album', [count($category_ids)], 'add', [
                'sync' => true,
                'count' => count($category_ids),
            ]);
            $logger->info('[sync][dirs] pwg_activity done', ['elapsed_s' => round(microtime(true) - $t_activity, 2)]);

            $t_perms = microtime(true);
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

                $granted_grps = [];

                if (! empty($result)) {
                    while ($row = $conf->sql_backend::pwg_db_fetch_assoc($result)) {
                        $granted_grps[$row['cat_id']][] = $row['group_id'];
                    }
                }

                $query = <<<SQL
                    SELECT *
                    FROM user_access
                    WHERE cat_id IN ({$category_up});
                    SQL;
                $result = $conf->sql_backend::pwg_query($query);

                $granted_users = [];

                if (! empty($result)) {
                    while ($row = $conf->sql_backend::pwg_db_fetch_assoc($result)) {
                        $granted_users[$row['cat_id']][] = $row['user_id'];
                    }
                }

                $insert_granted_users = [];
                $insert_granted_grps = [];

                // Use a hash set for O(1) lookups instead of in_array O(n).
                $category_ids_set = array_flip($category_ids);

                foreach ($category_ids as $ids) {
                    $parent_id = $db_categories[$ids]['parent'];

                    while (isset($category_ids_set[$parent_id])) {
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
                // add_permission_on_category only does meaningful work for
                // private albums.  Skip the expensive DB round-trip entirely
                // when all new albums are public (the common default).
                $private_ids = array_values(
                    array_column(
                        array_filter($inserts, fn($c) => $c['status'] === 'private'),
                        'id'
                    )
                );
                if ($private_ids !== []) {
                    functions_admin::add_permission_on_category($private_ids, functions_admin::get_admins());
                }
            }

            $logger->info('[sync][dirs] permissions done', ['elapsed_s' => round(microtime(true) - $t_perms, 2)]);
        }

        $counts['new_categories'] = count($inserts);
        sync_emit('substep_complete', [
            'phase' => 'dirs', 'id' => 'insert',
            'detail' => fmt_number(count($inserts)) . ' albums added',
            'elapsed' => round(microtime(true) - $t_sub, 1),
        ]);
    }

    // to delete categories
    $to_delete = [];
    $to_delete_derivative_dirs = [];

    foreach ($del_dirs as $fulldir) {
        $to_delete[] = $db_fulldirs[$fulldir];
        unset($db_fulldirs[$fulldir]);

        if (substr_compare($fulldir, '../', 0, 3) == 0) {
            $fulldir = substr($fulldir, 3);
        }

        $to_delete_derivative_dirs[] = './' . PWG_DERIVATIVE_DIR . $fulldir;
    }

    if ($to_delete !== []) {
        sync_emit('substep_start', [
            'phase' => 'dirs', 'id' => 'delete',
            'label' => 'Deleting ' . fmt_number(count($to_delete)) . ' albums',
        ]);
        $t_sub = microtime(true);
        $logger->info('[sync][dirs] deleting categories', ['count' => count($to_delete)]);
        if (! $simulate) {
            $t_del = microtime(true);
            functions_admin::delete_categories($to_delete, skip_subcats: true);
            $logger->info('[sync][dirs] delete_categories done', ['elapsed_s' => round(microtime(true) - $t_del, 2)]);

            $t_deriv = microtime(true);
            foreach ($to_delete_derivative_dirs as $to_delete_dir) {
                if (is_dir($to_delete_dir)) {
                    functions_admin::clear_derivative_cache_rec($to_delete_dir, '#.+#');
                }
            }
            $logger->info('[sync][dirs] derivative cleanup done', ['elapsed_s' => round(microtime(true) - $t_deriv, 2)]);
        }

        $counts['del_categories'] = count($to_delete);
        sync_emit('substep_complete', [
            'phase' => 'dirs', 'id' => 'delete',
            'detail' => fmt_number(count($to_delete)) . ' albums deleted',
            'elapsed' => round(microtime(true) - $t_sub, 1),
        ]);
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

    $cat_ids = array_diff(array_keys($db_categories), $to_delete);

    // Load existing DB images as [path => id] directly — no array_flip needed.
    // Entries are unset during the streaming loop; what remains afterwards are
    // files deleted from the filesystem.
    $db_paths_set = [];

    sync_emit('substep_start', ['phase' => 'files', 'id' => 'db', 'label' => 'Loading database records']);
    $t_db_query = microtime(true);

    if ($cat_ids !== []) {
        $wrappedCatIds = wordwrap(
            implode(', ', $cat_ids),
            160
        );

        $query = <<<SQL
            SELECT path, id
            FROM images
            WHERE storage_category_id IN ({$wrappedCatIds});
            SQL;
        $db_paths_set = $conf->sql_backend::query2array($query, 'path', 'id');
    }

    sync_emit('substep_complete', [
        'phase' => 'files', 'id' => 'db',
        'detail' => fmt_number(count($db_paths_set)) . ' records',
        'elapsed' => round(microtime(true) - $t_db_query, 1),
    ]);

    // next element id available
    $next_element_id = $conf->sql_backend::pwg_db_nextval('id', 'images');

    $start = functions::get_moment();

    $insert_formats = [];
    $formats_to_delete = [];

    // --- Streaming scan + insert loop ---
    // get_elements() is a Generator: files are yielded one-by-one as the filesystem is
    // traversed, so the full $fs array is never materialised in memory.
    // Existing files are tracked inline; new files are inserted in 50k-row chunks.

    $existing_ids     = [];
    $existing_count   = 0;
    $existing_formats = [];   // [image_id => formats array] — used by formats-for-existing section
    $inserted_count   = 0;
    $chunk_inserts    = [];
    $chunk_links      = [];
    $chunk_fmt_new    = [];
    $chunk_caddie     = [];

    $image_fields = ['id', 'file', 'name', 'date_available', 'path', 'representative_ext', 'storage_category_id', 'added_by'];

    if ($_POST['privacy_level'] != 0) {
        $image_fields[] = 'level';
    }

    $link_fields    = ['image_id', 'category_id'];
    $fmt_fields     = ['image_id', 'ext', 'filesize'];
    $add_to_caddie  = isset($_POST['add_to_caddie']) && $_POST['add_to_caddie'] == 1;

    // Use DB record count as a proxy for "is this a large gallery?".
    // We don't know new_count upfront (scan is streaming), so we approximate:
    // galleries with > 10k existing records benefit from bulk-insert optimisations
    // (FULLTEXT drop + unique_checks=0). If 0 new files are found, the FULLTEXT index
    // is not rebuilt (gated on $inserted_count > 0 below).
    $large_insert_likely = count($db_paths_set) > 10000;
    $has_ft_index = false;

    if (! $simulate && $large_insert_likely) {
        $result = $conf->sql_backend::pwg_query("SHOW INDEX FROM images WHERE Key_name = 'image_ft';");

        if ($conf->sql_backend::pwg_db_num_rows($result) > 0) {
            $conf->sql_backend::pwg_query('ALTER TABLE images DROP INDEX image_ft;');
            $has_ft_index = true;
        }

        $conf->sql_backend::pwg_query('SET unique_checks=0, foreign_key_checks=0');
    }

    $t_mass_insert = microtime(true);

    sync_emit('substep_start', ['phase' => 'files', 'id' => 'scan', 'label' => 'Scanning filesystem']);
    $t_scan = microtime(true);

    $scan_callback = $sse_mode
        ? function (string $dir, int $count) {
            sync_emit('substep_progress', [
                'phase' => 'files',
                'id' => 'scan',
                'detail' => $dir . ($count > 0 ? ' — ' . fmt_number($count) . ' files' : ''),
            ]);
        }
        : null;

    $logger->info('[sync][files] starting get_elements', ['basedir' => $basedir]);

    foreach ($site_reader->get_elements($basedir, 0, $scan_callback) as $path => $meta) {
        if (isset($db_paths_set[$path])) {
            // Existing file: collect ID + formats for formats-for-existing check; mark seen.
            $image_id = $db_paths_set[$path];
            $existing_ids[]   = $image_id;
            $existing_count++;
            if ($conf->enable_formats) {
                $existing_formats[$image_id] = $meta['formats'] ?? [];
            }
            unset($db_paths_set[$path]);
            continue;
        }

        // New file: storage category must exist in DB.
        $dirname = dirname($path);

        if (! isset($db_fulldirs[$dirname])) {
            continue;
        }

        $inserted_count++;

        if ($inserted_count === 1) {
            sync_emit('substep_start', [
                'phase' => 'files', 'id' => 'insert',
                'label' => $simulate ? 'Counting new photos' : 'Inserting new photos',
            ]);
        }

        if (! $simulate) {
            $filename = basename($path);

            $insert = [
                'id'                  => $next_element_id++,
                'file'                => $filename,
                'name'                => functions::get_name_from_file($filename),
                'date_available'      => CURRENT_DATE,
                'path'                => $path,
                'representative_ext'  => $meta['representative_ext'],
                'storage_category_id' => $db_fulldirs[$dirname],
                'added_by'            => $user['id'],
            ];

            if ($_POST['privacy_level'] != 0) {
                $insert['level'] = $_POST['privacy_level'];
            }

            $chunk_inserts[] = $insert;
            $chunk_links[]   = ['image_id' => $insert['id'], 'category_id' => $insert['storage_category_id']];

            if ($conf->enable_formats) {
                foreach ($meta['formats'] as $ext => $filesize) {
                    $chunk_fmt_new[] = ['image_id' => $insert['id'], 'ext' => $ext, 'filesize' => $filesize];
                }
            }

            if ($add_to_caddie) {
                $chunk_caddie[] = $insert['id'];
            }

            if (count($chunk_inserts) >= 50000) {
                $conf->sql_backend::mass_inserts('images', $image_fields, $chunk_inserts);
                $conf->sql_backend::mass_inserts('image_category', $link_fields, $chunk_links);

                if ($chunk_fmt_new !== []) {
                    $conf->sql_backend::mass_inserts('image_format', $fmt_fields, $chunk_fmt_new);
                }

                if ($chunk_caddie !== []) {
                    functions::fill_caddie($chunk_caddie);
                }

                $chunk_inserts = $chunk_links = $chunk_fmt_new = $chunk_caddie = [];

                sync_emit('substep_progress', [
                    'phase' => 'files', 'id' => 'insert',
                    'detail' => fmt_number($inserted_count) . ' inserted',
                ]);
            }
        } elseif ($sse_mode && $inserted_count % 50000 === 0) {
            sync_emit('substep_progress', [
                'phase' => 'files', 'id' => 'insert',
                'detail' => fmt_number($inserted_count) . ' counted',
            ]);
        }
    }

    // Flush the remaining partial chunk.
    if ($chunk_inserts !== []) {
        $conf->sql_backend::mass_inserts('images', $image_fields, $chunk_inserts);
        $conf->sql_backend::mass_inserts('image_category', $link_fields, $chunk_links);

        if ($chunk_fmt_new !== []) {
            $conf->sql_backend::mass_inserts('image_format', $fmt_fields, $chunk_fmt_new);
        }

        if ($chunk_caddie !== []) {
            functions::fill_caddie($chunk_caddie);
        }
    }

    unset($chunk_inserts, $chunk_links, $chunk_fmt_new, $chunk_caddie);

    $logger->info('[sync][files] get_elements done', [
        'total'    => $inserted_count + $existing_count,
        'new'      => $inserted_count,
        'existing' => $existing_count,
    ]);
    sync_emit('substep_complete', [
        'phase' => 'files', 'id' => 'scan',
        'detail' => fmt_number($inserted_count + $existing_count) . ' files found',
        'elapsed' => round(microtime(true) - $t_scan, 1),
    ]);

    $template->append('footer_elements', '<!-- get_elements: '
        . functions::get_elapsed_time($start, functions::get_moment())
        . ' -->');

    if (! $simulate && $large_insert_likely) {
        $conf->sql_backend::pwg_query('SET unique_checks=1, foreign_key_checks=1');

        if ($has_ft_index && $inserted_count > 0) {
            sync_emit('substep_progress', [
                'phase' => 'files', 'id' => 'insert',
                'detail' => 'Rebuilding fulltext index...',
            ]);
            $conf->sql_backend::pwg_query('ALTER TABLE images ADD FULLTEXT INDEX image_ft (name, comment);');
        }
    }

    if ($inserted_count > 0) {
        if (! $simulate) {
            functions::pwg_activity('photo', [$inserted_count], 'add', [
                'sync' => true,
                'count' => $inserted_count,
            ]);
        }

        sync_emit('substep_complete', [
            'phase' => 'files', 'id' => 'insert',
            'detail' => fmt_number($inserted_count) . ' photos inserted',
            'elapsed' => round(microtime(true) - $t_mass_insert, 1),
        ]);
    }

    // search new/removed formats on photos already registered in database
    // ($existing_ids was collected during the streaming insert loop above)
    if ($conf->enable_formats && $existing_ids !== []) {
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
            $image_formats_to_delete = array_diff_key($formats, $existing_formats[$image_id] ?? []);

            foreach ($image_formats_to_delete as $ext => $format_id) {
                $formats_to_delete[] = $format_id;
            }
        }

        // then we search for new formats on existing photos
        foreach ($existing_ids as $image_id) {
            $formats = $db_formats[$image_id] ?? [];
            $image_formats_to_insert = array_diff_key($existing_formats[$image_id] ?? [], $formats);

            foreach ($image_formats_to_insert as $ext => $filesize) {
                $insert_formats[] = [
                    'image_id' => $image_id,
                    'ext'      => $ext,
                    'filesize' => $filesize,
                ];
            }
        }
    }

    if (! $simulate) {
        // inserts all formats
        if ($insert_formats !== [] || $formats_to_delete !== []) {
            sync_emit('substep_start', [
                'phase' => 'files', 'id' => 'formats',
                'label' => 'Syncing file formats',
            ]);
            $t_formats = microtime(true);

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

            sync_emit('substep_complete', [
                'phase' => 'files', 'id' => 'formats',
                'detail' => fmt_number(count($insert_formats)) . ' added, '
                    . fmt_number(count($formats_to_delete)) . ' removed',
                'elapsed' => round(microtime(true) - $t_formats, 1),
            ]);
        }
    }

    $counts['new_elements'] = $inserted_count;

    sync_emit('substep_start', ['phase' => 'files', 'id' => 'delete', 'label' => 'Checking for deleted files']);
    $t_delete_check = microtime(true);

    // Remaining entries in $db_paths_set are files deleted from the filesystem.
    // (Existing files were unset from $db_paths_set during the streaming insert loop.)
    $to_delete_elements = array_values($db_paths_set);
    unset($db_paths_set);

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

    // $fs_sizes cache removed: get_sync_metadata() calls filesize() directly when
    // fs_filesize is absent from $element_infos — the overhead is negligible vs EXIF parsing.

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

        sync_emit('substep_start', ['phase' => 'files', 'id' => 'attrs', 'label' => 'Checking file attributes']);
        $t_update_phase = microtime(true);
        $files = functions_metadata_admin::get_filelist(
            '',
            $site_id,
            true,
            only_representable: true
        );
        $template->append('footer_elements', '<!-- get_filelist : '
          . functions::get_elapsed_time($start, functions::get_moment())
          . ' -->');
        $start = functions::get_moment();

        $datas = [];
        $attrs_update_fields = [
            'primary' => ['id'],
            'update'  => $site_reader->get_update_attributes(),
        ];
        $attrs_updated = 0;

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
            $attrs_updated++;

            if (count($datas) >= 10000) {
                if (! $simulate) {
                    $conf->sql_backend::mass_updates('images', $attrs_update_fields, $datas);
                }

                $datas = [];
            }
        }

        if (! $simulate && $datas !== []) {
            $conf->sql_backend::mass_updates('images', $attrs_update_fields, $datas);
            $datas = [];
        }

        $counts['upd_elements'] = $attrs_updated;

        sync_emit('substep_complete', [
            'phase' => 'files', 'id' => 'attrs',
            'detail' => $attrs_updated > 0 ? fmt_number($attrs_updated) . ' updated' : 'no changes',
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

    sync_emit('phase_start', ['phase' => 'meta']);
    $t_meta_phase = microtime(true);

    sync_emit('substep_start', ['phase' => 'meta', 'id' => 'filelist', 'label' => 'Loading file list']);
    $start = functions::get_moment();
    $t_filelist = microtime(true);
    $files_total = functions_metadata_admin::count_filelist('', $site_id, true, $opts['only_new']);
    $files = functions_metadata_admin::get_filelist('', $site_id, true, $opts['only_new']);
    $t_filelist = microtime(true) - $t_filelist;
    sync_emit('substep_complete', [
        'phase' => 'meta', 'id' => 'filelist',
        'detail' => fmt_number($files_total) . ' candidates',
        'elapsed' => round($t_filelist, 1),
    ]);

    $template->append('footer_elements', '<!-- get_filelist : '
      . functions::get_elapsed_time($start, functions::get_moment())
      . ' -->');

    sync_emit('substep_start', ['phase' => 'meta', 'id' => 'extract', 'label' => 'Extracting metadata', 'has_progress' => true]);
    $t_extract = microtime(true);

    $start = functions::get_moment();
    $datas = [];
    $meta_datas_total = 0;
    $tags_of = [];
    $tag_names_by_image = [];
    $meta_update_fields = [
        'primary' => ['id'],
        'update'  => array_unique(
            array_merge(
                array_diff(
                    $site_reader->get_metadata_attributes(),
                    ['keywords', 'tags']
                ),
                ['date_metadata_update']
            )
        ),
    ];
    $meta_update_options = isset($_POST['meta_empty_overrides']) ? 0 : $conf->sql_backend::MASS_UPDATES_SKIP_EMPTY;
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
                    'total' => $files_total,
                    'updated' => $meta_datas_total,
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
            $meta_datas_total++;

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

            // Stream to DB every 10k rows to cap $datas memory usage.
            if (! $simulate && count($datas) >= 10000) {
                $conf->sql_backend::mass_updates('images', $meta_update_fields, $datas, $meta_update_options);
                $datas = [];
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
                'total' => $files_total,
                'updated' => $meta_datas_total,
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
            'images_success' => $meta_datas_total,
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
        'detail' => fmt_number($meta_datas_total) . ' updated, ' . fmt_number($meta_skipped) . ' skipped',
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

        // Flush the remaining partial $datas batch (most rows were streamed during extract).
        if ($datas !== []) {
            $conf->sql_backend::mass_updates('images', $meta_update_fields, $datas, $meta_update_options);
            $datas = [];
        }

        functions_admin::set_tags_of($tags_of);

        sync_emit('substep_complete', [
            'phase' => 'meta', 'id' => 'db_update',
            'detail' => fmt_number($meta_datas_total) . ' rows, ' . count($tags_of) . ' tagged',
            'elapsed' => round(microtime(true) - $t_db_update, 1),
        ]);
    }

    sync_emit('phase_complete', [
        'phase' => 'meta',
        'elapsed' => round(microtime(true) - $t_meta_phase, 1),
        'updated' => $meta_datas_total,
        'candidates' => $files_total,
        'skipped' => $meta_skipped,
    ]);

    $template->append('footer_elements', '<!-- metadata update : '
      . functions::get_elapsed_time($start, functions::get_moment())
      . ' -->');

    $template->assign(
        'metadata_result',
        [
            'NB_ELEMENTS_DONE' => $meta_datas_total,
            'NB_ELEMENTS_CANDIDATES' => $files_total,
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
            'updated' => $meta_datas_total,
            'candidates' => $files_total,
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
        'add_to_caddie' => isset($_POST['add_to_caddie']) && $_POST['add_to_caddie'] == 1,
        'subcats_included' => true,
        'privacy_level_selected' => (int) $_POST['privacy_level'],
        'meta_all' => isset($_POST['meta_all']),
        'meta_empty_overrides' => isset($_POST['meta_empty_overrides']),
    ];

} else {
    $tpl_introduction = [
        'sync' => 'dirs',
        'sync_meta' => true,
        'add_to_caddie' => false,
        'subcats_included' => true,
        'privacy_level_selected' => 0,
        'meta_all' => false,
        'meta_empty_overrides' => false,
    ];
}

$tpl_introduction['privacy_level_options'] = functions::get_privacy_level_options();

$template->assign('introduction', $tpl_introduction);


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


// +-----------------------------------------------------------------------+
// |                          sending html code                            |
// +-----------------------------------------------------------------------+
$template->assign_var_from_handle('ADMIN_CONTENT', 'update');
