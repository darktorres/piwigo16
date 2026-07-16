<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin;

use Piwigo\Admin\BatchManager\FilterResolver;
use Piwigo\Admin\BatchManagerGlobalPageRenderer;
use Piwigo\Admin\BatchManagerUnitPageRenderer;
use Piwigo\Admin\tabsheet;
use Piwigo\Cache\PersistentFileCache;
use Piwigo\Core\ValidationPattern;
use Piwigo\Db\DbConnection;
use Piwigo\Db\Tables;
use Piwigo\Group\GroupRepository;
use Piwigo\Image\ImageRepository;
use Piwigo\Image\ImageService;
use Piwigo\Mail\MailService;
use Piwigo\Permission\PermissionRepository;
use Piwigo\Permission\PermissionService;
use Piwigo\Search\SearchRepository;
use Piwigo\Search\SearchService;
use Piwigo\Tag\TagRepository;
use Piwigo\Tag\TagService;
use Piwigo\Template\Template;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Replaces admin/batch_manager.php's own tab-dispatch shell (page slug
 * "batch_manager"), folded directly into this controller -- same shape as
 * AlbumSubController/PhotoSubController (the shell logic goes into the
 * sub-controller itself, tab bodies become their own renderer classes).
 * The shell's own real "data access" concern (resolving
 * $_SESSION['bulk_manager_filter'] into photo id lists) was already
 * migrated off ~320 lines of inline SQL onto Piwigo\Admin\BatchManager\
 * FilterResolver back in P21 -- this class absorbs what was left:
 * session-filter parsing, FilterResolver orchestration, pagination, the
 * dimension/filesize filter-form option aggregation, and tab dispatch to
 * BatchManagerGlobalPageRenderer/BatchManagerUnitPageRenderer (P23 batch
 * 6g). admin.php itself already gates every page behind
 * check_status(AccessLevel::Administrator) before dispatch, so the
 * original file's own (redundant) check_status() call is dropped here --
 * same precedent as every prior P23 batch 6 sub-batch.
 *
 * Real CSRF fix (P23 batch 6g): the `action=empty_caddie` GET link ran an
 * unconditional `DELETE FROM piwigo_caddie WHERE user_id = ...` with zero
 * token verification -- confirmed via a direct grep that
 * batch_manager_filter.inc.tpl's link never carried a pwg_token either.
 * check_pwg_token() added to that branch, `pwg_token` appended to the
 * link (CsrfService::check() reads $_REQUEST, so a GET-carried token works
 * the same as a POST one, confirmed by reading the class directly before
 * relying on it). The other 2 GET actions (`delete_orphans`,
 * `sync_md5sum`) only render a $_SESSION message from an
 * already-validated count -- the real deletion/checksum work happens via
 * already-token-protected ws.php calls (pwg.images.deleteOrphans/
 * pwg.images.setMd5sum, confirmed in batchManagerGlobal.js) -- so no fix
 * needed there.
 */
