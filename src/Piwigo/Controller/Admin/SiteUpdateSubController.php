<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin;

use Doctrine\ORM\EntityManagerInterface;
use Override;
use Piwigo\Activity\ActivityService;
use Piwigo\Admin\CoreTabs;
use Piwigo\Admin\CoreTabsContext;
use Piwigo\Admin\Tabsheet;
use Piwigo\Caddie\CaddieService;
use Piwigo\Category\CategoryService;
use Piwigo\Common\ValueObject\UserId;
use Piwigo\Config\CurrentConfig;
use Piwigo\Controller\Admin\Projection\SiteUpdateIntroductionPageContext;
use Piwigo\Controller\Admin\Projection\SiteUpdateMetadataResultPageContext;
use Piwigo\Controller\Admin\Projection\SiteUpdatePageContext;
use Piwigo\Controller\Admin\Projection\SiteUpdateSaveErrorPageContext;
use Piwigo\Controller\Admin\Projection\SiteUpdateSyncErrorsPageContext;
use Piwigo\Controller\Admin\Projection\SiteUpdateSyncResultPageContext;
use Piwigo\Controller\Admin\Request\SiteUpdateRequest;
use Piwigo\Core\CurrentLogger;
use Piwigo\Core\Env;
use Piwigo\Core\HtmlRenderingInterface;
use Piwigo\Core\Lang;
use Piwigo\Core\PageState;
use Piwigo\Core\Paths;
use Piwigo\Core\RedirectServiceInterface;
use Piwigo\Core\StringHelper;
use Piwigo\Core\TimingHelper;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Csrf\CsrfService;
use Piwigo\Db\BatchWriter;
use Piwigo\Image\DerivativeCacheService;
use Piwigo\Image\ImageEntity;
use Piwigo\Image\ImageService;
use Piwigo\Metadata\MetadataRepository;
use Piwigo\Metadata\MetadataService;
use Piwigo\Permalink\PermalinkRepository;
use Piwigo\Permission\PermissionRepository;
use Piwigo\Permission\PermissionService;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Session\SessionService;
use Piwigo\Site\LocalSiteReader;
use Piwigo\Site\SiteEntity;
use Piwigo\Tag\TagService;
use Piwigo\Template\CurrentTemplate;
use Piwigo\Users\CurrentUser;
use Piwigo\Users\UserRepository;
use Piwigo\Validation\InputValidator;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Backs the "site_update" admin page: a full filesystem/DB synchronization
 * pass (new/deleted category detection, new/deleted/updated element
 * detection, metadata sync). This business logic lives directly in this
 * controller rather than a separate service. No free functions are
 * declared in this file.
 *
 * admin.php gates every page behind check_status(AccessLevel::Administrator)
 * before dispatch, so this controller does not duplicate that check.
 *
 * The "Synchronize" POST handler is CSRF-protected by a single CsrfService
 * check as the first statement inside the `isset($post['submit'])` gate
 * below; every downstream block re-checks that same condition but never
 * re-derives $_POST independently, so the one early check covers the whole
 * flow. The template's own `<form>` also carries a hidden pwg_token field
 * (see themes/admin/default/template/site_update.latte).
 *
 * `define('CURRENT_DATE', ...)` is not used here: src/Piwigo/ is
 * arch-tested to contain no `define()` calls (tests/Arch/StructuralTest.php),
 * because a FrankenPHP worker process reuses one process across many
 * requests and PHP constants can never be redefined -- a `define()` inside
 * src/Piwigo/ would silently go stale after its first call in a worker's
 * lifetime. This class reads the local `$dbnow` variable directly instead.
 * `Piwigo\Metadata\MetadataService::syncMetadata()` still falls back to a
 * defensive `defined('CURRENT_DATE') ? constant(...) : null` read (with
 * `install.php`/`upgrade.php` as its only definers), since this class
 * never calls `syncMetadata()`.
 */
