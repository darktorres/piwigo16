<?php

declare(strict_types=1);

namespace Piwigo\Ws\Method;

use Doctrine\DBAL\Connection;
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
use Piwigo\Core\ServiceLocator;
use Piwigo\Core\StringUtil;
use Piwigo\Core\Util;
use Piwigo\Db\DbConnection;
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
use Piwigo\Tag\TagRepository;
use Piwigo\Url\UrlGenerator;
use Piwigo\Url\UrlService;
use Piwigo\Users\AuthService;
use Piwigo\Users\CurrentUser;
use Piwigo\Users\PermissionService;
use Piwigo\Users\UserRepository;
use Piwigo\Ws\PwgError;
use Piwigo\Ws\PwgNamedArray;
use Piwigo\Ws\PwgServer;
use Piwigo\Ws\WsHelper;

final class GeneralEndpoints
{
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
            $types = array_intersect(array_keys(ImageStdParams::getDefinedTypeMap()), array_map(fn (mixed $v): string => is_scalar($v) ? (string) $v : '', $typesRaw));
            if (count($types) === 0) {
                return new PwgError(WS_ERR_INVALID_PARAM, 'Invalid types');
            }
        }
        $maxUrls = is_numeric($params['max_urls']) ? (int) $params['max_urls'] : 0;
        [$maxId, $imageCount] = ServiceLocator::get(ImageRepository::class)->findMaxIdAndCount();
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
        $whereClauses   = ServiceLocator::get(WsHelper::class)->imageSqlFilter($params, '');
        $whereClauses[] = 'id<start_id';
        if (!empty($params['ids'])) {
            $idsArr         = is_array($params['ids']) ? array_map(fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $params['ids']) : [];
            $whereClauses[] = 'id IN (' . implode(',', $idsArr) . ')';
        }
        $queryModel = 'SELECT id, path, representative_ext, width, height, rotation FROM ' . Tables::images() . ' WHERE ' . implode(' AND ', $whereClauses) . ' ORDER BY id DESC LIMIT ' . $qlimit . ';';
        $conn       = ServiceLocator::get(Connection::class);
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
        $infos['version']            = AppInfo::VERSION;
        $imgRepo  = ServiceLocator::get(ImageRepository::class);
        $catRepo  = ServiceLocator::get(CategoryRepository::class);
        $tagRepo  = ServiceLocator::get(TagRepository::class);
        $userRepo = ServiceLocator::get(UserRepository::class);
        $comRepo  = ServiceLocator::get(CommentRepository::class);
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
        $infos['cache_size'] = 4242;
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
        $pathCache = Config::dataLocation();
        $infos['cache_size'] = $this->directorySizeBytes($pathCache);
        $pathMsizes = Config::dataLocation() . 'i';
        $msizes     = ServiceLocator::get(ImageAdminService::class)->getCacheSizeDerivatives($pathMsizes);
        $infos['msizes'] = array_fill_keys(array_keys(ImageStdParams::getDefinedTypeMap()), 0);
        $infos['msizes']['custom'] = 0;
        $all = 0;
        foreach (array_keys($infos['msizes']) as $sizeType) {
            $infos['msizes'][$sizeType] += $msizes[DerivativeEncoding::derivativeToUrl($sizeType)] ?? 0;
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
        ServiceLocator::get(ConfigService::class)->confUpdateParam('cache_sizes', $output, true);
        return ['infos' => new PwgNamedArray($output, 'item')];
    }

    /** @param array<mixed> $params */
    public function caddieAdd(array $params, PwgServer &$service): int
    {
        $userId = CurrentUser::get()->id;
        $query  = 'SELECT id FROM ' . Tables::images() . ' LEFT JOIN ' . Tables::caddie() . ' ON id=element_id AND user_id=' . $userId . ' WHERE id IN (' . implode(',', array_map(fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, is_array($params['image_id']) ? $params['image_id'] : [])) . ') AND element_id IS NULL;';
        $result = array_column(DbConnection::get()->executeQuery($query)->fetchAllAssociative(), 'id');
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
            $query .= " AND anonymous_id='" . (is_scalar($params['anonymous_id']) ? (string) $params['anonymous_id'] : '') . "'";
        }
        if (!empty($params['image_id'])) {
            $query .= ' AND element_id=' . (is_numeric($params['image_id']) ? (int) $params['image_id'] : 0);
        }
        $changes = DbConnection::get()->executeStatement($query);
        if ($changes > 0) {
            ServiceLocator::get(RateService::class)->updateRatingScore();
        }
        return $changes;
    }

    /** @param array<mixed> $params */
    public function sessionLogin(array $params, PwgServer &$service): PwgError|true
    {
        if (defined('PWG_API_KEY_REQUEST')) {
            return new PwgError(401, 'Cannot use this method with an api key');
        }
        $username = is_scalar($params['username']) ? (string) $params['username'] : '';
        $password = is_scalar($params['password']) ? (string) $params['password'] : '';
        if (preg_match('/^pkid-\d{8}-[a-z0-9]{20}$/i', $username)) {
            if (AuthService::get()->authKeyLogin($username . ':' . $password)) {
                $_SESSION['connected_with'] = 'ws_session_login_api_key';
                return true;
            }
        } elseif (AuthService::get()->tryLogUser($username, $password, false)) {
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
        if (!PermissionService::get()->isAGuest()) {
            AuthService::get()->logoutUser();
        }
        return true;
    }

    public function sessionGetStatus(mixed $params, PwgServer &$service): mixed
    {
        $currentUser = CurrentUser::get();
        $res['username'] = PermissionService::get()->isAGuest() ? 'guest' : stripslashes($currentUser->username);
        $res['status']   = $currentUser->status;
        $res['theme']    = $currentUser->theme;
        $res['language'] = $currentUser->language;
        $res['pwg_token'] = ServiceLocator::get(Util::class)->getPwgToken();
        $res['charset']   = ServiceLocator::get(StringUtil::class)->getPwgCharset();
        $res['current_datetime'] = new \DateTimeImmutable()->format('Y-m-d H:i:s');
        $res['version']   = AppInfo::VERSION;
        $res['save_visits'] = ServiceLocator::get(Util::class)->doLog();
        $res['connected_with'] = $_SESSION['connected_with'] ?? null;
        $httpUserAgent = isset($_SERVER['HTTP_USER_AGENT']) && is_string($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '';
        if ($httpUserAgent !== '' && preg_match('/^PiwigoRemoteSync/', $httpUserAgent)) {
            unset($res['save_visits'], $res['connected_with']);
        }
        if ($httpUserAgent === '' || !str_starts_with($httpUserAgent, 'Apache-HttpClient/')) {
            $res['available_sizes'] = array_keys(ImageStdParams::getDefinedTypeMap());
        }
        if (PermissionService::get()->isAdmin()) {
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
            if (!empty($param[$datefield]) && !ServiceLocator::get(StringUtil::class)->isValidMysqlDatetime(is_scalar($param[$datefield]) ? (string) $param[$datefield] : '')) {
                return new PwgError(WS_ERR_INVALID_PARAM, 'Invalid ' . $datefield);
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
            $dateMinStr = is_scalar($param['date_min']) ? (string) $param['date_min'] : '';
            $dateMaxStr = isset($param['date_max']) && is_scalar($param['date_max']) ? (string) $param['date_max'] : '';
            $dmin       = date_create($dateMinStr);
            $dmax       = $dateMaxStr !== '' ? date_create($dateMaxStr) : false;
            $min        = $dmin !== false ? date_format($dmin, 'Y-m-d H:i:s') : '';
            $max        = $dmax !== false ? date_format($dmax, 'Y-m-d 23:59:59') : '';
        }
        if (!empty($param['date_max'])) {
            $dateMaxStr2 = is_scalar($param['date_max']) ? (string) $param['date_max'] : '';
            $dmax2       = date_create($dateMaxStr2);
            $max         = $dmax2 !== false ? date_format($dmax2, 'Y-m-d 23:59:59') : '';
        }
        $where = "WHERE object != 'system'";
        if (isset($param['uid'])) {
            $where .= ' AND performed_by=' . (is_numeric($param['uid']) ? (int) $param['uid'] : 0);
        }
        if (isset($param['action'])) {
            $where .= ' AND action=' . DbConnection::get()->quote(is_scalar($param['action']) ? (string) $param['action'] : '');
        }
        if (isset($param['object'])) {
            $where .= ' AND object=' . DbConnection::get()->quote(is_scalar($param['object']) ? (string) $param['object'] : '');
        }
        if (!empty($param['date_min'])) {
            $where .= ' AND occured_on >= "' . $min . '"';
        }
        if (!empty($param['date_max'])) {
            $where .= ' AND occured_on <= "' . $max . '"';
        }
        if (!empty($param['id'])) {
            $where .= ' AND object_id=' . (is_numeric($param['id']) ? (int) $param['id'] : 0);
        }
        if ('none' === Config::activityDisplayConnections()) {
            $where .= " AND action NOT IN ('login', 'logout')";
        } elseif ('admins_only' === Config::activityDisplayConnections()) {
            $where .= ' AND NOT (action IN (\'login\', \'logout\') AND object_id NOT IN (' . implode(',', ServiceLocator::get(UserAdminService::class)->getAdmins()) . '))';
        }
        $moreRowsAvailable = true;
        while (count($outputLines) < $pageSize && $moreRowsAvailable) {
            $query = 'SELECT activity_id, performed_by, object, object_id, action, session_idx, ip_address, occured_on, details, user_agent FROM ' . Tables::activity() . ' ' . $where . ' ORDER BY activity_id DESC LIMIT ' . $nbRowsToFetch . ' OFFSET ' . $pageOffset . ';';
            $rows  = DbConnection::get()->executeQuery($query)->fetchAllAssociative();
            if (count($rows) < $nbRowsToFetch) {
                $moreRowsAvailable = false;
            }
            foreach ($rows as $row) {
                if (count($outputLines) < $pageSize) {
                    $pageOffset++;
                    $rowSessionIdx = is_scalar($row['session_idx']) ? (string) $row['session_idx'] : '';
                    $rowObject     = is_scalar($row['object']) ? (string) $row['object'] : '';
                    $rowAction     = is_scalar($row['action']) ? (string) $row['action'] : '';
                    $lineKey = $rowSessionIdx . '~' . $rowObject . '~' . $rowAction . '~';
                    if ($lineKey === $currentKey) {
                        $outputLines[count($outputLines) - 1]['counter']++;
                        $outputLines[count($outputLines) - 1]['object_id'][] = $row['object_id'];
                    } else {
                        $rowDetailsStr = is_scalar($row['details']) ? (string) $row['details'] : '';
                        $rowDetailsStr = str_replace(['`groups`', '`rank`'], ['groups', 'rank'], $rowDetailsStr);
                        $details       = safe_unserialize($rowDetailsStr);
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
                        [$date, $hour]     = explode(' ', is_scalar($row['occured_on']) ? (string) $row['occured_on'] : '');
                        $rowPerformedBy    = $row['performed_by'];
                        $outputLines[]     = ['id' => $lineId, 'object' => $rowObject, 'object_id' => [$row['object_id']], 'action' => $rowAction, 'ip_address' => $row['ip_address'], 'date' => ServiceLocator::get(DateService::class)->formatDate($date), 'hour' => $hour, 'user_id' => $rowPerformedBy, 'detailsType' => $detailsType, 'details' => $details, 'counter' => 1];
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
            $usernameOf = array_column(DbConnection::get()->executeQuery($query)->fetchAllAssociative(), 'username', 'user_id');
        }
        foreach ($outputLines as $idx => $outputLine) {
            if ('user' === ($outputLine['object'] ?? '')) {
                $objIds = $outputLine['object_id'];
                foreach ($objIds as $uid) {
                    $uidKey = is_scalar($uid) ? (string) $uid : '';
                    /** @var array<string, mixed> $detArr */
                    $detArr = $outputLines[$idx]['details'] ?? [];
                    /** @var list<mixed> $detUsers */
                    $detUsers   = is_array($detArr['users'] ?? null) ? $detArr['users'] : [];
                    $detUsers[] = $usernameOf[$uidKey] ?? ('user#' . $uidKey);
                    $detArr['users'] = $detUsers;
                    $outputLines[$idx]['details'] = $detArr;
                }
                /** @var array<string, mixed> $detArr2 */
                $detArr2 = $outputLines[$idx]['details'];
                if (isset($detArr2['users'])) {
                    $usersArr = is_array($detArr2['users']) ? $detArr2['users'] : [];
                    $detArr2['users_string'] = implode(', ', array_map(fn (mixed $v): string => is_scalar($v) ? (string) $v : '', $usersArr));
                    $outputLines[$idx]['details'] = $detArr2;
                }
            }
            $lineUserId    = $outputLines[$idx]['user_id'] ?? '';
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
        $page = &$GLOBALS['page'];
        if (!is_array($page)) {
            $page = [];
        }
        if (!empty($params['section']) && in_array($params['section'], SchemaHelper::getEnums(Tables::history(), 'section'))) {
            $page['section'] = $params['section'];
        }
        if (!empty($params['cat_id'])) {
            $page['category'] = ['id' => $params['cat_id']];
        }
        $tagsString = is_scalar($params['tags_string']) ? (string) $params['tags_string'] : '';
        if ($tagsString !== '' && preg_match('/^\d+(,\d+)*$/', $tagsString)) {
            $page['tag_ids'] = explode(',', $tagsString);
        }
        $logImageId = is_numeric($params['image_id']) ? (int) $params['image_id'] : null;
        if (!empty($params['image_id']) && $logImageId !== null) {
            ServiceLocator::get(PictureService::class)->increaseImageVisitCounter($logImageId);
        }
        $imageType = $params['is_download'] ? 'high' : 'picture';
        ServiceLocator::get(Util::class)->pwgLog($logImageId, $imageType);
    }

    /**
     * @param array<mixed> $param
     * @return array<mixed>
     */
    public function historySearch(array $param, PwgServer &$service): array
    {
        $page = is_array($GLOBALS['page'] ?? null) ? $GLOBALS['page'] : [];
        if (isset($_GET['start']) && is_numeric($_GET['start'])) {
            $page['start'] = (int) $_GET['start'];
        } else {
            $page['start'] = 0;
        }
        $types = array_merge(['none'], SchemaHelper::getEnums(Tables::history(), 'image_type'));
        $displayThumbnails = ['no_display_thumbnail' => Lang::t('No display'), 'display_thumbnail_classic' => Lang::t('Classic display'), 'display_thumbnail_hoverbox' => Lang::t('Hoverbox display')];
        $page['errors'] = [];
        $search         = [];
        if (!empty($param['start'])) {
            ServiceLocator::get(Util::class)->checkInputParameter('start', $param, false, '/^\d{4}-\d{2}-\d{2}$/');
            $search['fields']['date-after'] = $param['start'];
        }
        if (!empty($param['end'])) {
            ServiceLocator::get(Util::class)->checkInputParameter('end', $param, false, '/^\d{4}-\d{2}-\d{2}$/');
            $search['fields']['date-before'] = $param['end'];
        }
        if (empty($param['types'])) {
            $search['fields']['types'] = $types;
        } else {
            ServiceLocator::get(Util::class)->checkInputParameter('types', $param, true, '/^(' . implode('|', $types) . ')$/');
            $search['fields']['types'] = $param['types'];
        }
        $search['fields']['user'] = is_numeric($param['user_id']) ? (int) $param['user_id'] : 0;
        if (!empty($param['image_id'])) {
            $search['fields']['image_id'] = is_numeric($param['image_id']) ? (int) $param['image_id'] : 0;
        }
        if (!empty($param['filename'])) {
            $search['fields']['filename'] = str_replace('*', '%', is_scalar($param['filename']) ? (string) $param['filename'] : '');
        }
        if (!empty($param['ip'])) {
            $search['fields']['ip'] = str_replace('*', '%', is_scalar($param['ip']) ? (string) $param['ip'] : '');
        }
        ServiceLocator::get(Util::class)->checkInputParameter('display_thumbnail', $param, false, '/^(' . implode('|', array_keys($displayThumbnails)) . ')$/');
        $search['fields']['display_thumbnail'] = $param['display_thumbnail'];
        $displayThumbnailStr = is_scalar($param['display_thumbnail']) ? (string) $param['display_thumbnail'] : '';
        $cookieVal           = ($displayThumbnailStr !== '' && isset($displayThumbnails[$displayThumbnailStr])) ? $displayThumbnailStr : null;
        ServiceLocator::get(CookieService::class)->setCookieVar('display_thumbnail', $cookieVal, strtotime('+1 month'));
        $searchRepo = ServiceLocator::get(SearchRepository::class);
        $searchId   = $searchRepo->insertSearch(serialize($search));
        $serializedRules = $searchRepo->findRulesById($searchId);
        $page['search'] = unserialize(is_string($serializedRules) ? $serializedRules : '');
        $search = is_array($page['search'] ?? null) ? $page['search'] : [];
        $data = ServiceLocator::get(HistoryAdminService::class)->getHistory([], $search, $types);
        usort($data, ServiceLocator::get(HistoryAdminService::class)->historyCompare(...));
        $page['nb_lines'] = count($data);
        $historyLines     = [];
        $userIds          = [];
        $usernameOf       = [];
        $categoryIds      = [];
        $imageIds         = [];
        $hasTags          = false;
        $searchIds        = [];
        foreach ($data as $row) {
            $rowUserId    = $row['user_id'] ?? null;
            $rowUserIdKey = is_scalar($rowUserId) ? (string) $rowUserId : '';
            if ($rowUserIdKey !== '') {
                $userIds[$rowUserIdKey] = 1;
            }
            if (isset($row['category_id'])) {
                array_push($categoryIds, $row['category_id']);
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
            if (isset($row['search_id'])) {
                array_push($searchIds, $row['search_id']);
            }
            $historyLines[] = $row;
        }
        $searchDetails = [];
        if (count($searchIds) > 0) {
            $searchIdsStr  = array_map(fn (mixed $v): string => is_scalar($v) ? (string) $v : '', $searchIds);
            $sdQuery       = 'SELECT id, rules FROM ' . Tables::search() . ' WHERE id IN (' . implode(',', $searchIdsStr) . ');';
            $searchDetails = array_column(DbConnection::get()->executeQuery($sdQuery)->fetchAllAssociative(), 'rules', 'id');
            foreach ($searchDetails as $idSearch => $rulesSearch) {
                $rulesArr    = safe_unserialize(is_scalar($rulesSearch) ? (string) $rulesSearch : '');
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
            $rawMap     = ServiceLocator::get(UserRepository::class)->findUsernamesByIds($userFields['id'], $userFields['username'], Tables::users(), array_map(intval(...), array_keys($userIds)));
            foreach ($rawMap as $id => $username) {
                $usernameOf[$id] = stripslashes($username);
            }
        }
        $nameOfCategory = [];
        $imageInfos     = [];
        $fullCatPath    = [];
        if (count($categoryIds) > 0) {
            $categoryIdsStr = array_map(fn (mixed $v): string => is_scalar($v) ? (string) $v : '', $categoryIds);
            $uppercatsOf    = array_column(DbConnection::get()->executeQuery('SELECT id, uppercats FROM ' . Tables::categories() . ' WHERE id IN (' . implode(',', $categoryIdsStr) . ');')->fetchAllAssociative(), 'uppercats', 'id');
            foreach ($uppercatsOf as $categoryId => $uppercats) {
                $uppercatsS           = is_scalar($uppercats) ? (string) $uppercats : '';
                $albumBase = ServiceLocator::get(UrlGenerator::class)->admin() . '&page=album-';
                $fullCatPath[$categoryId] = ServiceLocator::get(HtmlService::class)->getCatDisplayNameCache($uppercatsS, $albumBase);
                $uppercatsParts       = explode(',', $uppercatsS);
                $nameOfCategory[$categoryId] = ServiceLocator::get(HtmlService::class)->getCatDisplayNameCache(end($uppercatsParts) ?: '', $albumBase);
            }
        }
        if (count($imageIds) > 0) {
            $imageInfos = array_column(DbConnection::get()->executeQuery('SELECT id, IF(name IS NULL, file, name) AS label, filesize, file, path, representative_ext FROM ' . Tables::images() . ' WHERE id IN (' . implode(',', array_keys($imageIds)) . ');')->fetchAllAssociative(), null, 'id');
        }
        $nameOfTag = [];
        if ($hasTags) {
            foreach (ServiceLocator::get(TagRepository::class)->findAll() as $row) {
                $tagIdKey = is_scalar($row['id']) ? (string) $row['id'] : '';
                if ($tagIdKey !== '') {
                    $nameOfTag[$tagIdKey] = EventDispatcher::dispatch('render_tag_name', $row['name'], $row);
                }
            }
        }
        $i            = 0;
        $firstLine    = $page['start'] + 1;
        $lastLine     = $page['start'] + Config::nbLogsPage();
        $summary      = ['total_filesize' => 0, 'guests_IP' => []];
        $result       = [];
        $sortedMembers = [];
        foreach ($historyLines as $line) {
            $lineImageType  = is_scalar($line['image_type']) ? (string) $line['image_type'] : '';
            $lineImageId    = $line['image_id'] ?? null;
            $lineImageIdStr = is_scalar($lineImageId) ? (string) $lineImageId : '';
            $lineUserId     = $line['user_id'] ?? null;
            $lineUserIdStr  = is_scalar($lineUserId) ? (string) $lineUserId : '';
            $lineIP         = is_scalar($line['IP']) ? (string) $line['IP'] : '';
            $lineCatId      = $line['category_id'] ?? null;
            $lineCatIdStr   = is_scalar($lineCatId) ? (string) $lineCatId : '';
            $lineSearchId   = $line['search_id'] ?? null;
            $lineSearchIdStr = is_scalar($lineSearchId) ? (string) $lineSearchId : '';
            $lineSection    = is_scalar($line['section']) ? (string) $line['section'] : '';
            if ($lineImageType === 'high' && $lineImageIdStr !== '') {
                $summary['total_filesize'] += is_numeric($imageInfos[$lineImageIdStr]['filesize'] ?? null) ? (int) $imageInfos[$lineImageIdStr]['filesize'] : 0;
            }
            if ($lineUserIdStr === (string) Config::guestId()) {
                if (!isset($summary['guests_IP'][$lineIP])) {
                    $summary['guests_IP'][$lineIP] = 0;
                }
                $summary['guests_IP'][$lineIP]++;
            }
            $i++;
            if ($i <= $firstLine && $i >= $lastLine) {
                continue;
            }
            $userName   = '#unknown';
            $userString = '';
            if ($lineUserIdStr !== '' && isset($usernameOf[$lineUserIdStr])) {
                $userName    = $usernameOf[$lineUserIdStr];
                $userString .= $usernameOf[$lineUserIdStr];
            } else {
                $userString .= $lineUserIdStr;
            }
            $userString .= '&nbsp;<a href="' . ServiceLocator::get(UrlGenerator::class)->admin('history') . '&amp;search_id=' . $searchId . '&amp;user_id=' . $lineUserIdStr . '">+</a>';
            $tagNames = '';
            $tagIds   = '';
            if (isset($line['tag_ids'])) {
                $lineTagIds = is_scalar($line['tag_ids']) ? (string) $line['tag_ids'] : '';
                $tagNames   = preg_replace_callback('/(\d+)/', fn (array $m): string => is_scalar($nameOfTag[$m[1]] ?? null) ? (string) ($nameOfTag[$m[1]] ?? $m[1]) : (string) $m[1], $lineTagIds) ?? $lineTagIds;
                $tagIds     = $lineTagIds;
            }
            $imageString    = '';
            $imageTitle     = '';
            $imageEditString = '';
            $imageId        = '';
            if ($lineImageIdStr !== '') {
                $imageEditString = ServiceLocator::get(UrlGenerator::class)->admin('photo-' . $lineImageIdStr);
                $pictureUrl      = UrlService::get()->makePictureUrl(['image_id' => $lineImageId]);
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
                $searchDetail = ['allwords' => !empty($sdAllwordsWords) ? $sdAllwordsWords : null, 'tags' => !empty($sdTagsWords) ? array_intersect_key($nameOfTag, array_flip(array_map(fn (mixed $v): string => is_scalar($v) ? (string) $v : '', $sdTagsWords))) : null, 'date_posted' => !empty($sd['date_posted']) ? $sd['date_posted'] : null, 'cat' => !empty($sdCatWords) ? array_intersect_key($nameOfCategory, array_flip(array_map(fn (mixed $v): string => is_scalar($v) ? (string) $v : '', $sdCatWords))) : null, 'author' => !empty($sdAuthorWords) ? $sdAuthorWords : null, 'added_by' => !empty($sdAddedBy) ? array_intersect_key($usernameOf, array_flip(array_map(fn (mixed $v): string => is_scalar($v) ? (string) $v : '', $sdAddedBy))) : null, 'filetypes' => !empty($sd['filetypes']) ? $sd['filetypes'] : null];
            }
            $sortedMembers[$userName] = ($sortedMembers[$userName] ?? 0) + 1;
            $lineDate                 = is_scalar($line['date'] ?? null) ? $line['date'] : null;
            array_push($result, ['DATE' => ServiceLocator::get(DateService::class)->formatDate(is_string($lineDate) || is_int($lineDate) ? $lineDate : null), 'TIME' => $line['time'] ?? null, 'USER' => $userString, 'USERNAME' => $userName, 'USERID' => $lineUserId, 'IP' => $lineIP, 'IMAGE' => $imageString, 'IMAGENAME' => $imageTitle, 'IMAGEID' => $imageId, 'EDIT_IMAGE' => $imageEditString, 'TYPE' => $lineImageType, 'SECTION' => $lineSection, 'FULL_CATEGORY_PATH' => ($lineCatIdStr !== '' && isset($fullCatPath[$lineCatIdStr])) ? strip_tags((string) $fullCatPath[$lineCatIdStr]) : Lang::t('Root') . $lineCatIdStr, 'CATEGORY' => ($lineCatIdStr !== '' && isset($nameOfCategory[$lineCatIdStr])) ? $nameOfCategory[$lineCatIdStr] : Lang::t('Root') . $lineCatIdStr, 'SEARCH_ID' => $lineSearchId ?? null, 'TAGS' => explode(',', (string) $tagNames), 'TAGIDS' => explode(',', $tagIds), 'SEARCH_DETAILS' => $searchDetail]);
        }
        $maxPage = ceil(count($result) / 300);
        $result  = array_reverse($result, true);
        $pageNumber = is_numeric($param['pageNumber']) ? (int) $param['pageNumber'] : 0;
        $result     = array_slice($result, $pageNumber * 300, 300);
        $summary['nb_guests'] = 0;
        if (count(array_keys($summary['guests_IP'])) > 0) {
            $summary['nb_guests'] = count(array_keys($summary['guests_IP']));
            unset($usernameOf[(string) Config::guestId()]);
        }
        $summary['nb_members'] = count($usernameOf);
        $memberStrings         = [];
        foreach ($usernameOf as $userId => $userName) {
            $memberStrings[] = [$userName => $userId];
        }
        arsort($sortedMembers);
        unset($sortedMembers['guest']);
        $searchSummary = ['NB_LINES' => Translator::get()->plural('%d line filtered', '%d lines filtered', $page['nb_lines']), 'FILESIZE' => $summary['total_filesize'] != 0 ? ceil($summary['total_filesize'] / 1024) : 0, 'USERS' => Translator::get()->plural('%d user', '%d users', $summary['nb_members'] + $summary['nb_guests']), 'MEMBERS' => $memberStrings, 'SORTED_MEMBERS' => $sortedMembers, 'GUESTS' => Translator::get()->plural('%d guest', '%d guests', $summary['nb_guests'])];
        unset($nameOfTag);
        return ['lines' => $result, 'params' => $param, 'maxPage' => ($maxPage == 0) ? 1 : $maxPage, 'summary' => $searchSummary];
    }
}
