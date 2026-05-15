<?php

declare(strict_types=1);

namespace Piwigo\Ws\Method;

use Doctrine\DBAL\Connection;
use Piwigo\Activity\ActivityLogger;
use Piwigo\Admin\History\HistoryAdminService;
use Piwigo\Admin\Image\ImageAdminService;
use Piwigo\Admin\Users\UserAdminService;
use Piwigo\Auth\CookieService;
use Piwigo\Category\CategoryRepository;
use Piwigo\Comment\CommentRepository;
use Piwigo\Config\Config;
use Piwigo\Config\ConfigService;
use Piwigo\Core\AppInfo;
use Piwigo\Core\DateService;
use Piwigo\Core\Filesystem;
use Piwigo\Core\Lang;
use Piwigo\Core\PageState;
use Piwigo\Core\StringUtil;
use Piwigo\Csrf\CsrfService;
use Piwigo\Db\Dml;
use Piwigo\Db\SchemaHelper;
use Piwigo\Db\Tables;
use Piwigo\Html\HtmlService;
use Piwigo\Image\DerivativeEncoding;
use Piwigo\Image\DerivativeImage;
use Piwigo\Image\DerivativeSize;
use Piwigo\Image\ImageRepository;
use Piwigo\Image\ImageStdParams;
use Piwigo\Image\SrcImage;
use Piwigo\Lang\Translator;
use Piwigo\Picture\PictureService;
use Piwigo\Plugins\EventDispatcher;
use Piwigo\Rate\RateService;
use Piwigo\Search\SearchRepository;
use Piwigo\Section\SectionContext;
use Piwigo\Section\SectionContextRegistry;
use Piwigo\Tag\TagRepository;
use Piwigo\Url\UrlGenerator;
use Piwigo\Url\UrlService;
use Piwigo\Users\AuthService;
use Piwigo\Users\CurrentUser;
use Piwigo\Users\PermissionService;
use Piwigo\Users\UserRepository;
use Piwigo\Validation\InputValidator;
use Piwigo\Ws\PwgError;
use Piwigo\Ws\PwgNamedArray;
use Piwigo\Ws\PwgServer;
use Piwigo\Ws\WsError;
use Piwigo\Ws\WsHelper;
use Psr\Cache\CacheItemPoolInterface;