final readonly class SiteUpdateSubController implements AdminSubControllerInterface
{
    public function __construct(
        private Lang $lang,
        private RedirectServiceInterface $redirectService,
        private UrlServiceInterface $urlService,
        private CurrentLogger $currentLogger,
        private CoreTabs $coreTabs,
        private SessionService $sessionService,
        private EventDispatcher $eventDispatcher,
        private PageState $pageState,
        private CurrentUser $currentUser,
        private CurrentTemplate $currentTemplate,
        private EntityManagerInterface $entityManager,
        private ActivityService $activityService,
        private MetadataService $metadataService,
        private PermissionService $permissionService,
        private CategoryService $categoryService,
        private TagService $tagService,
        private HtmlRenderingInterface $htmlRenderer,
        private CurrentConfig $currentConfig,
        private InputValidator $inputValidator,
        private Paths $paths,
    ) {}

    private function imageService(): ImageService
    {
        return new ImageService($this->entityManager->getRepository(ImageEntity::class), $this->activityService, $this->sessionService, $this->eventDispatcher, $this->currentConfig, $this->paths, $this->categoryService);
    }

    #[Override]
    public function handle(ServerRequestInterface $request): void
    {
        $logger = $this->currentLogger->get();
        $template = $this->currentTemplate->get();

        if (! $this->currentConfig->enableSynchronization) {
            $this->htmlRenderer
                ->fatalError('synchronization is disabled');
        }

        $siteUpdateRequest = SiteUpdateRequest::fromGlobals($this->inputValidator);

        if (! is_numeric($siteUpdateRequest->siteRaw)) {
            $this->htmlRenderer
                ->fatalError('site param missing or invalid');
        }
        $site_id = (int) $siteUpdateRequest->siteRaw;

        $site_url = $this->entityManager->getRepository(SiteEntity::class)
            ->findGalleriesUrlById($site_id);
        if (! is_string($site_url)) {
            $this->htmlRenderer
                ->fatalError('site ' . $site_id . ' does not exist');
        }
        $site_is_remote = $this->urlService->urlIsRemote($site_url);

        // Env::now() rather than SQL's NOW() -- the real DB-server clock,
        // invisible to Env::now()'s own PIWIGO_TEST_NOW freeze, same
        // reasoning as every other NOW()-reading repository this
        // migration already retargeted (e.g. Comment\CommentRepository::insert()).
        $dbnow = Env::now()->format('Y-m-d H:i:s');

        $error_labels = [
            'PWG-UPDATE-1' => [
                $this->lang->t('wrong filename'),
                $this->lang->t('The name of directories and files must be composed of letters, numbers, "-", "_" or "."'),
            ],
            'PWG-ERROR-NO-FS' => [
                $this->lang->t('File/directory read error'),
                $this->lang->t('The file or directory cannot be accessed (either it does not exist or the access is denied)'),
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
            $this->htmlRenderer
                ->fatalError('remote sites not supported');
        } else {
            $site_reader = new LocalSiteReader($site_url, $this->currentConfig, new MetadataService($this->lang, new MetadataRepository($this->entityManager), $this->currentLogger, $this->eventDispatcher, $this->currentConfig, $this->currentUser, $this->sessionService, $this->paths));
        }

        if ($this->pageState->noMd5sumNumber !== null) {
            $template->assignContext(new SiteUpdateSaveErrorPageContext(
                saveError: '<a href="admin.php?page=batch_manager&amp;filter=prefilter-no_sync_md5sum">' . $this->lang->t('Some checksums are missing.') . '<i class="icon-right"></i></a>',
            ));

        }

        // `footer_elements` accumulates real cross-branch shared state (a
        // debug/timing trace built up across this method's own several
        // sequential, independently-gated sync stages -- dirs/files/
        // metadata) -- not genuinely progressive/AJAX-polled (no
        // flush()/ob_flush()/echo anywhere in this class), so every
        // stage's own message is read back once, together, by the single
        // unconditional SiteUpdatePageContext assign near the end of this
        // method.
        $footer_elements = null;

        // Consumed by CoreTabs::addCoreTabs()'s own 'site_update' case,
        // triggered synchronously inside Tabsheet::select() below -- must
        // be set before that call, not dead code (see this class's own
        // docblock).
        $this->coreTabs->setContext(new CoreTabsContext(myBaseUrl: $this->urlService->getRootUrl() . 'admin.php?page='));

        $tabsheet = new Tabsheet();
        $tabsheet->setId('site_update');
        $tabsheet->select('synchronization', $this->eventDispatcher);
        $tabsheet->assign($this->currentTemplate);

        // $post is a local working copy of the submitted form data. The
        // quick_sync shortcut below sets 8 keys directly on this array to
        // simulate a full form submission, so every later isset()/comparison
        // read in this same handle() call sees the synthesized values.
        $post = $siteUpdateRequest->post;

        if ($siteUpdateRequest->quickSyncRequested) {
            new CsrfService($this->currentConfig)
                ->checkOrFail($this->htmlRenderer, $this->redirectService);

            $post['sync'] = 'files';
            $post['display_info'] = '1';
            $post['add_to_caddie'] = '1';
            $post['privacy_level'] = '0';
            $post['sync_meta'] = '1';
            $post['simulate'] = '0';
            $post['subcats-included'] = '1';
            $post['submit'] = 'Quick Local Synchronization';
        }

        $general_failure = true;
        if (isset($post['submit'])) {
            new CsrfService($this->currentConfig)
                ->checkOrFail($this->htmlRenderer, $this->redirectService);

            if ($site_reader->open()) {
                $general_failure = false;
            }

            // shall we simulate only
            if (isset($post['simulate']) and $post['simulate'] === '1') {
                $simulate = true;
            } else {
                $simulate = false;
            }
        }

        if (isset($post['submit'])
            and ($post['sync'] === 'dirs' or $post['sync'] === 'files')
            and ! $general_failure) {
            $start = TimingHelper::getMoment();
            // which categories to update ?
            $extra_cat_id = null;
            $extra_recursive = false;
            if (isset($post['cat']) and is_numeric($post['cat'])) {
                $extra_cat_id = (int) $post['cat'];
                $extra_recursive = isset($post['subcats-included']) && $post['subcats-included'] === '1';
            }
            // getSyncCandidatesForSite()'s own row shape is id/uppercats/
            // global_rank/status/visible, but this same array is later reused
            // (below) to hold freshly-inserted categories keyed by their new
            // int id, whose entries additionally carry an int 'parent' and int
            // 'id'/'rank'/'global_rank' fields -- PHPStan's own flow analysis
            // merges both shapes into a union from here on, so individual
            // fields still need narrowing/defensive reads at each point of use
            // below.
            $db_categories = array_column($this->categoryService->getSyncCandidatesForSite($site_id, $extra_cat_id, $extra_recursive), null, 'id');

            // get categort full directories in an array for comparison with file
            // system directory tree
            $siteGalleriesUrlLookup = $this->entityManager->getRepository(SiteEntity::class);
            $db_fulldirs = $this->categoryService->getFulldirs(array_map(intval(...), array_keys($db_categories)), $siteGalleriesUrlLookup);

            // what is the base directory to search file system sub-directories ?
            if (isset($post['cat']) and is_numeric($post['cat'])) {
                $basedir = $db_fulldirs[(int) $post['cat']];
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

            foreach ($this->categoryService->getAllIds() as $row_id) {
                $next_rank[$row_id] = 1;
            }

            // let's see if some categories already have some sub-categories...
            foreach ($this->categoryService->getNextRanksByParent() as $row) {
                // for the id_uppercat NULL, we write 'NULL' and not the empty string
                $row_id_uppercat = $row['id_uppercat'] ?? 'NULL';
                // next_rank is a computed "MAX(`rank`)+1" aggregate; fall back to
                // the same default used above for categories without any
                // sub-category yet.
                $next_rank[$row_id_uppercat] = $row['next_rank'] ?? 1;
            }

            // next category id available
            $next_id = $this->categoryService->getNextId();

            // retrieve sub-directories fulldirs from the site reader
            $fs_fulldirs = $site_reader->getFullDirectories($basedir);

            // getFullDirectories doesn't include the base directory, so if it's a
            // category directory, we need to include it in our array
            if (isset($post['cat'])) {
                $fs_fulldirs[] = $basedir;
            }
            // If $_POST['subcats-included'] != 1 ("Search in sub-albums" is unchecked)
            // $db_fulldirs doesn't include any subdirectories and $fs_fulldirs does
            // So $fs_fulldirs will be limited to the selected basedir
            // (if that one is in $fs_fulldirs)
            if (! isset($post['subcats-included']) or $post['subcats-included'] !== '1') {
                $fs_fulldirs = array_intersect($fs_fulldirs, array_keys($db_fulldirs));
            }
            $inserts = [];
            // new categories are the directories not present yet in the database
            foreach (array_diff($fs_fulldirs, array_keys($db_fulldirs)) as $fulldir) {
                $dir = basename($fulldir);
                $sync_chars_regex = $this->currentConfig->syncCharsRegex;
                if ($sync_chars_regex !== '' && (bool) preg_match($sync_chars_regex, $dir)) {
                    $insert = [
                        'id' => $next_id++,
                        'dir' => $dir,
                        'name' => str_replace('_', ' ', $dir),
                        'site_id' => $site_id,
                        'commentable' => $this->currentConfig->newcatDefaultCommentable,
                        'status' => $this->currentConfig->newcatDefaultStatus,
                        'visible' => $this->currentConfig->newcatDefaultVisible,
                    ];

                    if (isset($db_fulldirs[dirname($fulldir)])) {
                        $parent = $db_fulldirs[dirname($fulldir)];

                        // $db_categories[$parent] can be either a raw DB row
                        // (uppercats/global_rank as string|null) or an already-inserted
                        // category from an earlier iteration of this same
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
                        if (! $db_categories[$parent]['visible']) {
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
                        'info' => $this->lang->t('added'),
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
                    $this->categoryService->massInsertCategories($dbfields, $inserts);

                    // add default permissions to categories
                    $category_ids = [];
                    $category_up = [];
                    foreach ($inserts as $category) {
                        $category_ids[] = $category['id'];
                        if (! in_array($category['id_uppercat'] ?? null, [null, 0, '0', ''], true)) {
                            $category_up[] = $category['id_uppercat'];
                        }
                    }

                    $this->activityService->record('album', $category_ids, 'add', [
                        'sync' => true,
                    ]);

                    $category_up = array_values(array_unique($category_up));
                    if ($this->currentConfig->inheritanceByDefault and $category_up !== []) {
                        $granted_grps = new PermissionRepository($this->entityManager)
                            ->findGrantedGroupIdsByCategory($category_up);
                        $granted_users = new PermissionRepository($this->entityManager)
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
                        $this->categoryService->massInsertGroupAccess($insert_granted_grps);
                        $insert_granted_users = array_values(array_unique($insert_granted_users, SORT_REGULAR));
                        $this->permissionService->massInsertUserAccess($insert_granted_users, ignore: false);
                    } else {
                        $this->permissionService
                            ->addPermissionOnCategory($category_ids, array_map(
                                static fn (UserId $id): int => $id->value,
                                new UserRepository($this->entityManager, $this->eventDispatcher, $this->currentConfig)
                                    ->findAdminIds()
                            ));
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
                    'info' => $this->lang->t('deleted'),
                ];

                if (substr_compare($fulldir, '../', 0, 3) === 0) {
                    $fulldir = substr($fulldir, 3);
                }
                $to_delete_derivative_dirs[] = $this->paths->root . $this->currentConfig->derivativeDir . $fulldir;
            }

            if (count($to_delete) > 0) {
                if (! $simulate) {
                    $this->categoryService->deleteCategories($to_delete, $this->activityService, $this->urlService, $this->sessionService, $this->eventDispatcher, new PermalinkRepository($this->entityManager));
                    foreach ($to_delete_derivative_dirs as $to_delete_dir) {
                        if (is_dir($to_delete_dir)) {
                            new DerivativeCacheService($this->currentConfig, $this->paths)
                                ->clearDerivativeCacheRecursive($to_delete_dir, '#.+#');
                        }
                    }
                }
                $counts['del_categories'] = count($to_delete);
            }

            $footer_elements = ['<!-- scanning dirs : '
              . TimingHelper::getElapsedTime($start, TimingHelper::getMoment())
              . ' -->'];
        }
        if (isset($post['submit']) and $post['sync'] === 'files'
              and ! $general_failure) {
            $start_files = TimingHelper::getMoment();
            $start = $start_files;

            $fs = $site_reader->getElements($basedir);

            $footer_elements ??= [];
            $footer_elements[] = '<!-- getElements: '
              . TimingHelper::getElapsedTime($start, TimingHelper::getMoment())
              . ' -->';

            $cat_ids = array_values(array_diff(array_keys($db_categories), $to_delete));

            $db_elements = [];

            if (count($cat_ids) > 0) {
                $db_elements = $this->imageService()
                    ->getIdsAndPathsByStorageCategoryIds($cat_ids);
            }

            // next element id available
            $next_element_id = $this->imageService()
                ->getNextId();

            $start = TimingHelper::getMoment();

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
                $sync_chars_regex = $this->currentConfig->syncCharsRegex;
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
                    'name' => StringHelper::getNameFromFile($filename),
                    'date_available' => $dbnow,
                    'path' => $path,
                    'representative_ext' => $fs[$path]['representative_ext'],
                    'storage_category_id' => $db_fulldirs[$dirname],
                    'added_by' => $this->currentUser->get()
                        ->id->value,
                ];

                if ($post['privacy_level'] !== '0') {
                    $insert['level'] = $post['privacy_level'];
                }

                $inserts[] = $insert;

                $insert_links[] = [
                    'image_id' => $insert['id'],
                    'category_id' => $insert['storage_category_id'],
                ];

                $infos[] = [
                    'path' => $insert['path'],
                    'info' => $this->lang->t('added'),
                ];

                if ($this->currentConfig->isFormatsEnabled) {
                    $element_formats = $fs[$path]['formats'] ?? null;
                    if ($element_formats !== null) {
                        foreach ($element_formats as $ext => $filesize) {
                            $insert_formats[] = [
                                'image_id' => $insert['id'],
                                'ext' => $ext,
                                'filesize' => (int) $filesize,
                            ];

                            $infos[] = [
                                'path' => $insert['path'],
                                'info' => $this->lang->t('format %s added', $ext),
                            ];
                        }
                    }
                }

                $caddiables[] = $insert['id'];
            }

            // search new/removed formats on photos already registered in database
            if ($this->currentConfig->isFormatsEnabled) {
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
                    foreach ($this->entityManager->getRepository(ImageEntity::class)->findFullFormatsByImageIds($existing_ids_int) as $formatRow) {
                        $format_image_id = $formatRow->imageId->value;
                        if (! isset($db_formats[$format_image_id])) {
                            $db_formats[$format_image_id] = [];
                        }

                        $db_formats[$format_image_id][$formatRow->ext] = (string) $formatRow->formatId;
                    }

                    // first we search the formats that were removed
                    foreach ($db_formats as $image_id => $formats) {
                        $element_formats = $fs[$db_elements[$image_id]]['formats'] ?? [];
                        $image_formats_to_delete = array_diff_key($formats, $element_formats);
                        $logger->debug('image_formats_to_delete', 'sync', $image_formats_to_delete);
                        foreach ($image_formats_to_delete as $ext => $format_id) {
                            $formats_to_delete[] = (int) $format_id;

                            $infos[] = [
                                'path' => $db_elements[$image_id],
                                'info' => $this->lang->t('format %s removed', $ext),
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

                        $element_formats = $fs[$path]['formats'] ?? [];
                        $image_formats_to_insert = array_diff_key($element_formats, $formats);
                        $logger->debug('image_formats_to_insert', 'sync', $image_formats_to_insert);
                        foreach ($image_formats_to_insert as $ext => $filesize) {
                            $insert_formats[] = [
                                'image_id' => $image_id,
                                'ext' => $ext,
                                'filesize' => (int) $filesize,
                            ];

                            $infos[] = [
                                'path' => $db_elements[$image_id],
                                'info' => $this->lang->t('format %s added', $ext),
                            ];
                        }
                    }
                }
            }

            if (! $simulate) {
                // inserts all new elements
                if (count($inserts) > 0) {
                    $this->imageService()
                        ->massInsertImages($inserts);

                    // inserts all links between new elements and their storage category
                    $this->imageService()
                        ->insertImageCategoryLinks($insert_links);
                    $this->entityManager->clear();

                    $this->activityService->record('photo', $caddiables, 'add', [
                        'sync' => true,
                    ]);

                    // add new photos to caddie
                    if (isset($post['add_to_caddie']) and $post['add_to_caddie'] === '1') {
                        CaddieService::fillCurrentUserCaddie($caddiables, $this->currentUser);
                    }
                }

                // inserts all formats
                if (count($insert_formats) > 0) {
                    $this->imageService()
                        ->massInsertFormats($insert_formats);
                }

                if (count($formats_to_delete) > 0) {
                    $this->imageService()
                        ->deleteFormatsByIds($formats_to_delete);
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
                $to_delete_elements[] = $element_id;
                $infos[] = [
                    'path' => $path,
                    'info' => $this->lang->t('deleted'),
                ];
            }
            if (count($to_delete_elements) > 0) {
                if (! $simulate) {
                    $this->imageService()
                        ->deleteElements($to_delete_elements, $this->urlService);
                }
                $counts['del_elements'] = count($to_delete_elements);
            }

            $footer_elements[] = '<!-- scanning files : '
              . TimingHelper::getElapsedTime($start_files, TimingHelper::getMoment())
              . ' -->';
        }

        if (isset($post['submit'])
            and ($post['sync'] === 'dirs' or $post['sync'] === 'files')
            and ! $general_failure) {
            if (! $simulate) {
                $syncCategoryService = $this->categoryService;

                $start = TimingHelper::getMoment();
                $syncCategoryService->updateCategory('all');
                $footer_elements ??= [];
                $footer_elements[] = '<!-- update_category(all) : '
                  . TimingHelper::getElapsedTime($start, TimingHelper::getMoment())
                  . ' -->';
                $start = TimingHelper::getMoment();
                $syncCategoryService->updateGlobalRank();
                $footer_elements[] = '<!-- ordering categories : '
                  . TimingHelper::getElapsedTime($start, TimingHelper::getMoment())
                  . ' -->';
            }

            if ($post['sync'] === 'files') {
                $start = TimingHelper::getMoment();
                $opts = [
                    'category_id' => '',
                    'recursive' => true,
                ];
                if (isset($post['cat'])) {
                    $cat = $post['cat'];
                    $opts['category_id'] = is_string($cat) ? $cat : '';
                    if (! isset($post['subcats-included']) or $post['subcats-included'] !== '1') {
                        $opts['recursive'] = false;
                    }
                }
                $files = $this->metadataService
                    ->getFilelist(
                        $opts['category_id'],
                        $site_id,
                        $opts['recursive'],
                        false
                    );
                $footer_elements ??= [];
                $footer_elements[] = '<!-- get_filelist : '
                  . TimingHelper::getElapsedTime($start, TimingHelper::getMoment())
                  . ' -->';
                $start = TimingHelper::getMoment();

                $datas = [];
                foreach ($files as $id => $file) {
                    $data = $site_reader->getElementUpdateAttributes($file['path'])->toArray();
                    $data['id'] = $id;
                    $datas[] = $data;
                } // end foreach file

                $counts['upd_elements'] = count($datas);
                if (! $simulate and count($datas) > 0) {
                    $this->imageService()
                        ->massUpdateFields(
                            [
                                'primary' => ['id'],
                                'update' => $site_reader->getUpdateAttributes(),
                            ],
                            $datas
                        );
                    $this->entityManager->clear();
                }
                $footer_elements[] = '<!-- update files : '
                  . TimingHelper::getElapsedTime($start, TimingHelper::getMoment())
                  . ' -->';
            }// end if sync files
        }

        if (isset($post['submit'])
            and ($post['sync'] === 'dirs' or $post['sync'] === 'files')) {
            $template->assignContext(new SiteUpdateSyncResultPageContext(
                newCategories: $counts['new_categories'],
                delCategories: $counts['del_categories'],
                newElements: $counts['new_elements'],
                delElements: $counts['del_elements'],
                updElements: $counts['upd_elements'],
                errors: count($errors),
            ));
        }

        if (isset($post['submit']) and isset($post['sync_meta'])
                 and ! $general_failure) {
            // sync only never synchronized files ?
            $opts = [
                'only_new' => isset($post['meta_all']) ? false : true,
                'category_id' => '',
                'recursive' => true,
            ];

            if (isset($post['cat'])) {
                $cat = $post['cat'];
                $opts['category_id'] = is_string($cat) ? $cat : '';
                // recursive ?
                if (! isset($post['subcats-included']) or $post['subcats-included'] !== '1') {
                    $opts['recursive'] = false;
                }
            }
            $start = TimingHelper::getMoment();
            $files = $this->metadataService
                ->getFilelist(
                    $opts['category_id'],
                    $site_id,
                    $opts['recursive'],
                    $opts['only_new']
                );

            $footer_elements ??= [];
            $footer_elements[] = '<!-- get_filelist : '
              . TimingHelper::getElapsedTime($start, TimingHelper::getMoment())
              . ' -->';

            $start = TimingHelper::getMoment();
            $datas = [];
            $tags_of = [];

            $tagService = $this->tagService;

            foreach ($files as $id => $element_infos) {
                $data = $site_reader->getElementMetadata($element_infos);

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
                    $this->imageService()
                        ->massUpdateFields(
                            [
                                'primary' => ['id'],
                                'update' => array_values(array_unique(
                                    array_merge(
                                        array_diff(
                                            $site_reader->getMetadataAttributes(),
                                            // keywords and tags fields are managed separately
                                            ['keywords', 'tags']
                                        ),
                                        ['date_metadata_update']
                                    )
                                )),
                            ],
                            $datas,
                            isset($post['meta_empty_overrides']) ? 0 : BatchWriter::SKIP_EMPTY
                        );
                    $this->entityManager->clear();
                }
                $tagService->setTagsOf($tags_of);
            }

            $footer_elements[] = '<!-- metadata update : '
              . TimingHelper::getElapsedTime($start, TimingHelper::getMoment())
              . ' -->';

            $template->assignContext(new SiteUpdateMetadataResultPageContext(
                elementsDone: count($datas),
                elementsCandidates: count($files),
                errors: count($errors),
            ));
        }

        $result_title = '';
        if ($simulate) {
            $result_title .= '[' . $this->lang->t('Simulation') . '] ';
        }

        // used_metadata string is displayed to inform admin which metadata will be
        // used from files for synchronization
        $used_metadata = implode(', ', $site_reader->getMetadataAttributes());

        $template->assignContext(new SiteUpdatePageContext(
            siteUrl: $site_url,
            siteManagerUrl: $this->urlService->getRootUrl() . 'admin.php?page=site_manager',
            resultUpdateLabel: $result_title . $this->lang->t('Search for new images in the directories'),
            resultMetadataLabel: $result_title . $this->lang->t('Metadata synchronization results'),
            metadataList: $used_metadata,
            helpUrl: $this->urlService->getRootUrl() . 'admin/popuphelp.php?page=synchronize',
            adminPageTitle: $this->lang->t('Synchronize'),
            pwgToken: new CsrfService($this->currentConfig)
                ->getToken(),
            footerElements: $footer_elements,
        ));

        if (isset($post['submit'])) {
            $privacy_level_selected = 0;
            if (isset($post['privacy_level']) and is_numeric($post['privacy_level'])) {
                $privacy_level_selected = (int) $post['privacy_level'];
            }

            $sync_value = is_string($post['sync'] ?? null) ? $post['sync'] : 'dirs';
            $sync_meta_value = isset($post['sync_meta']);
            $display_info_value = isset($post['display_info']) && $post['display_info'] === '1';
            $add_to_caddie_value = isset($post['add_to_caddie']) && $post['add_to_caddie'] === '1';
            $subcats_included_value = isset($post['subcats-included']) && $post['subcats-included'] === '1';
            $meta_all_value = isset($post['meta_all']);
            $meta_empty_overrides_value = isset($post['meta_empty_overrides']);

            if (isset($post['cat']) and is_numeric($post['cat'])) {
                $cat_selected = [$post['cat']];
            } else {
                $cat_selected = [];
            }
        } else {
            $sync_value = 'dirs';
            $sync_meta_value = true;
            $display_info_value = false;
            $add_to_caddie_value = false;
            $subcats_included_value = true;
            $privacy_level_selected = 0;
            $meta_all_value = false;
            $meta_empty_overrides_value = false;

            $cat_selected = [];

            if ($siteUpdateRequest->catId !== null) {
                $cat_selected = [$siteUpdateRequest->catId];
                $sync_value = 'files';
            }
        }

        $categoryOptions = $this->categoryService->displaySelectBySite(
            $site_id,
            $cat_selected,
            $this->htmlRenderer,
        );

        $template->assignContext(new SiteUpdateIntroductionPageContext(
            sync: $sync_value,
            syncMeta: $sync_meta_value,
            displayInfo: $display_info_value,
            addToCaddie: $add_to_caddie_value,
            subcatsIncluded: $subcats_included_value,
            privacyLevelSelected: $privacy_level_selected,
            metaAll: $meta_all_value,
            metaEmptyOverrides: $meta_empty_overrides_value,
            privacyLevelOptions: PermissionService::getPrivacyLevelOptions($this->currentConfig, $this->lang),
            categoryOptions: $categoryOptions,
        ));

        $sync_errors = [];
        $sync_error_captions = [];
        if (count($errors) > 0) {
            foreach ($errors as $error) {
                $sync_errors[] = [
                    'ELEMENT' => $error['path'],
                    'LABEL' => $error['type'] . ' (' . $error_labels[$error['type']][0] . ')',
                ];
            }

            foreach ($error_labels as $error_type => $error_description) {
                $sync_error_captions[] = [
                    'TYPE' => $error_type,
                    'LABEL' => $error_description[1],
                ];
            }
        }

        $sync_infos = [];
        if (count($infos) > 0
            and isset($post['display_info'])
            and $post['display_info'] === '1') {
            foreach ($infos as $info) {
                $sync_infos[] = [
                    'ELEMENT' => $info['path'],
                    'LABEL' => $info['info'],
                ];
            }
        }

        $template->assignContext(new SiteUpdateSyncErrorsPageContext(
            syncErrors: $sync_errors,
            syncErrorCaptions: $sync_error_captions,
            syncInfos: $sync_infos,
        ));

        $template->assignVarFromTemplate('ADMIN_CONTENT', 'site_update.latte');
    }
}
