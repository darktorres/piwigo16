<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin;

use Doctrine\DBAL\Connection;
use Piwigo\Admin\CoreTabs;
use Piwigo\Admin\CoreTabsContext;
use Piwigo\Admin\Tabsheet;
use Piwigo\Category\CategoryRepository;
use Piwigo\Category\CategoryService;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\Lang;
use Piwigo\Core\RedirectServiceInterface;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Core\ValidationPattern;
use Piwigo\Db\BatchWriter;
use Piwigo\Db\DbConnection;
use Piwigo\Db\SqlDialect;
use Piwigo\Db\Tables;
use Piwigo\Group\GroupRepository;
use Piwigo\Image\DerivativeCacheService;
use Piwigo\Image\ImageRepository;
use Piwigo\Image\ImageService;
use Piwigo\Metadata\MetadataRepository;
use Piwigo\Metadata\MetadataService;
use Piwigo\Permission\PermissionRepository;
use Piwigo\Permission\PermissionService;
use Piwigo\Site\LocalSiteReader;
use Piwigo\Tag\TagRepository;
use Piwigo\Tag\TagService;
use Piwigo\Template\Template;
use Piwigo\Users\UserRepository;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Replaces admin/site_update.php (page slug "site_update"), folded
 * directly into this controller -- same shape as every prior P23 batch 6
 * sub-batch's shell folding. By far the largest file in this batch
 * (1,100+ lines): a full filesystem/DB synchronization pass (new/deleted
 * category detection, new/deleted/updated element detection, metadata
 * sync), all self-contained business logic directly in the page file
 * rather than delegating to a separate admin/include/*.inc.php helper the
 * way Upload/Albums/Users' pages did. The P21 plan's own scope for this
 * batch names only ConfigService/ConfigRepository/SiteRepository/
 * PermalinkService/MenubarLayoutRepository as reused pieces -- no new
 * "SiteSyncService" is called for, and extracting one now would be a
 * large, high-risk undertaking disproportionate to this batch (matching
 * task #343's own deferral precedent in the Users batch). Left as legacy
 * glue, same "keep page/template logic inline, only extract data access"
 * split used throughout P21 for oversized pages. No free functions are
 * declared in this file, so there's no "redeclare on a second same-process
 * call" risk to fold away here.
 *
 * admin.php itself already gates every page behind
 * check_status(AccessLevel::Administrator) before dispatch (admin.php:65),
 * so the original file's own (redundant, same level, and the only
 * check_status() call in this file) call is dropped here -- same
 * precedent as MaintenanceSubController/ConfigurationSubController.
 *
 * P23 batch 6j-5 fixed the most severe CSRF gap of the whole P23 batch 6
 * effort: the main "Synchronize" POST handler (creates/deletes categories
 * and photos, bulk-overwrites metadata) had zero check_pwg_token() -- only
 * the `quick_sync` GET shortcut was token-gated. Fixed with a single
 * check_pwg_token() as the first statement inside the
 * `isset($_POST['submit'])` gate (every downstream block re-checks that
 * same condition but never re-derives the POST independently, so one
 * early check covers the whole flow); the template's own `<form>` now
 * carries a hidden pwg_token field too (see
 * themes/admin/default/template/site_update.tpl).
 *
 * Also fixed 2 real issues found during a fresh full re-read, not carried
 * over from the earlier batch-6j scoping pass:
 *
 * - `$my_base_url` needed `global $my_base_url;` before its assignment --
 *   the 4th live instance of the P23 batch 6i-4/6j-1 `$my_base_url` bug
 *   class, and unlike those, this one was already broken in production
 *   *before* this batch touched it (confirmed live via curl:
 *   `?page=site_update`'s own "Synchronization"/"Site manager" tab hrefs
 *   were rendering without their `admin.php?page=` prefix), since this
 *   file was already being `include`'d from inside
 *   `SiteUpdateSubController::handle()`'s own call frame even before its
 *   real absorption.
 * - `define('CURRENT_DATE', $dbnow)` is dropped entirely, not just
 *   guarded -- `tests/Arch/StructuralTest.php`'s "src/Piwigo/ contains no
 *   define() calls" rule (part of SEC-60 worker-isolation prep: a future
 *   FrankenPHP worker loop reuses one process across many requests, and
 *   PHP constants can never be redefined, so any `define()` inside
 *   src/Piwigo/ would go stale -- silently, with a non-fatal E_WARNING --
 *   after its first call in a given worker's lifetime) already forbids
 *   this once the code lives here rather than in a legacy admin/*.php
 *   file. Both of this file's own reads of the constant are replaced with
 *   the already-in-scope local `$dbnow` variable directly -- no `define()`/
 *   `constant()` indirection was ever needed for this class's own logic.
 *   `Piwigo\Metadata\MetadataService::syncMetadata()`'s own defensive
 *   `defined('CURRENT_DATE') ? constant(...) : null` read (falling back to
 *   `date('Y-m-d')`) stays untouched -- `install.php`/`upgrade.php` (real
 *   top-level scripts, not src/Piwigo/) remain its only genuine definers;
 *   this class never actually called `syncMetadata()` itself, so nothing
 *   real starts falling back to today's date that didn't already.
 */