final class BatchManagerSubController implements AdminSubControllerInterface
{
    #[\Override]
    public function handle(ServerRequestInterface $request): void
    {
        /**
         * @var array<string, mixed> $conf
         * @var array<string, mixed> $page
         * @var Template $template
         * @var array<string, mixed> $user
         */
        global $conf, $page, $template, $user;

        (new \Piwigo\Validation\InputValidator())->validate('selection', $_POST, true, ValidationPattern::ID);
        (new \Piwigo\Validation\InputValidator())->validate('display', $_REQUEST, false, '/^(\d+|all)$/');

        // $user['id'] (the logged in / guest user id) is always numeric here (DB
        // primary key, or $conf['guest_id']); narrow once and reuse at every query
        // site below instead of re-reading the offset (each re-read is `mixed`).
        $user_id = is_numeric($user['id'] ?? null) ? (int) $user['id'] : 0;

        // $conf['available_permission_levels'] and $conf['order_by'] are read from
        // a loosely-typed config bag at several sites below; narrow each once and
        // reuse the local variable everywhere instead of re-reading the offset.
        $available_permission_levels = is_array($conf['available_permission_levels'] ?? null) ? $conf['available_permission_levels'] : [];
        $conf_order_by = is_string($conf['order_by'] ?? null) ? $conf['order_by'] : '';

        // used both for the action-specific redirects below and for the
        // "category no longer exists" redirect further down
        $get_page = is_string($_GET['page'] ?? null) ? $_GET['page'] : '';

        $this->handleGetActions($get_page, $user_id);

        $this->resolveSessionFilter($available_permission_levels);

        /** @var array<string, mixed> $bulk_filter */
        $bulk_filter = is_array($_SESSION['bulk_manager_filter'] ?? null) ? $_SESSION['bulk_manager_filter'] : [];

        $filter_resolver = new FilterResolver(DbConnection::build());

        $page['cat_elements_id'] = $this->computeCurrentSet(
            $filter_resolver,
            $bulk_filter,
            $get_page,
            $user_id,
            $conf_order_by,
        );

        // +-------------------------------------------------------------------+
        // |                       first element to display                        |
        // +-------------------------------------------------------------------+

        // $page['start'] contains the number of the first element in its
        // category. For exampe, $page['start'] = 12 means we must show elements #12
        // and $page['nb_images'] next elements

        if (! isset($_REQUEST['start'])
            or ! is_numeric($_REQUEST['start'])
            or $_REQUEST['start'] < 0
            or (isset($_REQUEST['display']) and $_REQUEST['display'] === 'all')) {
            $page['start'] = 0;
        } else {
            $page['start'] = $_REQUEST['start'];
        }

        // +-------------------------------------------------------------------+
        // |                                 Tabs                                  |
        // +-------------------------------------------------------------------+

        if (isset($_GET['mode'])) {
            (new \Piwigo\Validation\InputValidator())->validate('mode', $_GET, false, '/^(global|unit)$/');
            $page['tab'] = is_string($_GET['mode']) ? $_GET['mode'] : 'global';
        } else {
            $page['tab'] = 'global';
        }

        $tabsheet = new tabsheet();
        $tabsheet->set_id('batch_manager');
        $tabsheet->select($page['tab']);
        $tabsheet->assign();

        // +-------------------------------------------------------------------+
        // |                              dimensions                               |
        // +-------------------------------------------------------------------+

        $template->assign('dimensions', $this->computeDimensionOptions($bulk_filter));

        // +-------------------------------------------------------------------+
        // | filesize                                                              |
        // +-------------------------------------------------------------------+

        $template->assign('filesize', $this->computeFilesizeOptions($bulk_filter));

        // +-------------------------------------------------------------------+
        // |                         open specific mode                            |
        // +-------------------------------------------------------------------+

        if ($page['tab'] === 'unit') {
            new BatchManagerUnitPageRenderer()
                ->render();
        } else {
            new BatchManagerGlobalPageRenderer()
                ->render();
        }
    }

    private function handleGetActions(string $getPage, int $userId): void
    {
        if (! isset($_GET['action'])) {
            return;
        }

        if ($_GET['action'] === 'empty_caddie') {
            check_pwg_token();

            $query = '
DELETE FROM ' . Tables::caddie() . '
  WHERE user_id = ' . $userId . '
;';
            \Piwigo\Db\MysqliDb::query($query);

            $_SESSION['page_infos'] = [
                l10n('Information data registered in database'),
            ];

            redirect(get_root_url() . 'admin.php?page=' . $getPage);
        }

        if ($_GET['action'] === 'delete_orphans' and isset($_GET['nb_orphans_deleted'])) {
            (new \Piwigo\Validation\InputValidator())->validate('nb_orphans_deleted', $_GET, false, '/^\d+$/');
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

                redirect(get_root_url() . 'admin.php?page=' . $getPage);
            }
        }

