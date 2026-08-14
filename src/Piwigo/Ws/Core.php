<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Ws;

use Doctrine\ORM\EntityManagerInterface;
use Piwigo\Activity\ActivityListCriteria;
use Piwigo\Activity\ActivityService;
use Piwigo\Auth\AccessControl;
use Piwigo\Auth\AuthService;
use Piwigo\Auth\CookieService;
use Piwigo\Caddie\CaddieEntity;
use Piwigo\Category\CategoryService;
use Piwigo\Comment\CommentService;
use Piwigo\Common\ValueObject\ImageId;
use Piwigo\Common\ValueObject\SqlDateTime;
use Piwigo\Common\ValueObject\UserId;
use Piwigo\Config\ConfigService;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\ApiKeyRequestFlag;
use Piwigo\Core\AppInfo;
use Piwigo\Core\DateHelper;
use Piwigo\Core\Env;
use Piwigo\Core\FilesystemHelper;
use Piwigo\Core\HtmlRenderingInterface;
use Piwigo\Core\Lang;
use Piwigo\Core\Paths;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Core\WsError;
use Piwigo\Csrf\CsrfService;
use Piwigo\Event\Picture\RenderElementDescription;
use Piwigo\Event\Tag\RenderTagName;
use Piwigo\Event\Ws\GetHistory;
use Piwigo\Group\GroupService;
use Piwigo\History\HistoryEntity;
use Piwigo\History\HistoryImageType;
use Piwigo\History\HistoryService;
use Piwigo\Image\DerivativeImage;
use Piwigo\Image\DerivativeUrlCodec;
use Piwigo\Image\ImageRepository;
use Piwigo\Image\ImageService;
use Piwigo\Image\ImageStdParams;
use Piwigo\Image\MissingDerivativesCriteria;
use Piwigo\Image\SrcImage;
use Piwigo\Lang\Translator;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Rate\RateService;
use Piwigo\Search\Projection\Search;
use Piwigo\Search\SearchRepository;
use Piwigo\Tag\TagService;
use Piwigo\Users\CurrentUser;
use Piwigo\Users\UserRepository;
use Piwigo\Users\UserService;
use Piwigo\Validation\InputValidator;
use Piwigo\Ws\Request\HistorySearchPageRequest;

/**
 * Top-level `pwg.*` WS methods (getVersion, getInfos, getCacheSize,
 * getMissingDerivatives, caddie.add, rates.delete, session.*,
 * getActivityList, history.log/search -- 12 registrations) -- registered
 * via callable arrays in src/Piwigo/Ws/WsDefaultMethods.php. historyGet()
 * is the 'get_history' event handler (registered via first-class-callable,
 * not an addMethod() WS registration).
 *
 * `$params`/`$param` throughout this class (every `mixed[]`/`array<string,
 * mixed>`-typed WS method parameter) is raw, unvalidated WS-protocol
 * request data; every real read narrows defensively at its own use site.
 */