final class SiteUpdateSubController implements AdminSubControllerInterface
{
    public function __construct(
        private readonly RedirectServiceInterface $redirectService,
        private readonly UrlServiceInterface $urlService,
    ) {}

    private static function permissionService(Connection $conn): PermissionService
    {
        return new PermissionService(new PermissionRepository($conn), new GroupRepository($conn), new CategoryRepository($conn));
    }

    private static function categoryService(Connection $conn): CategoryService
    {
        return new CategoryService(new CategoryRepository($conn), self::permissionService($conn));
    }

    private static function activityService(Connection $conn): \Piwigo\Activity\ActivityService
    {
        return new \Piwigo\Activity\ActivityService(new \Piwigo\Activity\ActivityRepository($conn));
    }

    /**
     * Get max value plus one of a particular column -- same shape as the
     * retired MysqliDb::nextval(), reused twice in this file (categories,
     * images).
     */
    private static function nextval(Connection $conn, string $column, string $table): int
    {
        $query = '
SELECT IF(MAX(' . $column . ')+1 IS NULL, 1, MAX(' . $column . ')+1)
  FROM ' . $table;
        $next = $conn->fetchOne($query);

        // The IF(...) wrapper guarantees a non-NULL numeric result; fall back to
        // 1 (the same value the query itself uses when MAX() is NULL) if that
        // invariant is ever violated.
        return is_numeric($next) ? (int) $next : 1;
    }