final readonly class GeneralEndpoints
{
    public function __construct(
        private Connection $conn,
        private AuthService $authService,
        private CategoryRepository $categoryRepository,
        private CommentRepository $commentRepository,
        private ConfigService $configService,
        private CookieService $cookieService,
        private DateService $dateService,
        private HistoryAdminService $historyAdminService,
        private HtmlService $htmlService,
        private ImageAdminService $imageAdminService,
        private ImageRepository $imageRepository,
        private PermissionService $permissionService,
        private PictureService $pictureService,
        private RateService $rateService,
        private SearchRepository $searchRepository,
        private StringUtil $stringUtil,
        private TagRepository $tagRepository,
        private UrlGenerator $urlGenerator,
        private UrlService $urlService,
        private UserAdminService $userAdminService,
        private UserRepository $userRepository,
        private WsHelper $wsHelper,
        private CacheItemPoolInterface $pool,
        private ActivityLogger $activityLogger,
        private CsrfService $csrfService,
        private InputValidator $inputValidator,
    ) {
    }

    public function directorySizeBytes(string $path): ?int
    {
        if (!is_dir($path)) {
            return null;
        }
        $bytes    = 0;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );
        foreach ($iterator as $entry) {
            if ($entry instanceof \SplFileInfo && $entry->isFile()) {
                $bytes += $entry->getSize();
            }
        }
        return $bytes;
    }

    private function cacheSizeWithTtl(): int
    {
        $cacheKey = md5('ws_cache_size' . AppInfo::VERSION);
        $item     = $this->pool->getItem($cacheKey);
        if ($item->isHit() && is_int($item->get())) {
            return (int) $item->get();
        }
        $bytes = $this->directorySizeBytes(Config::dataLocation() . 'cache') ?? 0;
        $item->set($bytes);
        $item->expiresAfter(300);
        $this->pool->save($item);
        return $bytes;
    }

    /**
     * @param array<mixed> $params
     * @return array<mixed>|PwgError
     */
    public function getMissingDerivatives(array $params, PwgServer &$service): PwgError|array
    {
        if (empty($params['types'])) {
            $types = array_keys(ImageStdParams::getDefinedTypeMap());
        } else {
            $typesRaw = is_array($params['types']) ? $params['types'] : [];
            $types = array_intersect(array_keys(ImageStdParams::getDefinedTypeMap()), array_map(fn (mixed $v): string => is_scalar($v) ? (string) $v : '0', $typesRaw));
            if (count($types) === 0) {
                return new PwgError(WsError::InvalidParam->value, 'Invalid types');
            }
        }
        $maxUrls = is_numeric($params['max_urls']) ? (int) $params['max_urls'] : 0;
        [$maxId, $imageCount] = $this->imageRepository->findMaxIdAndCount();
        if ($imageCount === 0) {
            return [];
        }
        $startId = is_numeric($params['prev_page']) ? (int) $params['prev_page'] : 0;
        if ($startId <= 0) {
            $startId = $maxId;
        }
        $uid = '&b=' . time();
        Config::override('derivative_url_style', 2);
        $qlimit = min(5000, (int) ceil(max($imageCount / 500, $maxUrls / count($types))));
        /** @var array<string> $whereClauses */
        $whereClauses   = $this->wsHelper->imageSqlFilter($params, '');
        $whereClauses[] = 'id<start_id';
        if (!empty($params['ids'])) {
            $idsArr         = is_array($params['ids']) ? array_map(fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $params['ids']) : [];
            $whereClauses[] = 'id IN (' . implode(',', $idsArr) . ')';
        }
        $queryModel = 'SELECT id, path, representative_ext, width, height, rotation FROM ' . Tables::images() . ' WHERE ' . implode(' AND ', $whereClauses) . ' ORDER BY id DESC LIMIT ' . $qlimit . ';';
        $conn       = $this->conn;
        $urls       = [];
        do {
            $rows   = $conn->executeQuery(str_replace('start_id', (string) $startId, $queryModel))->fetchAllAssociative();
            $isLast = count($rows) < $qlimit;
            foreach ($rows as $row) {
                $startId  = is_numeric($row['id']) ? (int) $row['id'] : 0;
                $srcImage = new SrcImage($row);
                if ($srcImage->isMimetype()) {
                    continue;
                }
                foreach ($types as $type) {
                    $derivative = new DerivativeImage($type, $srcImage);
                    if ($type !== $derivative->getType()) {
                        continue;
                    }
                    if (Filesystem::tryFileMtime($derivative->getPath()) === false) {
                        $url    = $derivative->getUrl();
                        $urls[] = (is_string($url) ? $url : '') . $uid;
                    }
                }
                if (count($urls) >= $maxUrls && !$isLast) {
                    break;
                }
            }
            if ($isLast) {
                $startId = 0;
            }
        } while (count($urls) < $maxUrls && $startId);
        $ret = [];
        if ($startId) {
            $ret['next_page'] = $startId;
        }
        $ret['urls'] = $urls;
        return $ret;
    }

    public function getVersion(mixed $params, PwgServer &$service): string
    {
        return AppInfo::VERSION;
    }

    /**
     * @param array<mixed> $params
     * @return array<mixed>
     */
    public function getInfos(array $params, PwgServer &$service): array
    {
        $infos = [];
        $infos['version']            = AppInfo::VERSION;
        $imgRepo  = $this->imageRepository;
        $catRepo  = $this->categoryRepository;
        $tagRepo  = $this->tagRepository;
        $userRepo = $this->userRepository;
        $comRepo  = $this->commentRepository;
        $infos['nb_elements']           = $imgRepo->countAll();
        $infos['nb_categories']         = $catRepo->countAll();
        $infos['nb_virtual']            = $catRepo->countVirtual();
        $infos['nb_physical']           = $catRepo->countPhysical();
        $infos['nb_image_category']     = $catRepo->countImageCategoryLinks();
        $infos['nb_tags']               = $tagRepo->countAll();
        $infos['nb_image_tag']          = $tagRepo->countImageTags();
        $infos['nb_users']              = $userRepo->countAll(Tables::users());
        $infos['nb_groups']             = $userRepo->countGroups();
        $infos['nb_comments']           = $comRepo->countAll();
        if ($infos['nb_elements'] > 0) {
            $infos['first_date'] = $imgRepo->findEarliestDate();
        }
        if ($infos['nb_comments'] > 0) {
            $infos['nb_unvalidated_comments'] = $comRepo->countUnvalidated();
        }
        $infos['cache_size'] = $this->cacheSizeWithTtl();
        $output = [];
        foreach ($infos as $name => $value) {
            $output[] = ['name' => $name, 'value' => $value];
        }
        return ['infos' => new PwgNamedArray($output, 'item')];
    }

    /**
     * @param array<mixed> $params
     * @return array<mixed>
     */
    public function getCacheSize(array $params, PwgServer &$service): array
    {
        $infos = [];
        $pathCache = Config::dataLocation();
        $infos['cache_size'] = $this->directorySizeBytes($pathCache);
        $pathMsizes = Config::dataLocation() . 'i';
        $msizes     = $this->imageAdminService->getCacheSizeDerivatives($pathMsizes);
        $infos['msizes'] = array_fill_keys(array_keys(ImageStdParams::getDefinedTypeMap()), 0.0);
        $infos['msizes']['custom'] = 0.0;
        $all = 0.0;
        foreach (array_keys($infos['msizes']) as $sizeType) {
            $infos['msizes'][$sizeType] += (float) ($msizes[DerivativeEncoding::derivativeToUrl($sizeType)] ?? 0);
            $all += $infos['msizes'][$sizeType];
        }
        $infos['msizes']['all'] = $all;
        $pathTemplateC = Config::dataLocation() . 'templates_c';
        $infos['tsizes']         = $this->directorySizeBytes($pathTemplateC);
        $infos['last_date_calc'] = date('Y-m-d H:i:s');
        $output = [];
        foreach ($infos as $name => $value) {
            $output[] = ['name' => $name, 'value' => $value];
        }
        $this->configService->confUpdateParam('cache_sizes', $output, true);
        return ['infos' => new PwgNamedArray($output, 'item')];
    }

    /** @param array<mixed> $params */
    public function caddieAdd(array $params, PwgServer &$service): int
    {
        $userId = CurrentUser::get()->id;
        $query  = 'SELECT id FROM ' . Tables::images() . ' LEFT JOIN ' . Tables::caddie() . ' ON id=element_id AND user_id=' . $userId . ' WHERE id IN (' . implode(',', array_map(fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, is_array($params['image_id']) ? $params['image_id'] : [])) . ') AND element_id IS NULL;';
        $result = array_column($this->conn->executeQuery($query)->fetchAllAssociative(), 'id');
        $datas  = [];
        foreach ($result as $id) {
            $datas[] = ['element_id' => $id, 'user_id' => $userId];
        }
        if (count($datas)) {
            Dml::massInserts(Tables::caddie(), ['element_id', 'user_id'], $datas);
        }
        return count($datas);
    }

    /** @param array<mixed> $params */
    public function ratesDelete(array $params, PwgServer &$service): mixed
    {
        $userId = is_numeric($params['user_id']) ? (int) $params['user_id'] : 0;
        $query  = 'DELETE FROM ' . Tables::rate() . ' WHERE user_id=' . $userId;
        if (!empty($params['anonymous_id'])) {
            $query .= " AND anonymous_id='" . (is_string($params['anonymous_id']) ? $params['anonymous_id'] : '') . "'";
        }
        if (!empty($params['image_id'])) {
            $query .= ' AND element_id=' . (is_numeric($params['image_id']) ? (int) $params['image_id'] : 0);
        }
        $changes = $this->conn->executeStatement($query);
        if ($changes > 0) {
            $this->rateService->updateRatingScore();
        }
        return $changes;
    }

    /** @param array<mixed> $params */
    public function sessionLogin(array $params, PwgServer &$service): PwgError|true
    {
        if (defined('PWG_API_KEY_REQUEST')) {
            return new PwgError(401, 'Cannot use this method with an api key');
        }
        $username = is_string($params['username'] ?? null) ? $params['username'] : '';
        $password = is_string($params['password'] ?? null) ? $params['password'] : '';
        if (preg_match('/^pkid-\d{8}-[a-z0-9]{20}$/i', $username)) {
            if ($this->authService->authKeyLogin($username . ':' . $password)) {
                $_SESSION['connected_with'] = 'ws_session_login_api_key';
                return true;
            }
        } elseif ($this->authService->tryLogUser($username, $password, false)) {
            $_SESSION['connected_with'] = 'ws_session_login';
            return true;
        }
        return new PwgError(999, 'Invalid username/password');
    }

    public function sessionLogout(mixed $params, PwgServer &$service): PwgError|true
    {
        if (defined('PWG_API_KEY_REQUEST')) {
            return new PwgError(401, 'Cannot use this method with an api key');
        }
        if (!$this->permissionService->isAGuest()) {
            $this->authService->logoutUser();
        }
        return true;
    }

    public function sessionGetStatus(mixed $params, PwgServer &$service): mixed
    {
        $currentUser = CurrentUser::get();
        $res = [];
        $res['username'] = $this->permissionService->isAGuest() ? 'guest' : stripslashes($currentUser->username);
        $res['status']   = $currentUser->status;
        $res['theme']    = $currentUser->theme;
        $res['language'] = $currentUser->language;
        $res['pwg_token'] = $this->csrfService->getToken();
        $res['charset']   = $this->stringUtil->getPwgCharset();
        $res['current_datetime'] = new \DateTimeImmutable()->format('Y-m-d H:i:s');
        $res['version']   = AppInfo::VERSION;
        $res['save_visits'] = $this->activityLogger->isLoggingEnabled();
        $res['connected_with'] = $_SESSION['connected_with'] ?? null;
        /** @var mixed $httpUserAgentRaw */
        $httpUserAgentRaw = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $httpUserAgent    = is_string($httpUserAgentRaw) ? $httpUserAgentRaw : '';
        if ($httpUserAgent !== '' && preg_match('/^PiwigoRemoteSync/', $httpUserAgent)) {
            unset($res['save_visits'], $res['connected_with']);
        }
        if ($httpUserAgent === '' || !str_starts_with($httpUserAgent, 'Apache-HttpClient/')) {
            $res['available_sizes'] = array_keys(ImageStdParams::getDefinedTypeMap());
        }
        if ($this->permissionService->isAdmin()) {
            $res['upload_file_types'] = implode(',', array_unique(array_map(strtolower(...), Config::uploadFormAllTypes() ? Config::fileExtensions() : Config::pictureExtensions())));
            $res['upload_form_chunk_size'] = Config::uploadFormChunkSize();
        }
        return $res;
    }

    /**
     * @param array<mixed> $param
     * @return array<mixed>|PwgError
     */
    public function getActivityList(array $param, PwgServer &$service): PwgError|array
    {
        foreach (['date_min', 'date_max'] as $datefield) {
            if (!empty($param[$datefield]) && !$this->stringUtil->isValidMysqlDatetime(is_scalar($param[$datefield]) ? (string) $param[$datefield] : '')) {
                return new PwgError(WsError::InvalidParam->value, 'Invalid ' . $datefield);
            }
        }
        $outputLines = [];
        $currentKey  = '';
        $pageSize    = 100;
        $pageOffset  = is_numeric($param['offset']) ? (int) $param['offset'] : 0;
        $nbRowsToFetch = 10000;
        $userIds     = [];
        $lineId      = 0;
        $min = '';
        $max = '';
        if (!empty($param['date_min'])) {
            $dateMinStr = is_string($param['date_min']) ? $param['date_min'] : '';
            $dateMaxStr = isset($param['date_max']) && is_string($param['date_max']) ? $param['date_max'] : '';
            $dmin       = date_create($dateMinStr);
            $dmax       = $dateMaxStr !== '' ? date_create($dateMaxStr) : false;
            $min        = $dmin !== false ? date_format($dmin, 'Y-m-d H:i:s') : '';
            $max        = $dmax !== false ? date_format($dmax, 'Y-m-d 23:59:59') : '';
        }
        if (!empty($param['date_max'])) {
            $dateMaxStr2 = is_string($param['date_max']) ? $param['date_max'] : '';
            $dmax2       = date_create($dateMaxStr2);
            $max         = $dmax2 !== false ? date_format($dmax2, 'Y-m-d 23:59:59') : '';
        }
        $where = "WHERE object != 'system'";
        if (isset($param['uid'])) {
            $where .= ' AND performed_by=' . (is_numeric($param['uid']) ? (int) $param['uid'] : 0);
        }
        if (isset($param['action'])) {
            $where .= ' AND action=' . $this->conn->quote(is_string($param['action']) ? $param['action'] : '');
        }
        if (isset($param['object'])) {
            $where .= ' AND object=' . $this->conn->quote(is_string($param['object']) ? $param['object'] : '');
        }
        $dateMinVal = $param['date_min'] ?? null;
        if ($dateMinVal !== null && $dateMinVal !== '' && $dateMinVal !== false && $dateMinVal !== 0) {
            $where .= ' AND occured_on >= "' . $min . '"';
        }
        $dateMaxVal = $param['date_max'] ?? null;
        if ($dateMaxVal !== null && $dateMaxVal !== '' && $dateMaxVal !== false && $dateMaxVal !== 0) {
            $where .= ' AND occured_on <= "' . $max . '"';
        }
        if (!empty($param['id'])) {
            $where .= ' AND object_id=' . (is_numeric($param['id']) ? (int) $param['id'] : 0);
        }
        if ('none' === Config::activityDisplayConnections()) {
            $where .= " AND action NOT IN ('login', 'logout')";
        } elseif ('admins_only' === Config::activityDisplayConnections()) {
            $where .= ' AND NOT (action IN (\'login\', \'logout\') AND object_id NOT IN (' . implode(',', $this->userAdminService->getAdmins()) . '))';
        }
        $moreRowsAvailable = true;
        while (count($outputLines) < $pageSize && $moreRowsAvailable) {
            $query = 'SELECT activity_id, performed_by, object, object_id, action, session_idx, ip_address, occured_on, details, user_agent FROM ' . Tables::activity() . ' ' . $where . ' ORDER BY activity_id DESC LIMIT ' . $nbRowsToFetch . ' OFFSET ' . $pageOffset . ';';
            $rows  = $this->conn->executeQuery($query)->fetchAllAssociative();
            if (count($rows) < $nbRowsToFetch) {
                $moreRowsAvailable = false;
            }
            foreach ($rows as $row) {
                if (count($outputLines) < $pageSize) {
                    $pageOffset++;
                    $rowSessionIdx = is_string($row['session_idx'] ?? null) ? $row['session_idx'] : '';
                    $rowObject     = is_string($row['object'] ?? null) ? $row['object'] : '';
                    $rowAction     = is_string($row['action'] ?? null) ? $row['action'] : '';
                    $lineKey = $rowSessionIdx . '~' . $rowObject . '~' . $rowAction . '~';
                    if ($lineKey === $currentKey) {
                        $outputLines[count($outputLines) - 1]['counter']++;
                        $outputLines[count($outputLines) - 1]['object_id'][] = $row['object_id'];
                    } else {
                        $rowDetailsStr = is_string($row['details'] ?? null) ? $row['details'] : '';
                        $rowDetailsStr = str_replace(['`groups`', '`rank`'], ['groups', 'rank'], $rowDetailsStr);
                        $details       = StringUtil::safeUnserialize($rowDetailsStr);
                        if (isset($row['user_agent'])) {
                            $details['agent'] = $row['user_agent'];
                        }
                        $detailsType = '';
                        if (isset($details['method'])) {
                            $detailsType = 'method';
                        }
                        if (isset($details['script'])) {
                            $detailsType = 'script';
                        }
                        [$date, $hour]     = explode(' ', is_string($row['occured_on'] ?? null) ? $row['occured_on'] : '');
                        $rowPerformedBy    = $row['performed_by'];
                        $outputLines[]     = ['id' => $lineId, 'object' => $rowObject, 'object_id' => [$row['object_id']], 'action' => $rowAction, 'ip_address' => $row['ip_address'], 'date' => $this->dateService->formatDate($date), 'hour' => $hour, 'user_id' => $rowPerformedBy, 'detailsType' => $detailsType, 'details' => $details, 'counter' => 1];
                        $userIdKey = is_scalar($rowPerformedBy) ? (string) $rowPerformedBy : '';
                        if ($userIdKey !== '') {
                            $userIds[$userIdKey] = 1;
                        }
                        if ('user' === $rowObject) {
                            $objId    = $row['object_id'];
                            $objIdKey = is_scalar($objId) ? (string) $objId : '';
                            if ($objIdKey !== '') {
                                $userIds[$objIdKey] = 1;
                            }
                        }
                        $currentKey = $lineKey;
                        $lineId++;
                    }
                } else {
                    $moreRowsAvailable = true;
                    break;
                }
            }
        }
        $usernameOf = [];
        if (count($userIds) > 0) {
            $query = 'SELECT `' . Config::userFields()['id'] . '` AS user_id, `' . Config::userFields()['username'] . '` AS username FROM ' . Tables::users() . ' WHERE `' . Config::userFields()['id'] . '` IN (' . implode(',', array_keys($userIds)) . ');';
            $usernameOf = array_column($this->conn->executeQuery($query)->fetchAllAssociative(), 'username', 'user_id');
        }
        foreach ($outputLines as $idx => $outputLine) {
            if ('user' === ($outputLine['object'] ?? '')) {
                /** @phpstan-ignore-next-line nullCoalesce.offset */
                $objIds = $outputLine['object_id'] ?? [];
                foreach ($objIds as $uid) {
                    $uidKey = is_scalar($uid) ? (string) $uid : '';
                    $detRaw = $outputLines[$idx]['details'] ?? null;
                    /** @var array<string, mixed> $detArr */
                    $detArr = is_array($detRaw) ? $detRaw : [];
                    /** @var list<mixed> $detUsers */
                    $detUsersArr = $detArr['users'] ?? null;
                    $detUsers   = is_array($detUsersArr) ? $detUsersArr : [];
                    $detUsers[] = $usernameOf[$uidKey] ?? ('user#' . $uidKey);
                    $detArr['users'] = $detUsers;
                    $outputLines[$idx]['details'] = $detArr;
                }
                $detRaw2 = $outputLines[$idx]['details'] ?? null; // @phpstan-ignore nullCoalesce.offset
                /** @var array<string, mixed> $detArr2 */
                $detArr2 = is_array($detRaw2) ? $detRaw2 : [];
                if (isset($detArr2['users'])) {
                    $usersArr = is_array($detArr2['users']) ? $detArr2['users'] : [];
                    $detArr2['users_string'] = implode(', ', array_map(fn (mixed $v): string => is_scalar($v) ? (string) $v : '0', $usersArr));
                    $outputLines[$idx]['details'] = $detArr2;
                }
            }
            $lineUserId    = $outputLines[$idx]['user_id'] ?? null;
            $lineUserIdStr = is_scalar($lineUserId) ? (string) $lineUserId : '';
            $outputLines[$idx]['username'] = 'user#' . $lineUserIdStr;
            if ($lineUserIdStr !== '' && isset($usernameOf[$lineUserIdStr])) {
                $outputLines[$idx]['username'] = $usernameOf[$lineUserIdStr];
            }
        }
        return ['result_lines' => $outputLines, 'page_offset' => $pageOffset, 'end_page' => !$moreRowsAvailable, 'params' => $param];
    }

    /** @param array<mixed> $params */
    public function historyLog(array $params, PwgServer &$service): void
    {
        $currentCtx = SectionContextRegistry::current();

        $section = $currentCtx->section;
        if (!empty($params['section']) && in_array($params['section'], SchemaHelper::getEnums(Tables::history(), 'section'))) {
            $section = is_string($params['section']) ? $params['section'] : $section;
        }

        $category = $currentCtx->category;
        if (!empty($params['cat_id'])) {
            $category = ['id' => $params['cat_id']];
        }

        $tagIds = $currentCtx->tagIds;
        $tagsString = is_string($params['tags_string'] ?? null) ? $params['tags_string'] : '';
        if ($tagsString !== '' && preg_match('/^\d+(,\d+)*$/', $tagsString)) {
            $tagIds = array_map(intval(...), explode(',', $tagsString));
        }

        SectionContextRegistry::set(new SectionContext(
            section: $section,
            category: $category,
            tagIds: $tagIds,
        ));

        $logImageId = is_numeric($params['image_id']) ? (int) $params['image_id'] : null;
        if (!empty($params['image_id']) && $logImageId !== null) {
            $this->pictureService->increaseImageVisitCounter($logImageId);
        }
        $imageType = $params['is_download'] ? 'high' : 'picture';
        $this->activityLogger->pageView($logImageId, $imageType);
    }

    /**
     * @param array<mixed> $param
     * @return array<mixed>
     */
    public function historySearch(array $param, PwgServer &$service): array
    {
        $types = array_merge(['none'], SchemaHelper::getEnums(Tables::history(), 'image_type'));
        $displayThumbnails = ['no_display_thumbnail' => Lang::t('No display'), 'display_thumbnail_classic' => Lang::t('Classic display'), 'display_thumbnail_hoverbox' => Lang::t('Hoverbox display')];
        PageState::current()->errors = [];
        $search         = ['fields' => []];
        if (!empty($param['start'])) {
            $this->inputValidator->check('start', $param, false, '/^\d{4}-\d{2}-\d{2}$/');
            $search['fields']['date-after'] = $param['start'];
        }
        if (!empty($param['end'])) {
            $this->inputValidator->check('end', $param, false, '/^\d{4}-\d{2}-\d{2}$/');
            $search['fields']['date-before'] = $param['end'];
        }
        if (empty($param['types'])) {
            $search['fields']['types'] = $types;
        } else {
            $this->inputValidator->check('types', $param, true, '/^(' . implode('|', $types) . ')$/');
            $search['fields']['types'] = $param['types'];
        }
        $search['fields']['user'] = is_numeric($param['user_id']) ? (int) $param['user_id'] : 0;
        if (!empty($param['image_id'])) {
            $search['fields']['image_id'] = is_numeric($param['image_id']) ? (int) $param['image_id'] : 0;
        }
        if (!empty($param['filename'])) {
            $search['fields']['filename'] = str_replace('*', '%', is_string($param['filename']) ? $param['filename'] : '');
        }
        if (!empty($param['ip'])) {
            $search['fields']['ip'] = str_replace('*', '%', is_string($param['ip']) ? $param['ip'] : '');
        }
        $this->inputValidator->check('display_thumbnail', $param, false, '/^(' . implode('|', array_keys($displayThumbnails)) . ')$/');
        $search['fields']['display_thumbnail'] = $param['display_thumbnail'];
        $displayThumbnailRaw = $param['display_thumbnail'] ?? null;
        $displayThumbnailStr = is_string($displayThumbnailRaw) ? $displayThumbnailRaw : '';
        $cookieVal           = ($displayThumbnailStr !== '' && isset($displayThumbnails[$displayThumbnailStr])) ? $displayThumbnailStr : null;
        $strtotimeMonth = strtotime('+1 month');
        /** @var int|false $strtotimeMonth */
        $this->cookieService->setCookieVar('display_thumbnail', $cookieVal, $strtotimeMonth === false ? null : $strtotimeMonth);
        $searchRepo = $this->searchRepository;
        $searchId   = $searchRepo->insertSearch(serialize($search));
        $serializedRules = $searchRepo->findRulesById($searchId);
        $searchRules = unserialize(is_string($serializedRules) ? $serializedRules : '');
        $search = is_array($searchRules) ? $searchRules : [];
        $historyService = $this->historyAdminService;
        $search         = $historyService->prepareSearch($search);

        $pageNumber = is_numeric($param['pageNumber']) ? (int) $param['pageNumber'] : 0;
        $pageSize   = Config::nbLogsPage();

        $nbLines       = $historyService->getHistoryCount($search, $types);
        $totalFilesize = $historyService->getHistoryTotalFilesizeForHigh($search, $types);
        $guestIpHist   = $historyService->getHistoryGuestIpHistogram($search, $types, Config::guestId());
        $userHitCounts = $historyService->getHistoryUserHitCounts($search, $types);
        $searchIds     = $historyService->getHistoryDistinctSearchIds($search, $types);
        $pageRows      = $historyService->getHistoryPage($search, $types, $pageNumber * $pageSize, $pageSize);

        $userIds = [];
        foreach (array_keys($userHitCounts) as $uid) {
            $userIds[(string) $uid] = 1;
        }
        $categoryIds = [];
        $imageIds    = [];
        $hasTags     = false;
        foreach ($pageRows as $row) {
            if (isset($row['category_id'])) {
                $categoryIds[] = $row['category_id'];
            }
            if (isset($row['image_id'])) {
                $rowImageId    = $row['image_id'];
                $rowImageIdKey = is_scalar($rowImageId) ? (string) $rowImageId : '';
                if ($rowImageIdKey !== '') {
                    $imageIds[$rowImageIdKey] = 1;
                }
            }
            if (isset($row['tag_ids'])) {
                $hasTags = true;
            }
        }
        $usernameOf    = [];
        $searchDetails = [];
        if (count($searchIds) > 0) {
            $sdQuery       = 'SELECT id, rules FROM ' . Tables::search() . ' WHERE id IN (' . implode(',', array_map(intval(...), $searchIds)) . ');';
            $searchDetails = array_column($this->conn->executeQuery($sdQuery)->fetchAllAssociative(), 'rules', 'id');
            foreach ($searchDetails as $idSearch => $rulesSearch) {
                $rulesArr    = StringUtil::safeUnserialize(is_scalar($rulesSearch) ? (string) $rulesSearch : '');
                $rulesFields = is_array($rulesArr['fields'] ?? null) ? $rulesArr['fields'] : [];
                $rfTags      = is_array($rulesFields['tags'] ?? null) ? $rulesFields['tags'] : [];
                $rfCat       = is_array($rulesFields['cat'] ?? null) ? $rulesFields['cat'] : [];
                if (!empty($rfTags['words'])) {
                    $hasTags = true;
                }
                if (!empty($rfCat['words'])) {
                    $catWords   = is_array($rfCat['words']) ? $rfCat['words'] : [];
                    $categoryIds = array_merge($categoryIds, $catWords);
                }
                if (!empty($rulesFields['added_by'])) {
                    $addedBy = is_array($rulesFields['added_by']) ? $rulesFields['added_by'] : [];
                    foreach ($addedBy as $key) {
                        $keyStr = is_scalar($key) ? (string) $key : '';
                        if ($keyStr !== '') {
                            $userIds[$keyStr] = 1;
                        }
                    }
                }
                $searchDetails[$idSearch] = $rulesFields;
            }
        }
        if (count($userIds) > 0) {
            $userFields = Config::userFields();
            $rawMap     = $this->userRepository->findUsernamesByIds($userFields['id'], $userFields['username'], Tables::users(), array_map(intval(...), array_keys($userIds)));
            foreach ($rawMap as $id => $username) {
                $usernameOf[$id] = stripslashes($username);
            }
        }
        $nameOfCategory = [];
        $imageInfos     = [];
        $fullCatPath    = [];
        if (count($categoryIds) > 0) {
            $categoryIdsStr = array_map(fn (mixed $v): string => is_scalar($v) ? (string) $v : '0', $categoryIds);
            $uppercatsOf    = array_column($this->conn->executeQuery('SELECT id, uppercats FROM ' . Tables::categories() . ' WHERE id IN (' . implode(',', $categoryIdsStr) . ');')->fetchAllAssociative(), 'uppercats', 'id');
            foreach ($uppercatsOf as $categoryId => $uppercats) {
                $uppercatsS           = is_scalar($uppercats) ? (string) $uppercats : '';
                $albumBase = $this->urlGenerator->admin() . '&page=album-';
                $fullCatPath[$categoryId] = $this->htmlService->getCatDisplayNameCache($uppercatsS, $albumBase);
                $uppercatsParts       = explode(',', $uppercatsS);
                $nameOfCategory[$categoryId] = $this->htmlService->getCatDisplayNameCache(end($uppercatsParts) ?: '', $albumBase);
            }
        }
        if (count($imageIds) > 0) {
            $imageInfos = array_column($this->conn->executeQuery('SELECT id, IF(name IS NULL, file, name) AS label, filesize, file, path, representative_ext FROM ' . Tables::images() . ' WHERE id IN (' . implode(',', array_keys($imageIds)) . ');')->fetchAllAssociative(), null, 'id');
        }
        $nameOfTag = [];
        if ($hasTags) {
            foreach ($this->tagRepository->findAll() as $row) {
                $tagIdKey = is_scalar($row['id'] ?? null) ? (string) $row['id'] : '';
                if ($tagIdKey !== '') {
                    $nameOfTag[$tagIdKey] = EventDispatcher::dispatch('render_tag_name', $row['name'], $row);
                }
            }
        }
        $result = [];
        foreach ($pageRows as $line) {
            $lineImageType  = is_string($line['image_type'] ?? null) ? $line['image_type'] : '';
            $lineImageId    = $line['image_id'] ?? null;
            $lineImageIdStr = is_scalar($lineImageId) ? (string) $lineImageId : '';
            $lineUserId     = $line['user_id'] ?? null;
            $lineUserIdStr  = is_scalar($lineUserId) ? (string) $lineUserId : '';
            $lineIP         = is_string($line['IP'] ?? null) ? $line['IP'] : '';
            $lineCatId      = $line['category_id'] ?? null;
            $lineCatIdStr   = is_scalar($lineCatId) ? (string) $lineCatId : '';
            $lineSearchId   = $line['search_id'] ?? null;
            $lineSearchIdStr = is_scalar($lineSearchId) ? (string) $lineSearchId : '';
            $lineSection    = is_string($line['section'] ?? null) ? $line['section'] : '';
            $userName   = '#unknown';
            $userString = '';
            if ($lineUserIdStr !== '' && isset($usernameOf[$lineUserIdStr])) {
                $userName    = $usernameOf[$lineUserIdStr];
                $userString .= $usernameOf[$lineUserIdStr];
            } else {
                $userString .= $lineUserIdStr;
            }
            $userString .= '&nbsp;<a href="' . $this->urlGenerator->admin('history') . '&amp;search_id=' . $searchId . '&amp;user_id=' . $lineUserIdStr . '">+</a>';
            $tagNames = '';
            $tagIds   = '';
            if (isset($line['tag_ids'])) {
                $lineTagIds = is_string($line['tag_ids']) ? $line['tag_ids'] : '';
                $tagNames   = preg_replace_callback('/(\d+)/', function (array $m) use ($nameOfTag): string {
                    $k = $m[1];
                    return isset($nameOfTag[$k]) && is_string($nameOfTag[$k]) ? $nameOfTag[$k] : $k;
                }, $lineTagIds) ?? $lineTagIds;
                $tagIds     = $lineTagIds;
            }
            $imageString    = '';
            $imageTitle     = '';
            $imageEditString = '';
            $imageId        = '';
            if ($lineImageIdStr !== '') {
                $imageEditString = $this->urlGenerator->admin('photo-' . $lineImageIdStr);
                $pictureUrl      = $this->urlService->makePictureUrl(['image_id' => $lineImageId]);
                $element         = [];
                if (isset($imageInfos[$lineImageIdStr])) {
                    $element = ['id' => $lineImageId, 'file' => $imageInfos[$lineImageIdStr]['file'], 'path' => $imageInfos[$lineImageIdStr]['path'], 'representative_ext' => $imageInfos[$lineImageIdStr]['representative_ext']];
                }
                $imageTitle = '';
                if (isset($imageInfos[$lineImageIdStr]['label'])) {
                    $tcResult    = EventDispatcher::dispatch('render_element_description', $imageInfos[$lineImageIdStr]['label']);
                    $imageTitle .= ' ' . (is_scalar($tcResult) ? (string) $tcResult : '');
                } else {
                    $imageEditString = '';
                    $imageTitle     .= ' unknown filename';
                }
                $imageId = $lineImageId;
                set_error_handler(static fn (): bool => true);
                try {
                    $imgUrl = DerivativeImage::url(ImageStdParams::getByType(DerivativeSize::Square->value), $element);
                } finally {
                    restore_error_handler();
                }
                $imageString = '<span><img src="' . (is_string($imgUrl) ? $imgUrl : '') . '" alt="' . $imageTitle . '" title="' . $imageTitle . '">';
            }
            $searchDetail = null;
            if ($lineSearchIdStr !== '' && isset($searchDetails[$lineSearchIdStr])) {
                $sd     = $searchDetails[$lineSearchIdStr];
                $sdTags = is_array($sd['tags'] ?? null) ? $sd['tags'] : [];
                $sdCat  = is_array($sd['cat'] ?? null) ? $sd['cat'] : [];
                $sdAllwords = is_array($sd['allwords'] ?? null) ? $sd['allwords'] : [];
                $sdAuthor   = is_array($sd['author'] ?? null) ? $sd['author'] : [];
                $sdTagsWords = is_array($sdTags['words'] ?? null) ? $sdTags['words'] : [];
                $sdCatWords  = is_array($sdCat['words'] ?? null) ? $sdCat['words'] : [];
                $sdAllwordsWords = is_array($sdAllwords['words'] ?? null) ? $sdAllwords['words'] : [];
                $sdAuthorWords   = is_array($sdAuthor['words'] ?? null) ? $sdAuthor['words'] : [];
                $sdAddedBy       = is_array($sd['added_by'] ?? null) ? $sd['added_by'] : [];
                $searchDetail = ['allwords' => (count($sdAllwordsWords) > 0) ? $sdAllwordsWords : null, 'tags' => (count($sdTagsWords) > 0) ? array_intersect_key($nameOfTag, array_flip(array_map(fn (mixed $v): string => is_scalar($v) ? (string) $v : '0', $sdTagsWords))) : null, 'date_posted' => (isset($sd['date_posted']) && $sd['date_posted'] !== '') ? $sd['date_posted'] : null, 'cat' => (count($sdCatWords) > 0) ? array_intersect_key($nameOfCategory, array_flip(array_map(fn (mixed $v): string => is_scalar($v) ? (string) $v : '0', $sdCatWords))) : null, 'author' => (count($sdAuthorWords) > 0) ? $sdAuthorWords : null, 'added_by' => (count($sdAddedBy) > 0) ? array_intersect_key($usernameOf, array_flip(array_map(fn (mixed $v): string => is_scalar($v) ? (string) $v : '0', $sdAddedBy))) : null, 'filetypes' => (isset($sd['filetypes']) && $sd['filetypes'] !== '') ? $sd['filetypes'] : null];
            }
            $lineDate = is_scalar($line['date'] ?? null) ? $line['date'] : null;
            array_push($result, ['DATE' => $this->dateService->formatDate(is_string($lineDate) || is_int($lineDate) ? $lineDate : null), 'TIME' => $line['time'] ?? null, 'USER' => $userString, 'USERNAME' => $userName, 'USERID' => $lineUserId, 'IP' => $lineIP, 'IMAGE' => $imageString, 'IMAGENAME' => $imageTitle, 'IMAGEID' => $imageId, 'EDIT_IMAGE' => $imageEditString, 'TYPE' => $lineImageType, 'SECTION' => $lineSection, 'FULL_CATEGORY_PATH' => ($lineCatIdStr !== '' && isset($fullCatPath[$lineCatIdStr])) ? strip_tags($fullCatPath[$lineCatIdStr]) : Lang::t('Root') . $lineCatIdStr, 'CATEGORY' => ($lineCatIdStr !== '' && isset($nameOfCategory[$lineCatIdStr])) ? $nameOfCategory[$lineCatIdStr] : Lang::t('Root') . $lineCatIdStr, 'SEARCH_ID' => $lineSearchId ?? null, 'TAGS' => explode(',', $tagNames), 'TAGIDS' => explode(',', $tagIds), 'SEARCH_DETAILS' => $searchDetail]);
        }
        $sortedMembers = [];
        foreach ($userHitCounts as $uid => $hits) {
            $uidStr = (string) $uid;
            $name   = $usernameOf[$uidStr] ?? '#unknown';
            $sortedMembers[$name] = ($sortedMembers[$name] ?? 0) + $hits;
        }
        $nbGuests = count($guestIpHist);
        if ($nbGuests > 0) {
            unset($usernameOf[(string) Config::guestId()]);
        }
        $nbMembers     = count($usernameOf);
        $memberStrings = [];
        foreach ($usernameOf as $userId => $userName) {
            $memberStrings[] = [$userName => $userId];
        }
        arsort($sortedMembers);
        unset($sortedMembers['guest']);
        $maxPage = (int) ceil($nbLines / $pageSize);
        $searchSummary = ['NB_LINES' => Translator::get()->plural('%d line filtered', '%d lines filtered', $nbLines), 'FILESIZE' => $totalFilesize != 0 ? ceil($totalFilesize / 1024) : 0, 'USERS' => Translator::get()->plural('%d user', '%d users', $nbMembers + $nbGuests), 'MEMBERS' => $memberStrings, 'SORTED_MEMBERS' => $sortedMembers, 'GUESTS' => Translator::get()->plural('%d guest', '%d guests', $nbGuests)];
        return ['lines' => $result, 'params' => $param, 'maxPage' => ($maxPage == 0) ? 1 : $maxPage, 'summary' => $searchSummary];
    }
}