        if ($_GET['action'] === 'sync_md5sum' and isset($_GET['nb_md5sum_added'])) {
            (new \Piwigo\Validation\InputValidator())->validate('nb_md5sum_added', $_GET, false, '/^\d+$/');
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

                redirect(get_root_url() . 'admin.php?page=' . $getPage);
            }
        }
    }

    /**
     * @param array<int|string, mixed> $availablePermissionLevels
     */
    private function resolveSessionFilter(array $availablePermissionLevels): void
    {
        // filters from form
        if (isset($_POST['submitFilter'])) {
            // echo '<pre>'; print_r($_POST); echo '</pre>';
            unset($_REQUEST['start']); // new photo set must reset the page
            $_SESSION['bulk_manager_filter'] = [];

            if (isset($_POST['filter_prefilter_use'])) {
                $_SESSION['bulk_manager_filter']['prefilter'] = $_POST['filter_prefilter'];

                if ($_POST['filter_prefilter'] === 'duplicates') {
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
                (new \Piwigo\Validation\InputValidator())->validate('filter_category', $_POST, false, ValidationPattern::ID);

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
                $filterTagConn = DbConnection::build();
                $_SESSION['bulk_manager_filter']['tags'] = new TagService(new TagRepository($filterTagConn), new PermissionService(new PermissionRepository($filterTagConn), new GroupRepository($filterTagConn)), new \Piwigo\Activity\ActivityService(new \Piwigo\Activity\ActivityRepository(\Piwigo\Db\DbConnection::build())))
                    ->getTagIds($filter_tags, false);

                if (isset($_POST['tag_mode']) and in_array($_POST['tag_mode'], ['AND', 'OR'], true)) {
                    $_SESSION['bulk_manager_filter']['tag_mode'] = $_POST['tag_mode'];
                }
            }

            if (isset($_POST['filter_level_use'])) {
                (new \Piwigo\Validation\InputValidator())->validate('filter_level', $_POST, false, '/^\d+$/');

                // $_POST['filter_level'] is a numeric string (validated by
                // check_input_parameter() above); $availablePermissionLevels holds
                // real ints (config_default.inc.php seeds [0, 1, 2, 4, 8]) -- cast
                // before the strict in_array() so a numeric-string match still
                // succeeds, matching the original loose-comparison behavior.
                if (is_numeric($_POST['filter_level'] ?? null) && in_array((int) $_POST['filter_level'], $availablePermissionLevels, true)) {
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

                            if (in_array($duplicate_field, ['filename', 'checksum', 'date', 'dimensions'], true)) {
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
                        if (is_numeric($value) && in_array((int) $value, $availablePermissionLevels, true)) {
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

        if (! isset($_SESSION['bulk_manager_filter']) || $_SESSION['bulk_manager_filter'] === []) {
            $_SESSION['bulk_manager_filter'] = [
                'prefilter' => 'caddie',
            ];
        }

        if (! is_array($_SESSION['bulk_manager_filter'])) {
            // Defensive: bulk_manager_filter is only ever written as an array by
            // this method (the $_POST/$_GET branches above, or the default fallback
            // just above); this guards against corrupted/foreign session state,
            // and lets PHPStan track a real array shape for the reads below.
            $_SESSION['bulk_manager_filter'] = [
                'prefilter' => 'caddie',
            ];
        }
    }

    /**
     * @param array<string, mixed> $bulkFilter
     *
     * @return array<mixed>
     */
    private function computeCurrentSet(
        FilterResolver $filterResolver,
        array $bulkFilter,
        string $getPage,
        int $userId,
        string $confOrderBy,
    ): array {
        /**
         * @var array<string, mixed> $page
         * @var Template $template
         */
        global $page, $template;

        $filter_sets = [];
        if (isset($bulkFilter['prefilter']) && is_string($bulkFilter['prefilter'])) {
            $prefilter = $bulkFilter['prefilter'];

            // $duplicates_on_fields is only ever computed for the 'duplicates'
            // prefilter (matching the legacy switch's own scoping) -- assigned to
            // $page['duplicates_on_fields'] so BatchManagerGlobalPageRenderer can
            // read it back for its own duplicates-mode thumbnail ordering; the
            // original admin/batch_manager.php shared this via PHP's include()
            // scope instead, which doesn't survive splitting the shell and the
            // tab body into separate classes.
            if ($prefilter === 'duplicates') {
                $page['duplicates_on_fields'] = $filterResolver->duplicateFieldsFromFilter($bulkFilter);
            }

            $prefilter_result = match ($prefilter) {
                // getOrphans()/getPhotosNoMd5sum() are existing, already-tested
                // ImageService methods -- not duplicated into FilterResolver.
                'no_album' => new ImageService(new ImageRepository(DbConnection::build()), new \Piwigo\Activity\ActivityService(new \Piwigo\Activity\ActivityRepository(DbConnection::build())))->getOrphans(),
                'no_sync_md5sum' => new ImageService(new ImageRepository(DbConnection::build()), new \Piwigo\Activity\ActivityService(new \Piwigo\Activity\ActivityRepository(DbConnection::build())))->getPhotosNoMd5sum(),
                default => $filterResolver->resolvePrefilter($prefilter, $bulkFilter, $userId, $confOrderBy),
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

        if (isset($bulkFilter['category']) && is_numeric($bulkFilter['category'])) {
            $category_id = (int) $bulkFilter['category'];

            // we need to check the category still exists (it may have been deleted since it was added in the session)
            if (! $filterResolver->categoryExists($category_id)) {
                unset($_SESSION['bulk_manager_filter']);
                redirect(get_root_url() . 'admin.php?page=' . $getPage);
            }

            $categories = isset($bulkFilter['category_recursive'])
                ? get_subcat_ids([$category_id])
                : [$category_id];
            $categories = array_values(array_map(intval(...), array_filter($categories, is_numeric(...))));

            $filter_sets[] = $filterResolver->categoryImageIds($categories);
        }

        if (isset($bulkFilter['level']) && is_numeric($bulkFilter['level'])) {
            $filter_sets[] = $filterResolver->levelPhotoIds(
                (int) $bulkFilter['level'],
                isset($bulkFilter['level_include_lower']),
                $confOrderBy
            );
        }

        if (is_array($bulkFilter['tags'] ?? null) && count($bulkFilter['tags']) > 0) {
            $filter_tag_ids = [];
            foreach ($bulkFilter['tags'] as $filter_tag_id) {
                if (is_numeric($filter_tag_id)) {
                    $filter_tag_ids[] = (int) $filter_tag_id;
                }
            }

            $filter_tag_mode = is_string($bulkFilter['tag_mode'] ?? null) ? $bulkFilter['tag_mode'] : 'AND';

            $tagConn = DbConnection::build();
            $filter_sets[] = new TagService(new TagRepository($tagConn), new PermissionService(new PermissionRepository($tagConn), new GroupRepository($tagConn)), new \Piwigo\Activity\ActivityService(new \Piwigo\Activity\ActivityRepository(\Piwigo\Db\DbConnection::build())))
                ->getImageIdsForTags(
                    $filter_tag_ids,
                    $filter_tag_mode,
                    null,
                    null,
                    false // we don't apply permissions in administration screens
                );
        }

        if (isset($bulkFilter['dimension']) && is_array($bulkFilter['dimension'])) {
            // $bulkFilter is only known as array<string, mixed>, so a nested array
            // offset only narrows to array<mixed, mixed> after is_array() --
            // rebuild with only string keys so dimensionPhotoIds()'s declared
            // array<string, mixed> parameter type-checks against a real, verified
            // shape rather than a trust-me cast.
            $filter_dimension = [];
            foreach ($bulkFilter['dimension'] as $dimension_key => $dimension_value) {
                if (is_string($dimension_key)) {
                    $filter_dimension[$dimension_key] = $dimension_value;
                }
            }
            $dimension_ids = $filterResolver->dimensionPhotoIds($filter_dimension, $confOrderBy);
            if ($dimension_ids !== null) {
                $filter_sets[] = $dimension_ids;
            }
        }

        if (isset($bulkFilter['filesize']) && is_array($bulkFilter['filesize'])) {
            $filter_filesize = [];
            foreach ($bulkFilter['filesize'] as $filesize_key => $filesize_value) {
                if (is_string($filesize_key)) {
                    $filter_filesize[$filesize_key] = $filesize_value;
                }
            }
            $filesize_ids = $filterResolver->filesizePhotoIds($filter_filesize, $confOrderBy);
            if ($filesize_ids !== null) {
                $filter_sets[] = $filesize_ids;
            }
        }

        if (isset($bulkFilter['search']) && is_array($bulkFilter['search'])
            && isset($bulkFilter['search']['q']) && is_string($bulkFilter['search']['q'])
            && (bool) strlen($bulkFilter['search']['q'])) {
            $searchConn = DbConnection::build();
            $res = new SearchService(
                new SearchRepository($searchConn),
                new PermissionService(new PermissionRepository($searchConn), new GroupRepository($searchConn)),
                new PersistentFileCache(),
                new MailService(),
            )->getQuickSearchResultsNoCache($bulkFilter['search']['q'], [
                'permissions' => false,
            ]);
            $res_debug = is_array($res['debug'] ?? null) ? array_filter($res['debug'], is_string(...)) : [];
            unset($res['debug']);
            $template->append('footer_elements', implode("\n", $res_debug));
            $res_items = is_array($res['items'] ?? null) ? $res['items'] : [];
            if (count($res_items) > 0 && is_array($res['qs']) && is_array($res['qs']['unmatched_terms'] ?? null) && count($res['qs']['unmatched_terms']) > 0) {
                $unmatched_terms = array_filter($res['qs']['unmatched_terms'], is_string(...));
                $template->assign('no_search_results', array_map(htmlspecialchars(...), $unmatched_terms));
            }
            $filter_sets[] = $res_items;
        }

        $filter_sets = trigger_change('batch_manager_perform_filters', $filter_sets, $bulkFilter);
        if (! is_array($filter_sets)) {
            // Plugin handlers must return the (possibly extended) list of id sets;
            // fall back to an empty set of filters rather than propagating a
            // non-array value into the array-only code below.
            $filter_sets = [];
        }

        $current_set = array_shift($filter_sets);
        // filter sets are always image id lists (either this method's own search
        // results or a plugin-returned replacement set), so only scalar elements
        // are ever meaningful here -- array_intersect() also requires
        // string-castable values.
        $current_set = is_array($current_set) ? array_filter($current_set, is_scalar(...)) : [];
        foreach ($filter_sets as $set) {
            if (is_array($set)) {
                $current_set = array_intersect($current_set, array_filter($set, is_scalar(...)));
            }
        }

        return $current_set;
    }

    /**
     * @param array<string, mixed> $bulkFilter
     *
     * @return array<string, mixed>
     */
    private function computeDimensionOptions(array $bulkFilter): array
    {
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
        $result = \Piwigo\Db\MysqliDb::query($query);

        if ((bool) \Piwigo\Db\MysqliDb::numRows($result)) {
            while ((bool) ($row = \Piwigo\Db\MysqliDb::fetchAssoc($result))) {
                if (is_numeric($row['width']) && is_numeric($row['height']) && $row['width'] > 0 && $row['height'] > 0) {
                    $widths[] = $row['width'];
                    $heights[] = $row['height'];
                    $ratios[] = floor((float) $row['width'] / (float) $row['height'] * 100) / 100;
                }
            }
        }
        if ($widths === []) { // arbitrary values, only used when no photos on the gallery
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
        $selected_dimension = isset($bulkFilter['dimension']) && is_array($bulkFilter['dimension']) ? $bulkFilter['dimension'] : [];
        foreach (array_keys($dimensions['bounds']) as $type) {
            $dimensions['selected'][$type] = $selected_dimension[$type]
              ?? $dimensions['bounds'][$type]
            ;
        }

        return $dimensions;
    }

    /**
     * @param array<string, mixed> $bulkFilter
     *
     * @return array<string, mixed>
     */
    private function computeFilesizeOptions(array $bulkFilter): array
    {
        $filesizes = [];
        $filesize = [];

        $query = '
SELECT
  filesize
  FROM ' . Tables::images() . '
  WHERE filesize IS NOT NULL
  GROUP BY filesize
;';
        $result = \Piwigo\Db\MysqliDb::query($query);

        while ((bool) ($row = \Piwigo\Db\MysqliDb::fetchAssoc($result))) {
            if (is_numeric($row['filesize'])) {
                $filesizes[] = sprintf('%.1f', (float) $row['filesize'] / 1024);
            }
        }

        if ($filesizes === []) { // arbitrary values, only used when no photos on the gallery
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
        $selected_filesize = isset($bulkFilter['filesize']) && is_array($bulkFilter['filesize']) ? $bulkFilter['filesize'] : [];
        foreach (array_keys($filesize['bounds']) as $type) {
            $filesize['selected'][$type] = $selected_filesize[$type]
              ?? $filesize['bounds'][$type]
            ;
        }

        return $filesize;
    }
}