final readonly class Core
{
    public function __construct(
        private AuthService $authService,
        private HistoryService $historyService,
        private ImageService $imageService,
        private CategoryService $categoryService,
        private TagService $tagService,
        private GroupService $groupService,
        private UserService $userService,
        private CommentService $commentService,
        private ActivityService $activityService,
        private RateService $rateService,
        private CurrentConfig $currentConfig,
        private AccessControl $accessControl,
        private CurrentUser $currentUser,
        private EventDispatcher $eventDispatcher,
        private HtmlRenderingInterface $htmlRenderer,
        private UrlServiceInterface $urlService,
        private EntityManagerInterface $entityManager,
        private ApiKeyRequestFlag $apiKeyRequestFlag,
        private ImageRepository $imageRepository,
        private Paths $paths,
        private Lang $lang,
        private InputValidator $inputValidator,
        private Translator $translator,
        private ConfigService $configService,
        private ImageStdParams $imageStdParams,
        private WsHelper $wsHelper,
    ) {}

    /**
     * API method
     * Returns a list of missing derivatives (not generated yet)
     * @param array{types: array<int, string>, ids: array<int, int>, max_urls: int, prev_page: int|null, f_min_rate: float|null, f_max_rate: float|null, f_min_hit: int|null, f_max_hit: int|null, f_min_ratio: float|null, f_max_ratio: float|null, f_max_level: int|null, f_min_date_available: string|null, f_max_date_available: string|null, f_min_date_created: string|null, f_max_date_created: string|null, ...} $params
     *    types/ids: WsParamFlag::FORCE_ARRAY, null default -- never null
     *    (makeArrayParam() converts to []). max_urls: WsParamType::INT|POSITIVE,
     *    default 200 (non-null) -- always int. prev_page: WsParamType::INT|
     *    POSITIVE, null default -- int|null. f_* (see
     *    WsHelper::stdImageSqlFilterCriteria()'s docblock): shared filter set merged in
     *    via ws.php's $f_params.
     * @return WsErrorResponse|array{next_page?: int|string, urls?: string[]}
     */
    public function getMissingDerivatives(array $params, Server &$service): WsErrorResponse|array
    {
        if ($params['types'] === []) {
            $types = array_keys($this->imageStdParams->getDefinedTypeMap());
        } else {
            $types = array_intersect(array_keys($this->imageStdParams->getDefinedTypeMap()), $params['types']);
            if (count($types) === 0) {
                return new WsErrorResponse(WsError::INVALID_PARAM, 'Invalid types');
            }
        }

        $max_urls = $params['max_urls'];
        $next_id_and_count = $this->imageService->getNextIdAndCount();
        $max_id = $next_id_and_count->nextId;
        $image_count = $next_id_and_count->count;

        if ($image_count === 0) {
            return [];
        }

        $start_id = $params['prev_page'];
        if ($start_id <= 0) {
            $start_id = $max_id;
        }

        $uid = '&b=' . time();

        $this->currentConfig->questionMarkInUrls = true;
        $this->currentConfig->phpExtensionInUrls = true;
        $this->currentConfig->derivativeUrlStyle = 2; // script

        $qlimit = (int) min(5000, ceil(max($image_count / 500, $max_urls / count($types))));
        $criteria = new MissingDerivativesCriteria(
            filterCriteria: $this->wsHelper->stdImageSqlFilterCriteria($params, $service),
            ids: array_values($params['ids']),
        );

        $urls = [];
        do {
            $rows = $this->imageService->getForMissingDerivatives($criteria, $start_id, $qlimit);
            $is_last = count($rows) < $qlimit;

            foreach ($rows as $image_row) {
                $start_id = $image_row->id;
                $src_image = new SrcImage($image_row->toArray());
                if ($src_image->isMimetype()) {
                    continue;
                }

                foreach ($types as $type) {
                    $derivative = new DerivativeImage($type, $src_image, $this->currentConfig);
                    if ($type !== $derivative->getType()) {
                        continue;
                    }
                    if (@filemtime($derivative->getPath()) === false) {
                        $urls[] = $derivative->getUrl() . $uid;
                    }
                }

                if (count($urls) >= $max_urls and ! $is_last) {
                    break;
                }
            }
            if ($is_last) {
                $start_id = 0;
            }
        } while (count($urls) < $max_urls and (bool) $start_id);

        $ret = [];
        if ((bool) $start_id) {
            $ret['next_page'] = $start_id;
        }
        $ret['urls'] = $urls;
        return $ret;
    }

    /**
     * API method
     * Returns Piwigo version
     * @param mixed[] $params
     */
    public function getVersion(array $params, Server &$service): string
    {
        return AppInfo::VERSION;
    }

    /**
     * API method
     * Returns general informations about the installation
     * @param mixed[] $params
     * @return array{infos: NamedArray}
     */
    public function getInfos(array $params, Server &$service): array
    {
        $infos = [];
        $infos['version'] = AppInfo::VERSION;

        $infos['nb_elements'] = $this->imageService->getTotalImageCount();
        $infos['nb_categories'] = $this->categoryService->countAllCategories();
        $infos['nb_virtual'] = $this->categoryService->countByDirNull(true);
        $infos['nb_physical'] = $this->categoryService->countByDirNull(false);
        $infos['nb_image_category'] = $this->imageService->getImageCategoryLinkCount();
        $infos['nb_tags'] = $this->tagService->countAll();
        $infos['nb_image_tag'] = $this->tagService->countAllImageTagLinks();
        $infos['nb_users'] = $this->userService->getTotalUserCount();
        $infos['nb_groups'] = $this->groupService->countAll();
        $infos['nb_comments'] = $this->commentService->countAll();

        // first element
        if ($infos['nb_elements'] > 0) {
            $infos['first_date'] = $this->imageService->getMinDateAvailable();
        }

        // unvalidated comments
        if ($infos['nb_comments'] > 0) {
            $infos['nb_unvalidated_comments'] = $this->commentService->countUnvalidated();
        }

        // Cache size: not computed here on purpose. A real answer means
        // shelling out to `du` (see getCacheSize() below, the real
        // pwg.getCacheSize API method) -- too expensive to pay on every
        // pwg.getInfos call, and exec() isn't guaranteed to be enabled.
        // null (not a fake number) matches getCacheSize()'s own sentinel
        // for "couldn't determine size"; callers that need the real value
        // should call pwg.getCacheSize directly.
        $infos['cache_size'] = null;

        $output = [];
        foreach ($infos as $name => $value) {
            $output[] = [
                'name' => $name,
                'value' => $value,
            ];
        }
        return [
            'infos' => new NamedArray($output, 'item'),
        ];
    }

    /**
     * API method
     * Calculates and returns the size of the cache
     *
     * @since 12
     * @param mixed[] $params
     * @return array{infos: NamedArray}
     */
    public function getCacheSize(array $params, Server &$service): array
    {
        $data_location = $this->currentConfig->dataLocation;
        // $data_location ('_data/') is a path relative to the install root,
        // not to the PHP process's CWD -- request-time CWD is public/ (the
        // webroot), not the install root. Compose it against
        // $this->paths->root, like every other call site of dataLocation()
        // in this codebase (PersistentFileCache, FeedController,
        // RequestBootstrap, Template, IntroSubController, MailService,
        // CoreUpdateService), per Paths' own class-level contract
        // ("Config-driven directories ... compose against data/root at the
        // call site").
        $root = $this->paths->root;

        // Cache size
        $path_cache = $root . $data_location;
        $infos = [];
        $infos['cache_size'] = null;
        if (function_exists('exec')) {
            $return_array_cache = [];
            @exec('du -sk ' . $path_cache, $return_array_cache);
            if (
                isset($return_array_cache[0]) && $return_array_cache[0] !== '' && $return_array_cache[0] !== '0'
                and (bool) preg_match('/^(\d+)\s/', $return_array_cache[0], $matches_cache)
            ) {
                $infos['cache_size'] = (int) $matches_cache[1] * 1024;
            }
        }

        // Multiples sizes size
        $path_msizes = $root . $data_location . 'i';
        $msizes = FilesystemHelper::getCacheSizeDerivatives($path_msizes);

        $infos['msizes'] = array_fill_keys(array_keys($this->imageStdParams->getDefinedTypeMap()), 0);
        $infos['msizes']['custom'] = 0;
        $all = 0;

        foreach (array_keys($infos['msizes']) as $size_type) {
            $current_size = $infos['msizes'][$size_type];

            // getCacheSizeDerivatives()'s array<string, int> return type
            // doesn't capture that it's a sparse map -- it only contains keys
            // for derivative sizes that actually have files on disk (see its
            // real implementation, admin/include/functions.php), so a given
            // $size_type is genuinely, verifiably absent at runtime when no
            // such file exists. treatPhpDocTypesAsCertain makes PHPStan
            // (wrongly) prove this offset always exists and is always int;
            // @ suppresses the resulting real undefined-key warning, and the
            // two guards below are the actual runtime safety net, not dead
            // code. Tried telling PHPStan the real int|null result via an
            // explicit @var instead of ignoring -- rejected by PHPStan's own
            // varTag.type check (a @var can only narrow the type it already
            // infers, never widen it beyond what treatPhpDocTypesAsCertain
            // already committed to), confirming this can't be told, only
            // suppressed.
            $added_size = @$msizes[DerivativeUrlCodec::derivativeToUrl($size_type)];
            // @phpstan-ignore function.alreadyNarrowedType
            $added_size = is_int($added_size) ? $added_size : 0;

            $infos['msizes'][$size_type] = $current_size + $added_size;
            $all += $infos['msizes'][$size_type];
        }
        $infos['msizes']['all'] = $all;

        // Compiled templates size
        $path_template_c = $root . $data_location . 'templates_c';
        $infos['tsizes'] = null;
        if (function_exists('exec')) {
            $return_array_template_c = [];
            @exec('du -sk ' . $path_template_c, $return_array_template_c);
            if (
                isset($return_array_template_c[0]) && $return_array_template_c[0] !== '' && $return_array_template_c[0] !== '0'
                and (bool) preg_match('/^(\d+)\s/', $return_array_template_c[0], $matches_template_c)
            ) {
                $infos['tsizes'] = (int) $matches_template_c[1] * 1024;
            }
        }

        $infos['last_date_calc'] = date('Y-m-d H:i:s');

        // $output matches NamedArray::$content's own by-design generic
        // array<int, mixed> contract (a name/value pair list encoded
        // generically for XML/REST) -- $infos itself is genuinely
        // heterogeneous (int/array/string/null per key).
        /** @var array<int, mixed> $output */
        $output = [];
        foreach ($infos as $name => $value) {
            $output[] = [
                'name' => $name,
                'value' => $value,
            ];
        }

        $this->configService->confUpdateParam('cache_sizes', $output, true);

        return [
            'infos' => new NamedArray($output, 'item'),
        ];
    }

    /**
     * API method
     * Adds images to the caddie
     * @param array{image_id: array<int, int>, ...} $params image_id:
     *    WsParamFlag::FORCE_ARRAY|WsParamType::ID, mandatory (no 'default') -- always
     *    a list of positive ints.
     */
    public function caddieAdd(array $params, Server &$service): int
    {
        $user_id = $this->currentUser->get()
            ->id->value;

        return $this->entityManager->getRepository(CaddieEntity::class)
            ->addElements($user_id, $params['image_id']);
    }

    /**
     * API method
     * Deletes rates of an user
     * @param array{user_id: int, anonymous_id: string|null, image_id?: int, ...} $params
     *    user_id: WsParamType::ID, mandatory -- always int. anonymous_id: no
     *    WS_TYPE flag, null default -- string|null. image_id:
     *    WsParamFlag::OPTIONAL with no 'default' -- may be entirely absent.
     */
    public function ratesDelete(array $params, Server &$service): int
    {
        $anonymous_id = (! in_array($params['anonymous_id'], [null, ''], true)) ? $params['anonymous_id'] : null;
        $image_id = (isset($params['image_id']) and $params['image_id'] !== 0) ? $params['image_id'] : null;

        // Real bug fix, not just a MysqliDb retarget: the original (both here
        // and in the pre-rewrite legacy include/ws_functions/pwg.php) built
        // its own raw query and then NEVER executed it -- MysqliDb::changes()
        // just reads $mysqli->affected_rows from whatever OTHER query last
        // ran on the connection, completely disconnected from this DELETE.
        // executeStatement() both runs the query for real and returns its
        // actual affected-row count.
        $changes = $this->rateService->deleteByOptionalConditions(UserId::from($params['user_id']), $anonymous_id, $image_id === null ? null : ImageId::from($image_id));
        $this->entityManager->clear();
        if ($changes > 0) {
            $this->rateService->updateRatingScore();
        }
        return $changes;
    }

    /**
     * API method
     * Performs a login
     * @param array{username: string, password: string|null, ...} $params
     *    username: no WS_TYPE flag, mandatory -- always a plain string.
     *    password: no WS_TYPE flag, null default -- string|null.
     */
    public function sessionLogin(array $params, Server &$service): WsErrorResponse|true
    {
        if ($this->apiKeyRequestFlag->isActive()) {
            return new WsErrorResponse(401, 'Cannot use this method with an api key');
        }

        if ((bool) preg_match('/^pkid-\d{8}-[a-z0-9]{20}$/i', $params['username'])) {
            // The combined "username:password" string must match
            // authKeyLogin()'s own strict [a-z0-9]-only regex to be
            // considered valid at all, so it never needs SQL escaping.
            $authenticate = $this->authService->authKeyLogin($params['username'] . ':' . $params['password']);
            if ($authenticate) {
                $_SESSION['connected_with'] = 'ws_session_login_api_key';
                return true;
            }
        } elseif ($this->authService->tryLogUser($params['username'], $params['password'], false)) {
            $_SESSION['connected_with'] = 'ws_session_login';
            return true;
        }
        return new WsErrorResponse(999, 'Invalid username/password');
    }

    /**
     * API method
     * Performs a logout
     * @param mixed[] $params
     */
    public function sessionLogout(array $params, Server &$service): WsErrorResponse|true
    {
        if ($this->apiKeyRequestFlag->isActive()) {
            return new WsErrorResponse(401, 'Cannot use this method with an api key');
        }

        if (! $this->accessControl->isAGuest()) {
            $this->authService->logoutUser();
        }
        return true;
    }

    /**
     * API method
     * Returns info about the current user
     * @param mixed[] $params
     * @return array<string, mixed>
     */
    public function sessionGetStatus(array $params, Server &$service): array
    {

        $currentUser = $this->currentUser->get();
        $res = [];
        $res['username'] = $this->accessControl->isAGuest() ? 'guest' : stripslashes($currentUser->username->value ?? '');
        $res['status'] = $currentUser->status->value;
        $res['theme'] = $currentUser->theme->value;
        $res['language'] = $currentUser->language->value;
        $res['pwg_token'] = new CsrfService($this->currentConfig)->getToken();
        $res['charset'] = 'utf-8';

        // Env::now() (not SQL's NOW()) so this value can be frozen by
        // PIWIGO_TEST_NOW in tests -- SQL's NOW() reads the real,
        // unfreezable DB-server clock.
        $res['current_datetime'] = Env::now()->format('Y-m-d H:i:s');
        $res['version'] = AppInfo::VERSION;
        $res['save_visits'] = $this->historyService->isLoggingAllowed();
        $res['connected_with'] = $_SESSION['connected_with'] ?? null;

        // Piwigo Remote Sync does not support receiving the new (version 14) output "save_visits"
        $http_user_agent = $_SERVER['HTTP_USER_AGENT'] ?? null;
        if (is_string($http_user_agent) and (bool) preg_match('/^PiwigoRemoteSync/', $http_user_agent)) {
            unset($res['save_visits']);
            unset($res['connected_with']);
        }

        // Piwigo Remote Sync does not support receiving the available sizes
        $piwigo_remote_sync_agent = 'Apache-HttpClient/';
        if (! is_string($http_user_agent) or ! str_starts_with($http_user_agent, $piwigo_remote_sync_agent)) {
            $res['available_sizes'] = array_keys($this->imageStdParams->getDefinedTypeMap());
        }

        if ($this->accessControl->isAdmin()) {
            $upload_ext_list = ($this->currentConfig->uploadFormAllTypes) ? $this->currentConfig->fileExtensions : $this->currentConfig->pictureExtensions;

            $res['upload_file_types'] = implode(
                ',',
                array_unique(
                    array_map(
                        strtolower(...),
                        $upload_ext_list
                    )
                )
            );

            $chunk_size = $this->currentConfig->uploadFormChunkSize;
            $res['upload_form_chunk_size'] = $chunk_size;
        }

        return $res;
    }

    /**
     * API method
     * Returns lines of users activity
     *  @since 12
     * @param array{page: int|null, offset: int, uid: int|string|null, date_min: string|null, date_max: string|null, id: int|string|null, object: string|null, action: string|null, ...} $param
     *    page: WsParamType::INT|POSITIVE, null default -> int|null (never
     *    sent as '' by this method's own JS caller, unlike uid/id below).
     *    uid/id: WsParamType::ID, null default -- Server::checkType()
     *    deliberately skips type coercion for an empty-string value on an
     *    OPTIONAL param (matches legacy ws_core.inc.php's own
     *    PwgServer::checkType() byte-for-byte), so a present-but-empty
     *    'uid'/'id' arrives here as the raw string '', not int|null; a
     *    genuinely-provided value is coerced to int by that same
     *    checkType() call. offset: WsParamType::INT|POSITIVE, default 0
     *    (non-null) -> always int. date_min/date_max/object/action: no
     *    WS_TYPE flag, null default -> string|null.
     * result_lines' rows are genuinely heterogeneous (activity.details is
     * an entity-agnostic per-action payload, same rationale as
     * Admin\Maintenance\ActivityLogEntryFormatter's own $details); 'params'
     * echoes $param back for the WS client, same by-design shape.
     * @return WsErrorResponse|array{result_lines: array<int, array<string, mixed>>, page_offset: int, end_page: bool, params: array<string, mixed>}
     */
    public function getActivityList(array $param, Server &$service): WsErrorResponse|array
    {
        foreach (['date_min', 'date_max'] as $datefield) {
            $datefield_value = $param[$datefield];
            if (! in_array($datefield_value, [null, ''], true) and ! DateHelper::isValidMysqlDatetime($datefield_value)) {
                return new WsErrorResponse(WsError::INVALID_PARAM, 'Invalid ' . $datefield);
            }
        }

        $output_lines = [];
        $current_key = '';
        $page_size = 100; // We will fetch X lines in database =/= lines displayed due to line concatenation
        // $page_offset = $param['page']*$page_size;
        $page_offset = $param['offset'];
        $nb_rows_to_fetch = 10000;

        $user_ids = [];

        $line_id = 0;

        // $min/$max are only read below when the same date_min/date_max
        // condition that sets them here is true again.
        $min = null;
        $max = null;
        $date_min_raw = $param['date_min'];
        $date_max_raw = $param['date_max'];
        if (! in_array($date_min_raw, [null, ''], true)) {
            // is_valid_sql_datetime() above already validated date_min; a
            // valid Y-m-d[ H:i:s] string always parses
            $min_date = date_create($date_min_raw);
            assert($min_date !== false);
            $min = date_format($min_date, 'Y-m-d H:i:s');

            // date_max may be empty/unvalidated/absent here — date_create()
            // only returns false on a genuinely malformed string, never on ''
            // (which means "now"), so a missing date_max is coalesced to ''
            $max_date = date_create($date_max_raw ?? '');
            assert($max_date !== false);
            $max = date_format($max_date, 'Y-m-d 23:59:59');
        }

        if (! in_array($date_max_raw, [null, ''], true)) {
            $max_date = date_create($date_max_raw);
            assert($max_date !== false);
            $max = date_format($max_date, 'Y-m-d 23:59:59');
        }

        // uid/id/admin-ids/action/object/date_min/date_max collapse into
        // an ActivityListCriteria, translated into bound conditions inside
        // ActivityRepository itself (see that class's own findPaginated()
        // docblock).
        $connections_mode = $this->currentConfig->activityDisplayConnections;
        $admin_ids = [];
        if ($connections_mode === 'admins_only') {
            $admin_ids = new UserRepository($this->entityManager, $this->eventDispatcher, $this->currentConfig)
                ->findAdminIds();
        }

        $criteria = new ActivityListCriteria(
            // uid/id are WsParamType::ID (optional, null default) --
            // Server::checkType() deliberately skips type coercion for an
            // empty-string value on an OPTIONAL param (matches legacy
            // ws_core.inc.php's own PwgServer::checkType() byte-for-byte),
            // so a present-but-empty 'uid'/'id' arrives here as the raw
            // string '', not null/int. UserId::from()/ActivityListCriteria's
            // own $objectId are both strictly typed (no string), so is_int()
            // -- not a null/'' exclusion list -- both narrows for PHPStan and
            // excludes that empty string, same is_string() shape as
            // action/object below.
            performedBy: is_int($param['uid']) ? UserId::from($param['uid']) : null,
            action: is_string($param['action']) ? $param['action'] : null,
            object: is_string($param['object']) ? $param['object'] : null,
            minDate: ! in_array($date_min_raw, [null, ''], true) ? SqlDateTime::from($min) : null,
            maxDate: ! in_array($date_max_raw, [null, ''], true) ? SqlDateTime::from($max) : null,
            objectId: (is_int($param['id']) and $param['id'] !== 0) ? $param['id'] : null,
            connectionsMode: $connections_mode,
            adminIds: $admin_ids,
        );

        $more_rows_available = true;

        while (count($output_lines) < $page_size and $more_rows_available) {
            $rows = $this->activityService->getPaginated($criteria, $nb_rows_to_fetch, $page_offset);

            if (count($rows) < $nb_rows_to_fetch) {
                $more_rows_available = false;
            }

            foreach ($rows as $row) {
                if (count($output_lines) < $page_size) {
                    $page_offset++;

                    // ActivityRepository::findPaginated() now returns
                    // PaginatedActivityRow -- every field below reads its
                    // typed property directly, no per-field narrowing
                    // needed (the DTO already guarantees each real type).
                    $row_session_idx = $row->sessionIdx;
                    $row_object = $row->object;
                    $row_action = $row->action;
                    $row_object_id = (string) $row->objectId;
                    $row_ip_address = $row->ipAddress?->value;
                    $row_performed_by = $row->performedBy !== null ? (string) $row->performedBy->value : null;
                    $row_details = $row->details ?? [];
                    $row_occured_on = $row->occuredOn->value;

                    $line_key = $row_session_idx . '~' . $row_object . '~' . $row_action . '~'; // idx~photo~add

                    if ($line_key === $current_key) {
                        // I increment the counter of the previous line
                        $last_idx = count($output_lines) - 1;
                        $prev_counter = $output_lines[$last_idx]['counter'];
                        $output_lines[$last_idx]['counter'] = $prev_counter + 1;

                        // $output_lines elements are always built with the
                        // full literal shape below (id/object/object_id/...)
                        // -- PHPStan loses that precision once the array is
                        // both grown (`$output_lines[] = [...]`) and mutated
                        // by dynamic index in the same loop, widening every
                        // field to optional. Verified safe: $last_idx only
                        // reaches here after at least one element exists.
                        // An explicit @var on the assignment doesn't help
                        // here either -- unlike the msizes case above, this
                        // diagnostic flags the offset access itself, not the
                        // assigned variable's type, so a @var on
                        // has nothing to override.
                        // @phpstan-ignore offsetAccess.notFound
                        $prev_object_ids = $output_lines[$last_idx]['object_id'];
                        $prev_object_ids[] = $row_object_id;
                        $output_lines[$last_idx]['object_id'] = $prev_object_ids;
                    } else {
                        $details = $row_details;
                        $detailsType = null;

                        if ($row->userAgent !== null) {
                            $details['agent'] = $row->userAgent;
                        }

                        if (isset($details['method'])) {
                            $detailsType = 'method';
                        }

                        if (isset($details['script'])) {
                            $detailsType = 'script';
                        }

                        [$date, $hour] = explode(' ', $row_occured_on);
                        // New line
                        $output_lines[] = [
                            'id' => $line_id,
                            'object' => $row_object,
                            'object_id' => [$row_object_id],
                            'action' => $row_action,
                            'ip_address' => $row_ip_address,
                            'date' => DateHelper::formatDate($date),
                            'hour' => $hour,
                            'user_id' => $row_performed_by,
                            'detailsType' => $detailsType,
                            'details' => $details,
                            'counter' => 1,
                        ];

                        if ($row_performed_by !== null) {
                            $user_ids[$row_performed_by] = 1;
                        }
                        if ($row_object === 'user') {
                            $user_ids[$row_object_id] = 1;
                        }

                        $current_key = $line_key;
                        $line_id++;
                    }
                } else {
                    $more_rows_available = true;
                    break;
                }
            }
        }

        $username_of = [];
        $user_id_list = [];
        if (count($user_ids) > 0) {
            $username_of = $this->userService->getUsernamesByIds(array_map(strval(...), array_keys($user_ids)));
        }

        foreach ($output_lines as $idx => $output_line) {
            if (($output_line['object'] ?? null) === 'user') {
                foreach ($output_line['object_id'] as $user_id) {
                    // Inside this loop, PHPStan can't prove 'details' still
                    // exists on every iteration (the loop itself
                    // conditionally rewrites $output_lines[$idx]['details']
                    // below) -- the ?? [] fallback is genuinely needed here,
                    // unlike the read just after this loop.
                    $details = $output_lines[$idx]['details'] ?? [];

                    $users = $details['users'] ?? [];
                    if (! is_array($users)) {
                        $users = [];
                    }

                    $users[] = $username_of[$user_id] ?? 'user#' . $user_id;
                    $details['users'] = $users;
                    $output_lines[$idx]['details'] = $details;
                }

                $details = $output_lines[$idx]['details'];
                $details['users_string'] = implode(', ', array_filter($details['users'], is_string(...)));
                $output_lines[$idx]['details'] = $details;
            }

            $user_id_val = $output_lines[$idx]['user_id'] ?? null;
            $user_id_key = is_string($user_id_val) ? $user_id_val : '';
            $output_lines[$idx]['username'] = 'user#' . $user_id_key;
            if (isset($username_of[$user_id_key])) {
                $output_lines[$idx]['username'] = $username_of[$user_id_key];
            }
        }

        return [
            'result_lines' => $output_lines,
            'page_offset' => $page_offset,
            'end_page' => ! $more_rows_available,
            'params' => $param,
        ];
    }

    /**
     * API method
     * Log a new line in visit history
     * @since 13
     * @param array{image_id: int, cat_id: int|null, section: string|null, tags_string: string|null, is_download: bool, ...} $params
     *    image_id: WsParamType::ID, mandatory -- always int. cat_id: WsParamType::ID,
     *    null default -- int|null. section/tags_string: no WS_TYPE flag,
     *    null default -- string|null. is_download: WsParamType::BOOL, default
     *    false (non-null) -- always bool.
     */
    public function historyLog(array $params, Server &$service): void
    {
        $section = null;
        if (! in_array($params['section'], [null, ''], true)) {
            $historyRepository = $this->entityManager
                ->getRepository(HistoryEntity::class);
            if (in_array($params['section'], $historyRepository->getSectionEnumOptions(), true)) {
                $section = $params['section'];
            }
        }

        $category = null;
        if ($params['cat_id'] !== null and $params['cat_id'] !== 0) {
            $category = [
                'id' => $params['cat_id'],
            ];
        }

        $tagIds = null;
        if (! in_array($params['tags_string'], [null, ''], true) and (bool) preg_match('/^\d+(,\d+)*$/', $params['tags_string'])) {
            $tagIds = array_map(intval(...), explode(',', $params['tags_string']));
        }

        // when visiting a photo (which is currently, in version 14, the only event registered
        // by pwg.history.log) we should also increment images.hit
        $historyImageId = ImageId::tryFrom($params['image_id']);
        if ($historyImageId instanceof ImageId) {
            $this->imageRepository->incrementVisitCounter($historyImageId);
        }

        $image_type = 'picture';
        if ($params['is_download']) {
            $image_type = 'high';
        }

        $this->historyService->logVisit(
            $params['image_id'],
            $image_type,
            section: $section,
            category: $category,
            tagIds: $tagIds,
        );
    }

    /**
     * Perform history search. Registered as the default GetHistory event
     * handler (see src/Piwigo/Ws/WsInitializer.php) -- historySearch() (this
     * class's only real caller of that event) dispatches via
     * dispatchChange() rather than calling this directly, so a plugin can
     * still override history search behavior by registering its own
     * GetHistory handler at a higher priority.
     */
    public function historyGet(GetHistory $event): GetHistory
    {
        $event->data = $this->historyService
            ->getHistory($event->data, $event->search, $event->types);

        return $event;
    }

    /**
     * API method
     * Returns lines of an history search
     * @since 13
     * @param array{start: string|null, end: string|null, types: array<int, string>, user_id: int|string, image_id: int|string|null, filename: string|null, ip: string|null, display_thumbnail: string, pageNumber: int|null, ...} $param
     *    start/end/filename/ip: no WS_TYPE flag, null default -- string|null.
     *    types: WsParamFlag::FORCE_ARRAY, non-null array default, no WS_TYPE flag
     *    -- always an array (never coerced element-wise). user_id: no
     *    WS_TYPE flag, non-null int default (-1) -- int if the default is
     *    used, otherwise the raw uncoerced request string. image_id:
     *    WsParamType::ID, null default -- int|null in principle, but
     *    Server::checkType()'s own int/positive coercion deliberately
     *    skips an empty-string param (`elseif ($param !== '')`), so the
     *    real, uncoerced string '' also reaches here whenever a caller
     *    sends the key with no value (a real browser client, unlike a WS
     *    caller that just omits the key entirely).
     *    display_thumbnail: no WS_TYPE flag, non-null string default --
     *    always string. pageNumber: WsParamType::INT|POSITIVE, null
     *    default -- int|null.
     * @return array<string, mixed>
     */
    public function historySearch(array $param, Server &$service): array
    {
        /** @var array<string, mixed> $page */
        $page = [];
        $page['start'] = HistorySearchPageRequest::fromGlobals()->start;

        $types = array_merge(['none'], array_map(
            static fn (HistoryImageType $type): string => $type->value,
            HistoryImageType::cases()
        ));

        $display_thumbnails = [
            'no_display_thumbnail' => $this->lang->t('No display'),
            'display_thumbnail_classic' => $this->lang->t('Classic display'),
            'display_thumbnail_hoverbox' => $this->lang->t('Hoverbox display'),
        ];

        $page['errors'] = [];
        $search = [];
        $search['fields'] = [];

        // date start
        if (! in_array($param['start'], [null, ''], true)) {
            $this->inputValidator
                ->validate('start', $param, false, '/^\d{4}-\d{2}-\d{2}$/');
            $search['fields']['date-after'] = $param['start'];
        }

        // date end
        if (! in_array($param['end'], [null, ''], true)) {
            $this->inputValidator
                ->validate('end', $param, false, '/^\d{4}-\d{2}-\d{2}$/');
            $search['fields']['date-before'] = $param['end'];
        }

        // types
        if ($param['types'] === []) {
            $search['fields']['types'] = $types;
        } else {
            $this->inputValidator
                ->validate('types', $param, true, '/^(' . implode('|', $types) . ')$/');
            $search['fields']['types'] = $param['types'];
        }

        // user
        $search['fields']['user'] = intval($param['user_id']);

        // image -- Server::checkType() deliberately skips its own
        // int/positive coercion for an empty-string param (its own
        // `elseif ($param !== '')` guard), so a real browser client that
        // always sends every key (history.latte's own `image_id: {if
        // isset($IMAGE_ID)}"{$IMAGE_ID}"{else}""{/if}`, unlike a WS caller
        // that just omits the key) reaches this method with the literal
        // string ''. The old `!== 0` check missed that case (only the
        // sibling filename/ip checks below already excluded '' too),
        // so intval('') = 0 got stored into $search['fields']['image_id']
        // and persisted -- HistoryRepository::search() later reads it back
        // as a real, non-null 0 and calls ImageId::from(0), which throws:
        // a genuine, always-triggered 500 on
        // admin.php?page=history's default, unfiltered search, not a test-only
        // issue.
        if (! in_array($param['image_id'], [null, '', 0], true)) {
            $search['fields']['image_id'] = intval($param['image_id']);
        }

        // filename
        // filename/ip are read back later via
        // HistoryRepository::findImageIdsByFilename()/the 'ip' rate-limit
        // lookup, which bind the value as a real DBAL parameter
        // (:pattern/:ip), so neither needs escaping here. The '*' -> '%'
        // wildcard conversion below is unrelated to escaping.
        if (! in_array($param['filename'], [null, ''], true)) {
            $search['fields']['filename'] = str_replace('*', '%', $param['filename']);
        }

        // ip
        if (! in_array($param['ip'], [null, ''], true)) {
            $search['fields']['ip'] = str_replace('*', '%', $param['ip']);
        }

        // thumbnails
        $this->inputValidator
            ->validate('display_thumbnail', $param, false, '/^(' . implode('|', array_keys($display_thumbnails)) . ')$/');

        $search['fields']['display_thumbnail'] = $param['display_thumbnail'];
        // Display choise are also save to one cookie
        if ($param['display_thumbnail'] !== ''
            and isset($display_thumbnails[$param['display_thumbnail']])) {
            $cookie_val = $param['display_thumbnail'];
        } else {
            $cookie_val = null;
        }

        new CookieService()
            ->setCookieVar('display_thumbnail', $cookie_val, strtotime('+1 month'));

        // image_id and filename are set independently above (like every
        // other field in this method) and both end up ANDed together in
        // $search['fields'] -- submitting both simultaneously just
        // narrows the search to their intersection, same as any other
        // multi-field combination here, not a special case to resolve.

        // store seach in database
        // register search rules in database, then they will be available on
        // thumbnails page and picture page.
        $searchRepository = new SearchRepository($this->entityManager);
        $search_id = $searchRepository->insertSavedSearch($search);

        // Remove redirect for ajax //
        // redirect(
        //   PHPWG_ROOT_PATH.'admin.php?page=history&search_id='.$search_id
        //   );

        // what are the lines to display in reality ?
        $storedSearch = $searchRepository->findSavedSearchById($search_id);
        // this row is the one we just INSERTed above (via $search_id =
        // Connection::lastInsertId()) with rules we just encoded ourselves,
        // so it's guaranteed to be found with a non-null, decoded array.
        assert($storedSearch instanceof Search && is_array($storedSearch->rules));

        $page['search'] = $storedSearch->rules;

        // Known limitation: the query behind this fetches more rows than
        // the page actually displays instead of a SQL_CALC_FOUND_ROWS-based
        // LIMIT/OFFSET pagination -- a real, non-trivial optimization
        // opportunity on large history tables, not a defect.
        $historyEvent = $this->eventDispatcher->dispatchChange(new GetHistory([], $page['search'], $types));
        // GetHistory::$data is a non-nullable PHP `array` property --
        // dispatchChange()'s own instanceof check already guarantees a real
        // array here, but PHP has no generic array-shape enforcement, so a
        // misbehaving handler at a higher priority than historyGet() (the
        // default handler) could still populate it with non-row-shaped
        // elements; keep the per-element defensive filter for that.
        /** @var array<int, array<string, mixed>> $data */
        $data = array_values(array_filter($historyEvent->data, is_array(...)));
        $historyService = $this->historyService;
        usort($data, $historyService->historyCompare(...));

        $page['nb_lines'] = count($data);

        // Number of ids of each kind
        $history_lines = [];
        $user_ids = [];
        $username_of = [];
        $category_ids = [];
        $image_ids = [];
        $has_tags = false;
        $search_ids = [];

        // historyGet() (the real 'get_history' handler) builds each $row via
        // HistoryRepository::search()'s DBAL fetchAllAssociative(), so every
        // field here is really mixed -- the is_string()/is_array() guards
        // throughout this loop (and the rest of this method) are the real
        // narrowing, not just defensive boilerplate.
        foreach ($data as $row) {
            // user_id/category_id/image_id/search_id are int/mediumint/
            // smallint columns -- HistoryRepository::search()'s DBAL
            // fetchAllAssociative() returns these as native PHP int, so the
            // guard below accepts both int and string.
            $row_user_id = $row['user_id'] ?? null;
            if (is_int($row_user_id) || is_string($row_user_id)) {
                $user_ids[(string) $row_user_id] = 1;
            }

            if (isset($row['category_id']) and (is_int($row['category_id']) || is_string($row['category_id']))) {
                array_push($category_ids, (string) $row['category_id']);
            }

            if (isset($row['image_id']) and (is_int($row['image_id']) || is_string($row['image_id']))) {
                $image_ids[(string) $row['image_id']] = 1;
            }

            if (isset($row['tag_ids'])) {
                $has_tags = true;
            }

            if (isset($row['search_id']) and (is_int($row['search_id']) || is_string($row['search_id']))) {
                array_push($search_ids, (string) $row['search_id']);
            }

            $history_lines[] = $row;
        }

        // prepare reference data (users, tags, categories...)
        // Declared unconditionally (not just inside the "if" below) so it is
        // always defined by the time it is read later, even when $search_ids
        // is empty.
        $search_details = [];
        if (count($search_ids) > 0) {
            $rules_by_search_id = $searchRepository->findSavedSearchRulesByIds(array_map(intval(...), $search_ids));
            foreach ($rules_by_search_id as $id_search => $rules_full) {
                if ($rules_full === null) {
                    continue;
                }

                $rules_search = isset($rules_full['fields']) && is_array($rules_full['fields'])
                    ? $rules_full['fields']
                    : [];

                $rules_tags = is_array($rules_search['tags'] ?? null) ? $rules_search['tags'] : [];
                if (! in_array($rules_tags['words'] ?? null, [null, false, 0, '0', '', []], true)) {
                    $has_tags = true;
                }

                $rules_cat = is_array($rules_search['cat'] ?? null) ? $rules_search['cat'] : [];
                if (! in_array($rules_cat['words'] ?? null, [null, false, 0, '0', '', []], true) and is_array($rules_cat['words'])) {
                    foreach ($rules_cat['words'] as $cat_id) {
                        if (is_string($cat_id) || is_int($cat_id)) {
                            $category_ids[] = (string) $cat_id;
                        }
                    }
                }

                $rules_added_by = $rules_search['added_by'] ?? null;
                if (! in_array($rules_added_by, [null, false, 0, '0', '', []], true) and is_array($rules_added_by)) {
                    foreach ($rules_added_by as $key) {
                        if (is_string($key) || is_int($key)) {
                            $user_ids[$key] = 1;
                        }
                    }
                }

                $search_details[$id_search] = $rules_search;
            }
        }

        if (count($user_ids) > 0) {
            $username_of = [];
            foreach ($this->userService->getUsernamesByIds(array_map(strval(...), array_keys($user_ids))) as $id => $username) {
                $username_of[(string) $id] = stripslashes($username);
            }
        }

        $name_of_category = [];
        // Declared unconditionally (not just inside the "if" below),
        // matching $name_of_category above: both are read later regardless
        // of whether $category_ids is empty here.
        $full_cat_path = [];
        if (count($category_ids) > 0) {
            $uppercats_of = $this->categoryService->getUppercatsById(array_map(intval(...), $category_ids));

            foreach ($uppercats_of as $category_id => $uppercats) {
                $full_cat_path[$category_id] = $this->htmlRenderer->getCatDisplayNameCache(
                    $uppercats,
                    'admin.php?page=album-'
                );

                $uppercats = explode(',', $uppercats);
                $name_of_category[$category_id] = $this->htmlRenderer->getCatDisplayNameCache(
                    end($uppercats),
                    'admin.php?page=album-'
                );
            }
        }

        $image_infos = [];
        if (count($image_ids) > 0) {
            $image_infos = $this->imageService->getHistoryDisplayInfoByIds(array_keys($image_ids));
        }

        // $name_of_tag is a genuinely local variable (not a real global):
        // written, read (including via the closure below), and unset()
        // all within this one function -- the only place in the codebase
        // that touches it.
        $name_of_tag = [];
        if ($has_tags) {
            foreach ($this->tagService->getAll() as $tag) {
                $tag_row = [
                    'id' => $tag->id->value,
                    'name' => $tag->name,
                    'url_name' => $tag->urlName,
                ];
                $tagRowNameEvent = $this->eventDispatcher->dispatchChange(new RenderTagName($tag->name, $tag_row));
                $name_of_tag[(string) $tag->id->value] = $tagRowNameEvent->tagName;
            }
        }

        $page_start = $page['start'];

        $nb_logs_page = $this->currentConfig->nbLogsPage;

        $i = 0;
        $first_line = $page_start + 1;
        $last_line = $page_start + $nb_logs_page;

        /** @var array<string, mixed> $summary */
        $summary = [];
        $summary['total_filesize'] = 0;
        $summary['guests_IP'] = [];

        $result = [];
        $sorted_members = [];

        foreach ($history_lines as $line) {
            // every field of $line comes straight from historyGet()'s
            // HistoryRepository::search() rows (DBAL fetchAllAssociative()),
            // so it is really mixed -- the is_string()/is_int() narrowing
            // below is the real guard, not just defensive boilerplate.
            // user_id/category_id/image_id/search_id are int columns --
            // DBAL returns native int, so these 4 need the wider int|string
            // check the genuinely-string columns below don't.
            $line_image_type = $line['image_type'] ?? null;
            $line_image_type = is_string($line_image_type) ? $line_image_type : null;
            $line_image_id = $line['image_id'] ?? null;
            $line_image_id = (is_int($line_image_id) || is_string($line_image_id)) ? (string) $line_image_id : null;
            $line_user_id = $line['user_id'] ?? null;
            $line_user_id = (is_int($line_user_id) || is_string($line_user_id)) ? (string) $line_user_id : null;
            $line_ip = $line['IP'] ?? null;
            $line_ip = is_string($line_ip) ? $line_ip : null;
            $line_tag_ids = $line['tag_ids'] ?? null;
            $line_tag_ids = is_string($line_tag_ids) ? $line_tag_ids : null;
            $line_search_id = $line['search_id'] ?? null;
            $line_search_id = (is_int($line_search_id) || is_string($line_search_id)) ? (string) $line_search_id : null;
            $line_date = $line['date'] ?? null;
            $line_date = is_string($line_date) ? $line_date : null;
            $line_time = $line['time'] ?? null;
            $line_time = is_string($line_time) ? $line_time : null;
            $line_section = $line['section'] ?? null;
            $line_section = is_string($line_section) ? $line_section : null;
            $line_category_id = $line['category_id'] ?? null;
            $line_category_id = (is_int($line_category_id) || is_string($line_category_id)) ? (string) $line_category_id : null;

            if ($line_image_type === 'high' and $line_image_id !== null) {
                // 'total_filesize' is only ever set to int by this same loop
                // (initialized to 0 above, then always int + int below).
                $running_total_filesize = $summary['total_filesize'];
                $filesize_row = $image_infos[$line_image_id] ?? null;
                $filesize_value = is_array($filesize_row) ? $filesize_row['filesize'] : null;
                $summary['total_filesize'] = $running_total_filesize + (is_scalar($filesize_value) ? intval($filesize_value) : 0);
            }

            if (is_numeric($line_user_id) and (int) $line_user_id === $this->currentConfig->guestId) {
                $ip_key = $line_ip ?? '';
                // 'guests_IP' is only ever set to array by this same loop
                // (initialized to [] above, then always reassigned as array below).
                $guests_ip = $summary['guests_IP'];
                if (! isset($guests_ip[$ip_key])) {
                    $guests_ip[$ip_key] = 0;
                }

                // always int: either the literal 0 just set above, or a
                // value this same loop already wrote as int + 1.
                $guest_ip_count = $guests_ip[$ip_key];
                $guests_ip[$ip_key] = $guest_ip_count + 1;
                $summary['guests_IP'] = $guests_ip;
            }

            $i++;

            if ($i <= $first_line and $i >= $last_line) {
                continue;
            }

            $user_name = '#unknown';
            $user_string = '';
            $user_id_key = $line_user_id ?? '';
            if (isset($username_of[$user_id_key])) {
                $user_name = $username_of[$user_id_key];
                $user_string .= $username_of[$user_id_key];
            } else {
                $user_string .= $user_id_key;
            }
            $user_string .= '&nbsp;<a href="';
            $user_string .= $this->urlService
                ->getRootUrl() . 'admin.php?page=history';
            $user_string .= '&amp;search_id=' . $search_id;
            $user_string .= '&amp;user_id=' . $user_id_key;
            $user_string .= '">+</a>';

            $tag_names = '';
            $tag_ids = '';
            if ($line_tag_ids !== null) {
                $tag_names = preg_replace_callback(
                    '/(\d+)/',
                    /**
                     * @param array<int, string> $m
                     */
                    function (array $m) use ($name_of_tag): string {
                        $tag_id = $m[1];
                        return $name_of_tag[$tag_id] ?? $tag_id;
                    },
                    $line_tag_ids
                );
                $tag_ids = $line_tag_ids;
            }

            $image_string = '';
            $image_title = '';
            $image_edit_string = '';
            $image_id = '';
            $cat_name = '';
            if ($line_image_id !== null) {
                $image_edit_string = $this->urlService
                    ->getRootUrl() . 'admin.php?page=photo-' . $line_image_id;
                $picture_url = $this->urlService
                    ->makePictureUrl(
                        [
                            'image_id' => $line_image_id,
                        ]
                    );

                $element = [];
                if (isset($image_infos[$line_image_id])) {
                    $element = [
                        'id' => $line_image_id,
                        'file' => $image_infos[$line_image_id]['file'],
                        'path' => $image_infos[$line_image_id]['path'],
                        'representative_ext' => $image_infos[$line_image_id]['representative_ext'],
                    ];
                    $page_search = $page['search'];
                    $page_search_fields = $page_search['fields'] ?? null;
                    $thumbnail_display = is_array($page_search_fields) ? ($page_search_fields['display_thumbnail'] ?? 'no_display_thumbnail') : 'no_display_thumbnail';
                } else {
                    $thumbnail_display = 'no_display_thumbnail';
                }

                $image_title = '';

                if (isset($image_infos[$line_image_id]['label'])) {
                    $label = $image_infos[$line_image_id]['label'];
                    $labelEvent = $this->eventDispatcher->dispatchChange(new RenderElementDescription($label));
                    $image_title .= ' ' . $labelEvent->elementDescription;
                } else {
                    $image_edit_string = '';
                    $image_title .= ' unknown filename';
                }

                $image_string = '';
                $image_id = $line_image_id;

                $image_string =
                '<span><img src="' . @DerivativeImage::url($this->imageStdParams->getByType(ImageStdParams::SQUARE), $element)
                . '" alt="' . $image_title . '" title="' . $image_title . '">';
            }

            if ($line_search_id !== null) {
                $search_detail_fields = $search_details[$line_search_id] ?? null;
                $search_detail_fields = is_array($search_detail_fields) ? $search_detail_fields : [];

                $allwords_words = is_array($search_detail_fields['allwords'] ?? null) ? ($search_detail_fields['allwords']['words'] ?? null) : null;

                $tags_words = is_array($search_detail_fields['tags'] ?? null) ? ($search_detail_fields['tags']['words'] ?? null) : null;
                $tags_words = is_array($tags_words) ? array_values(array_filter($tags_words, is_string(...))) : null;

                $date_posted = $search_detail_fields['date_posted'] ?? null;

                $cat_words = is_array($search_detail_fields['cat'] ?? null) ? ($search_detail_fields['cat']['words'] ?? null) : null;
                $cat_words = is_array($cat_words) ? array_values(array_filter($cat_words, is_string(...))) : null;

                $author_words = is_array($search_detail_fields['author'] ?? null) ? ($search_detail_fields['author']['words'] ?? null) : null;

                $added_by = $search_detail_fields['added_by'] ?? null;
                $added_by = is_array($added_by) ? array_values(array_filter($added_by, is_string(...))) : null;

                $filetypes = $search_detail_fields['filetypes'] ?? null;

                $is_falsy = static fn (mixed $v): bool => in_array($v, [null, false, 0, 0.0, '0', '', []], true);
                $search_detail = [
                    'allwords' => ! $is_falsy($allwords_words) ? $allwords_words : null,
                    'tags' => $tags_words !== null && $tags_words !== [] ? array_intersect_key($name_of_tag, array_flip($tags_words)) : null,
                    'date_posted' => ! $is_falsy($date_posted) ? $date_posted : null,
                    'cat' => $cat_words !== null && $cat_words !== [] ? array_intersect_key($name_of_category, array_flip($cat_words)) : null,
                    'author' => ! $is_falsy($author_words) ? $author_words : null,
                    'added_by' => $added_by !== null && $added_by !== [] ? array_intersect_key($username_of, array_flip($added_by)) : null,
                    'filetypes' => ! $is_falsy($filetypes) ? $filetypes : null,
                ];
            } else {
                $search_detail = null;
            }

            @++$sorted_members[$user_name];

            array_push(
                $result,
                [
                    'DATE' => DateHelper::formatDate($line_date ?? ''),
                    'TIME' => $line_time,
                    'USER' => $user_string,
                    'USERNAME' => $user_name,
                    'USERID' => $line_user_id,
                    'IP' => $line_ip,
                    'IMAGE' => $image_string,
                    'IMAGENAME' => $image_title,
                    'IMAGEID' => $image_id,
                    'EDIT_IMAGE' => $image_edit_string,
                    'TYPE' => $line_image_type,
                    'SECTION' => $line_section,
                    'FULL_CATEGORY_PATH' => $line_category_id !== null && isset($full_cat_path[$line_category_id]) ? strip_tags($full_cat_path[$line_category_id]) : $this->lang->t('Root') . $line_category_id,
                    'CATEGORY' => $line_category_id !== null && isset($name_of_category[$line_category_id]) ? $name_of_category[$line_category_id] : $this->lang->t('Root') . $line_category_id,
                    'SEARCH_ID' => $line_search_id,
                    'TAGS' => explode(',', (string) $tag_names),
                    'TAGIDS' => explode(',', $tag_ids),
                    'SEARCH_DETAILS' => $search_detail,
                ]
            );
        }

        $max_page = ceil(count($result) / 300);
        $result = array_reverse($result, true);
        $result = array_slice($result, ($param['pageNumber'] ?? 0) * 300, 300);

        // always array: see the loop-invariant comment on 'guests_IP' above.
        $guests_ip_final = $summary['guests_IP'];

        $summary['nb_guests'] = 0;
        if (count(array_keys($guests_ip_final)) > 0) {
            $summary['nb_guests'] = count(array_keys($guests_ip_final));

            // we delete the "guest" from the $username_of hash so that it is
            // avoided in next steps
            // guestId() is SCHEMA-typed 'int' only.
            $guest_id_key = (string) $this->currentConfig->guestId;
            $username_of = array_diff_key($username_of, [
                $guest_id_key => true,
            ]);
        }

        $summary['nb_members'] = count($username_of);

        $member_strings = [];
        foreach ($username_of as $user_id => $user_name) {
            $member_string = $user_name;
            $member_strings[] = [
                $member_string => $user_id,
            ];
        }

        arsort($sorted_members);
        unset($sorted_members['guest']);

        // 'total_filesize'/'nb_members'/'nb_guests' are only ever set to int by
        // this same function (see the loop-invariant comments above, plus the
        // count()-based assignments for nb_members/nb_guests); 'nb_lines' is set
        // once above from count($data).
        $summary_total_filesize = $summary['total_filesize'];
        $summary_nb_members = $summary['nb_members'];
        $summary_nb_guests = $summary['nb_guests'];
        $page_nb_lines = $page['nb_lines'];

        $search_summary =
        [
            'NB_LINES' => $this->translator->plural(
                '%d line filtered',
                '%d lines filtered',
                $page_nb_lines
            ),
            'FILESIZE' => $summary_total_filesize !== 0 ? ceil($summary_total_filesize / 1024) : 0,
            'USERS' => $this->translator->plural(
                '%d user',
                '%d users',
                $summary_nb_members + $summary_nb_guests
            ),
            'MEMBERS' => $member_strings,
            'SORTED_MEMBERS' => $sorted_members,
            'GUESTS' => $this->translator->plural(
                '%d guest',
                '%d guests',
                $summary_nb_guests
            ),
        ];

        unset($name_of_tag);

        return [
            'lines' => $result,
            'params' => $param,
            'maxPage' => ($max_page === 0.0) ? 1 : $max_page,
            'summary' => $search_summary,
        ];
    }
}