    #[\Override]
    public function handle(ServerRequestInterface $request): void
    {
        $logger = \Piwigo\Core\CurrentLogger::get();
        $template = \Piwigo\Template\CurrentTemplate::get();

        // +-----------------------------------------------------------------------+
        // | Check Access and exit when user status is not ok                      |
        // +-----------------------------------------------------------------------+

        if (! \Piwigo\Config\CurrentConfig::enableSynchronization()) {
            \Piwigo\Bootstrap\PresentationAccessor::htmlService()
                ->fatalError('synchronization is disabled');
        }

        if (! is_numeric($_GET['site'])) {
            \Piwigo\Bootstrap\PresentationAccessor::htmlService()
                ->fatalError('site param missing or invalid');
        }
        $site_id = (int) $_GET['site'];

        // A single connection for the whole request -- mirrors the legacy
        // single-global-mysqli-connection model this migration restores,
        // and avoids the needless-reconnection pattern found in earlier
        // construction-chain debt (Phase 1d finding).
        $conn = DbConnection::build();

        $query = '
SELECT galleries_url
  FROM ' . Tables::sites() . '
  WHERE id = ' . $site_id;
        $site_url = $conn->fetchOne($query);
        if (! is_string($site_url)) {
            \Piwigo\Bootstrap\PresentationAccessor::htmlService()
                ->fatalError('site ' . $site_id . ' does not exist');
        }
        $site_is_remote = $this->urlService->urlIsRemote($site_url);

        $dbnow = $conn->fetchOne('SELECT NOW();');

        $error_labels = [
            'PWG-UPDATE-1' => [
                Lang::t('wrong filename'),
                Lang::t('The name of directories and files must be composed of letters, numbers, "-", "_" or "."'),
            ],
            'PWG-ERROR-NO-FS' => [
                Lang::t('File/directory read error'),
                Lang::t('The file or directory cannot be accessed (either it does not exist or the access is denied)'),
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
            \Piwigo\Bootstrap\PresentationAccessor::htmlService()
                ->fatalError('remote sites not supported');
        } else {
            $site_reader = new LocalSiteReader($site_url);
        }

        if (\Piwigo\Core\PageState::current()->noMd5sumNumber !== null) {
            $template->assign(
                [
                    'save_error' => '<a href="admin.php?page=batch_manager&amp;filter=prefilter-no_sync_md5sum">' . Lang::t('Some checksums are missing.') . '<i class="icon-right"></i></a>',
                ]
            );

        }

        // +-----------------------------------------------------------------------+
        // | tabs                                                                  |
        // +-----------------------------------------------------------------------+

        // Consumed by CoreTabs::addCoreTabs()'s own 'site_update' case,
        // triggered synchronously inside Tabsheet::select() below -- must
        // be set before that call, not dead code (see this class's own
        // docblock).
        CoreTabs::setContext(new CoreTabsContext(myBaseUrl: $this->urlService->getRootUrl() . 'admin.php?page='));

        $tabsheet = new Tabsheet();
        $tabsheet->set_id('site_update');
        $tabsheet->select('synchronization');
        $tabsheet->assign();

        // +-----------------------------------------------------------------------+
        // | Quick sync                                                            |
        // +-----------------------------------------------------------------------+

        if (isset($_GET['quick_sync'])) {
            new \Piwigo\Csrf\CsrfService()
                ->checkOrFail(\Piwigo\Bootstrap\PresentationAccessor::htmlService(), $this->redirectService);

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
            new \Piwigo\Csrf\CsrfService()
                ->checkOrFail(\Piwigo\Bootstrap\PresentationAccessor::htmlService(), $this->redirectService);

            if ($site_reader->open()) {
                $general_failure = false;
            }

            // shall we simulate only
            if (isset($_POST['simulate']) and $_POST['simulate'] === '1') {
                $simulate = true;
            } else {
                $simulate = false;
            }
        }

        // +-----------------------------------------------------------------------+
        // |                      directories / categories                         |
        // +-----------------------------------------------------------------------+
        if (isset($_POST['submit'])
            and ($_POST['sync'] === 'dirs' or $_POST['sync'] === 'files')
            and ! $general_failure) {
            $start = \Piwigo\Core\TimingHelper::getMoment();
            // which categories to update ?
            $query = '
SELECT id, uppercats, global_rank, status, visible
  FROM ' . Tables::categories() . '
  WHERE dir IS NOT NULL
    AND site_id = ' . $site_id;
            if (isset($_POST['cat']) and is_numeric($_POST['cat'])) {
                if (isset($_POST['subcats-included']) and $_POST['subcats-included'] === '1') {
                    $query .= '
    AND uppercats ' . SqlDialect::DB_REGEX_OPERATOR . ' \'(^|,)' . $_POST['cat'] . '(,|$)\'
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
            /** @var array<int|string, array<string, mixed>> $db_categories */
            $db_categories = array_column($conn->fetchAllAssociative($query), null, 'id');

            // get categort full directories in an array for comparison with file
            // system directory tree
            $db_fulldirs = self::categoryService($conn)->getFulldirs(array_map(intval(...), array_keys($db_categories)));

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
  FROM ' . Tables::categories();
            foreach ($conn->fetchAllAssociative($query) as $row) {
                // id is a NOT NULL primary key; skip defensively rather than use a
                // null/non-scalar value as an invalid array key.
                $row_id = $row['id'];
                if (! is_int($row_id) && ! is_string($row_id)) {
                    continue;
                }
                $next_rank[$row_id] = 1;
            }

            // let's see if some categories already have some sub-categories...
            $query = '
SELECT id_uppercat, MAX(`rank`)+1 AS next_rank
  FROM ' . Tables::categories() . '
  GROUP BY id_uppercat';
            foreach ($conn->fetchAllAssociative($query) as $row) {
                // for the id_uppercat NULL, we write 'NULL' and not the empty string
                if (! isset($row['id_uppercat']) or $row['id_uppercat'] === '') {
                    $row['id_uppercat'] = 'NULL';
                }
                // next_rank is a computed "MAX(`rank`)+1" aggregate, always a
                // positive numeric string; fall back to the same default used above
                // for categories without any sub-category yet.
                $row_next_rank = $row['next_rank'];
                $row_id_uppercat = $row['id_uppercat'];
                if (! is_int($row_id_uppercat) && ! is_string($row_id_uppercat)) {
                    continue;
                }
                $next_rank[$row_id_uppercat] = is_numeric($row_next_rank) ? (int) $row_next_rank : 1;
            }

            // next category id available
            $next_id = self::nextval($conn, 'id', Tables::categories());

            // retrieve sub-directories fulldirs from the site reader
            // get_full_directories() is declared to return mixed[], but in practice
            // it always forwards FilesystemHelper::getFsDirectories()'s string[]
            // result; filter defensively so this array's element type is a real
            // string.
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
            if (! isset($_POST['subcats-included']) or $_POST['subcats-included'] !== '1') {
                $fs_fulldirs = array_intersect($fs_fulldirs, array_keys($db_fulldirs));
            }
            $inserts = [];
            // new categories are the directories not present yet in the database
            foreach (array_diff($fs_fulldirs, array_keys($db_fulldirs)) as $fulldir) {
                $dir = basename($fulldir);
                $sync_chars_regex = \Piwigo\Config\CurrentConfig::syncCharsRegex();
                if ($sync_chars_regex !== '' && (bool) preg_match($sync_chars_regex, $dir)) {
                    $insert = [
                        'id' => $next_id++,
                        'dir' => $dir,
                        'name' => str_replace('_', ' ', $dir),
                        'site_id' => $site_id,
                        'commentable' => \Piwigo\Config\CurrentConfig::newcatDefaultCommentable(),
                        'status' => \Piwigo\Config\CurrentConfig::newcatDefaultStatus(),
                        'visible' => \Piwigo\Config\CurrentConfig::newcatDefaultVisible(),
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
                        if ($db_categories[$parent]['status'] === 'private') {
                            $insert['status'] = 'private';
                        }
                        if (! (bool) $db_categories[$parent]['visible']) {
                            $insert['visible'] = false;
                        }
                    } else {
                        $insert['uppercats'] = $insert['id'];
                        $insert['rank'] = $next_rank['NULL']++;
                        $insert['global_rank'] = $insert['rank'];
                    }

                    $inserts[] = $insert;
                    $infos[] = [
                        'path' => $fulldir,
                        'info' => Lang::t('added'),
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
                    new BatchWriter($conn)
                        ->massInsert(Tables::categories(), $dbfields, $inserts);

                    // add default permissions to categories
                    $category_ids = [];
                    $category_up = [];
                    foreach ($inserts as $category) {
                        $category_ids[] = $category['id'];
                        if (! in_array($category['id_uppercat'] ?? null, [null, false, 0, '0', '', []], true)) {
                            $category_up[] = $category['id_uppercat'];
                        }
                    }

                    self::activityService($conn)->record('album', $category_ids, 'add', [
                        'sync' => true,
                    ]);

                    $category_up = array_values(array_unique($category_up));
                    if (\Piwigo\Config\CurrentConfig::inheritanceByDefault() and $category_up !== []) {
                        $granted_grps = new PermissionRepository($conn)
                            ->findGrantedGroupIdsByCategory($category_up);
                        $granted_users = new PermissionRepository($conn)
                            ->findGrantedUserIdsByCategory($category_up);
                        $insert_granted_users = [];
                        $insert_granted_grps = [];
                        foreach ($category_ids as $ids) {
                            // 'parent' only exists on the freshly-inserted-category
                            // shape of $db_categories entries (see above); narrow to
                            // int so it's safe to use as an array key below.
                            $parent_id = $db_categories[$ids]['parent'] ?? null;
                            $parent_id = is_int($parent_id) ? $parent_id : null;
                            while ($parent_id !== null && in_array($parent_id, $category_ids, true)) {
                                $parent_id = $db_categories[$parent_id]['parent'] ?? null;
                                $parent_id = is_int($parent_id) ? $parent_id : null;
                            }
                            if ($db_categories[$ids]['status'] === 'private' and $parent_id !== null) {
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
                        new BatchWriter($conn)
                            ->massInsert(Tables::groupAccess(), ['group_id', 'cat_id'], $insert_granted_grps);
                        $insert_granted_users = array_unique($insert_granted_users, SORT_REGULAR);
                        new BatchWriter($conn)
                            ->massInsert(Tables::userAccess(), ['user_id', 'cat_id'], $insert_granted_users);
                    } else {
                        self::permissionService($conn)
                            ->addPermissionOnCategory($category_ids, new UserRepository($conn)->findAdminIds());
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
                    'info' => Lang::t('deleted'),
                ];

                if (substr_compare($fulldir, '../', 0, 3) === 0) {
                    $fulldir = substr($fulldir, 3);
                }
                $to_delete_derivative_dirs[] = \Piwigo\Core\CurrentPaths::get()->root . CurrentConfig::derivativeDir() . $fulldir;
            }

            if (count($to_delete) > 0) {
                if (! $simulate) {
                    self::categoryService($conn)->deleteCategories($to_delete, self::activityService($conn), $this->urlService);
                    foreach ($to_delete_derivative_dirs as $to_delete_dir) {
                        if (is_dir($to_delete_dir)) {
                            new DerivativeCacheService()
                                ->clearDerivativeCacheRecursive($to_delete_dir, '#.+#');
                        }
                    }
                }
                $counts['del_categories'] = count($to_delete);
            }

            $template->append('footer_elements', '<!-- scanning dirs : '
              . \Piwigo\Core\TimingHelper::getElapsedTime($start, \Piwigo\Core\TimingHelper::getMoment())
              . ' -->');
        }
        // +-----------------------------------------------------------------------+
        // |                           files / elements                            |
        // +-----------------------------------------------------------------------+
        if (isset($_POST['submit']) and $_POST['sync'] === 'files'
              and ! $general_failure) {
            $start_files = \Piwigo\Core\TimingHelper::getMoment();
            $start = $start_files;

            $fs = $site_reader->get_elements($basedir);

            $template->append('footer_elements', '<!-- get_elements: '
              . \Piwigo\Core\TimingHelper::getElapsedTime($start, \Piwigo\Core\TimingHelper::getMoment())
              . ' -->');

            $cat_ids = array_diff(array_keys($db_categories), $to_delete);

            $db_elements = [];

            if (count($cat_ids) > 0) {
                $query = '
SELECT id, path
  FROM ' . Tables::images() . '
  WHERE storage_category_id IN ('
                  . wordwrap(
                      implode(', ', $cat_ids),
                      160,
                      "\n"
                  ) . ')';
                // path is a NOT NULL varchar column, so filter defensively to
                // guarantee real strings here.
                $db_elements = array_filter(array_column($conn->fetchAllAssociative($query), 'path', 'id'), is_string(...));
            }

            // next element id available
            $next_element_id = self::nextval($conn, 'id', Tables::images());

            $start = \Piwigo\Core\TimingHelper::getMoment();

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
                $sync_chars_regex = \Piwigo\Config\CurrentConfig::syncCharsRegex();
                if ($sync_chars_regex === '' || ! (bool) preg_match($sync_chars_regex, $filename)) {
                    $errors[] = [
                        'path' => $path,
                        'type' => 'PWG-UPDATE-1',
                    ];

                    continue;
                }

                $insert = [
                    'id' => $next_element_id++,
                    'file' => $filename,
                    'name' => \Piwigo\Core\StringHelper::getNameFromFile($filename),
                    'date_available' => $dbnow,
                    'path' => $path,
                    'representative_ext' => $fs[$path]['representative_ext'],
                    'storage_category_id' => $db_fulldirs[$dirname],
                    'added_by' => \Piwigo\Users\CurrentUser::get()->id,
                ];

                if ($_POST['privacy_level'] !== '0') {
                    $insert['level'] = $_POST['privacy_level'];
                }

                $inserts[] = $insert;

                $insert_links[] = [
                    'image_id' => $insert['id'],
                    'category_id' => $insert['storage_category_id'],
                ];

                $infos[] = [
                    'path' => $insert['path'],
                    'info' => Lang::t('added'),
                ];

                if (\Piwigo\Config\CurrentConfig::isFormatsEnabled()) {
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
                                'info' => Lang::t('format %s added', $ext),
                            ];
                        }
                    }
                }

                $caddiables[] = $insert['id'];
            }

            // search new/removed formats on photos already registered in database
            if (\Piwigo\Config\CurrentConfig::isFormatsEnabled()) {
                $db_elements_flip = array_flip($db_elements);

                $existing_ids = [];

                foreach (array_intersect_key($fs, $db_elements_flip) as $path => $existing) {
                    $existing_ids[] = $db_elements_flip[$path];
                }

                $logger->debug('existing_ids', 'sync', $existing_ids);

                if (count($existing_ids) > 0) {
                    $db_formats = [];

                    // find formats for existing photos (already in database)
                    $existing_ids_int = array_values(array_map(intval(...), array_filter($existing_ids, is_numeric(...))));
                    foreach (new ImageRepository($conn)->findFullFormatsByImageIds($existing_ids_int) as $formatRow) {
                        $format_image_id = $formatRow->imageId;
                        if (! isset($db_formats[$format_image_id])) {
                            $db_formats[$format_image_id] = [];
                        }

                        $db_formats[$format_image_id][$formatRow->ext] = (string) $formatRow->formatId;
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
                                'info' => Lang::t('format %s removed', $ext),
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
                                'info' => Lang::t('format %s added', $ext),
                            ];
                        }
                    }
                }
            }

            if (! $simulate) {
                // inserts all new elements
                if (count($inserts) > 0) {
                    new BatchWriter($conn)
                        ->massInsert(
                            Tables::images(),
                            array_keys($inserts[0]),
                            $inserts
                        );

                    // inserts all links between new elements and their storage category
                    new BatchWriter($conn)
                        ->massInsert(
                            Tables::imageCategory(),
                            array_keys($insert_links[0]),
                            $insert_links
                        );

                    self::activityService($conn)->record('photo', $caddiables, 'add', [
                        'sync' => true,
                    ]);

                    // add new photos to caddie
                    if (isset($_POST['add_to_caddie']) and $_POST['add_to_caddie'] === '1') {
                        \Piwigo\Caddie\CaddieService::fillCurrentUserCaddie($caddiables);
                    }
                }

                // inserts all formats
                if (count($insert_formats) > 0) {
                    new BatchWriter($conn)
                        ->massInsert(
                            Tables::imageFormat(),
                            array_keys($insert_formats[0]),
                            $insert_formats
                        );
                }

                if (count($formats_to_delete) > 0) {
                    $query = '
DELETE
  FROM ' . Tables::imageFormat() . '
  WHERE format_id IN (' . implode(',', $formats_to_delete) . ')
;';
                    $conn->executeStatement($query);
                }
            }

            $counts['new_elements'] = count($inserts);

            // delete elements that are in database but not in the filesystem
            $to_delete_elements = [];
            foreach (array_diff($db_elements, array_keys($fs)) as $path) {
                // $path is sourced from $db_elements itself (via array_diff), so
                // it's always found in it
                $element_id = array_search($path, $db_elements, true);
                assert($element_id !== false);
                $to_delete_elements[] = (int) $element_id;
                $infos[] = [
                    'path' => $path,
                    'info' => Lang::t('deleted'),
                ];
            }
            if (count($to_delete_elements) > 0) {
                if (! $simulate) {
                    new ImageService(new ImageRepository($conn), self::activityService($conn))
                        ->deleteElements($to_delete_elements, $this->urlService);
                }
                $counts['del_elements'] = count($to_delete_elements);
            }

            $template->append('footer_elements', '<!-- scanning files : '
              . \Piwigo\Core\TimingHelper::getElapsedTime($start_files, \Piwigo\Core\TimingHelper::getMoment())
              . ' -->');
        }

        // +-----------------------------------------------------------------------+
        // |                          synchronize files                            |
        // +-----------------------------------------------------------------------+
        if (isset($_POST['submit'])
            and ($_POST['sync'] === 'dirs' or $_POST['sync'] === 'files')
            and ! $general_failure) {
            if (! $simulate) {
                $syncCategoryService = self::categoryService($conn);

                $start = \Piwigo\Core\TimingHelper::getMoment();
                $syncCategoryService->updateCategory('all');
                $template->append('footer_elements', '<!-- update_category(all) : '
                  . \Piwigo\Core\TimingHelper::getElapsedTime($start, \Piwigo\Core\TimingHelper::getMoment())
                  . ' -->');
                $start = \Piwigo\Core\TimingHelper::getMoment();
                $syncCategoryService->updateGlobalRank();
                $template->append('footer_elements', '<!-- ordering categories : '
                  . \Piwigo\Core\TimingHelper::getElapsedTime($start, \Piwigo\Core\TimingHelper::getMoment())
                  . ' -->');
            }

            if ($_POST['sync'] === 'files') {
                $start = \Piwigo\Core\TimingHelper::getMoment();
                $opts = [
                    'category_id' => '',
                    'recursive' => true,
                ];
                if (isset($_POST['cat'])) {
                    $cat = $_POST['cat'];
                    $opts['category_id'] = is_string($cat) ? $cat : '';
                    if (! isset($_POST['subcats-included']) or $_POST['subcats-included'] !== '1') {
                        $opts['recursive'] = false;
                    }
                }
                $files = new MetadataService(new MetadataRepository($conn))
                    ->getFilelist(
                        $opts['category_id'],
                        $site_id,
                        $opts['recursive'],
                        false
                    );
                $template->append('footer_elements', '<!-- get_filelist : '
                  . \Piwigo\Core\TimingHelper::getElapsedTime($start, \Piwigo\Core\TimingHelper::getMoment())
                  . ' -->');
                $start = \Piwigo\Core\TimingHelper::getMoment();

                $datas = [];
                foreach ($files as $id => $file) {
                    $path = is_string($file['path'] ?? null) ? $file['path'] : '';
                    $data = $site_reader->get_element_update_attributes($path);
                    $data['id'] = $id;
                    $datas[] = $data;
                } // end foreach file

                $counts['upd_elements'] = count($datas);
                if (! $simulate and count($datas) > 0) {
                    new BatchWriter($conn)
                        ->massUpdate(
                            Tables::images(),
                            // fields
                            [
                                'primary' => ['id'],
                                'update' => $site_reader->get_update_attributes(),
                            ],
                            $datas
                        );
                }
                $template->append('footer_elements', '<!-- update files : '
                  . \Piwigo\Core\TimingHelper::getElapsedTime($start, \Piwigo\Core\TimingHelper::getMoment())
                  . ' -->');
            }// end if sync files
        }

        // +-----------------------------------------------------------------------+
        // |                          synchronize files                            |
        // +-----------------------------------------------------------------------+
        if (isset($_POST['submit'])
            and ($_POST['sync'] === 'dirs' or $_POST['sync'] === 'files')) {
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
                $opts['category_id'] = is_string($cat) ? $cat : '';
                // recursive ?
                if (! isset($_POST['subcats-included']) or $_POST['subcats-included'] !== '1') {
                    $opts['recursive'] = false;
                }
            }
            $start = \Piwigo\Core\TimingHelper::getMoment();
            $files = new MetadataService(new MetadataRepository($conn))
                ->getFilelist(
                    $opts['category_id'],
                    $site_id,
                    $opts['recursive'],
                    $opts['only_new']
                );

            $template->append('footer_elements', '<!-- get_filelist : '
              . \Piwigo\Core\TimingHelper::getElapsedTime($start, \Piwigo\Core\TimingHelper::getMoment())
              . ' -->');

            $start = \Piwigo\Core\TimingHelper::getMoment();
            $datas = [];
            $tags_of = [];

            $tagService = new TagService(new TagRepository($conn), self::permissionService($conn), self::activityService($conn));

            foreach ($files as $id => $element_infos) {
                $data = $site_reader->get_element_metadata($element_infos);

                if (is_array($data)) {
                    $data['date_metadata_update'] = $dbnow;
                    $data['id'] = $id;
                    $datas[] = $data;

                    foreach (['keywords', 'tags'] as $key) {
                        if (isset($data[$key]) && is_string($data[$key])) {
                            if (! isset($tags_of[$id])) {
                                $tags_of[$id] = [];
                            }

                            foreach (explode(',', $data[$key]) as $tag_name) {
                                $tags_of[$id][] = $tagService->tagIdFromTagName($tag_name);
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
                    new BatchWriter($conn)
                        ->massUpdate(
                            Tables::images(),
                            // fields
                            [
                                'primary' => ['id'],
                                'update' => array_values(array_unique(
                                    array_merge(
                                        array_diff(
                                            $site_reader->get_metadata_attributes(),
                                            // keywords and tags fields are managed separately
                                            ['keywords', 'tags']
                                        ),
                                        ['date_metadata_update']
                                    )
                                )),
                            ],
                            $datas,
                            isset($_POST['meta_empty_overrides']) ? 0 : BatchWriter::SKIP_EMPTY
                        );
                }
                $tagService->setTagsOf($tags_of);
            }

            $template->append('footer_elements', '<!-- metadata update : '
              . \Piwigo\Core\TimingHelper::getElapsedTime($start, \Piwigo\Core\TimingHelper::getMoment())
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
            $result_title .= '[' . Lang::t('Simulation') . '] ';
        }

        // used_metadata string is displayed to inform admin which metadata will be
        // used from files for synchronization
        $used_metadata = implode(', ', $site_reader->get_metadata_attributes());

        $template->assign(
            [
                'SITE_URL' => $site_url,
                'U_SITE_MANAGER' => $this->urlService->getRootUrl() . 'admin.php?page=site_manager',
                'L_RESULT_UPDATE' => $result_title . Lang::t('Search for new images in the directories'),
                'L_RESULT_METADATA' => $result_title . Lang::t('Metadata synchronization results'),
                'METADATA_LIST' => $used_metadata,
                'U_HELP' => $this->urlService->getRootUrl() . 'admin/popuphelp.php?page=synchronize',
                'ADMIN_PAGE_TITLE' => Lang::t('Synchronize'),
                'PWG_TOKEN' => new \Piwigo\Csrf\CsrfService()
                    ->getToken(),
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
                'display_info' => isset($_POST['display_info']) and $_POST['display_info'] === '1',
                'add_to_caddie' => isset($_POST['add_to_caddie']) and $_POST['add_to_caddie'] === '1',
                'subcats_included' => isset($_POST['subcats-included']) and $_POST['subcats-included'] === '1',
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
                new \Piwigo\Validation\InputValidator()
                    ->validate('cat_id', $_GET, false, ValidationPattern::ID);

                $cat_selected = [$_GET['cat_id']];
                $tpl_introduction['sync'] = 'files';
            }
        }

        $tpl_introduction['privacy_level_options'] = \Piwigo\Permission\PermissionService::getPrivacyLevelOptions();

        $template->assign('introduction', $tpl_introduction);

        $query = '
SELECT id,name,uppercats,global_rank
  FROM ' . Tables::categories() . '
  WHERE site_id = ' . $site_id;
        self::categoryService($conn)->displaySelectCatWrapper(
            $query,
            $cat_selected,
            'category_options',
            \Piwigo\Bootstrap\PresentationAccessor::htmlService(),
            $template,
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
            and $_POST['display_info'] === '1') {
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
    }
}
