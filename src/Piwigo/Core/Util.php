<?php

declare(strict_types=1);

namespace Piwigo\Core;

use Piwigo\Theme\ThemeRepository;
use Piwigo\Users\UserRepository;
use Piwigo\Db\SqlExpr;
use Piwigo\Db\SchemaHelper;
use Piwigo\History\HistoryRepository;
use Piwigo\Db\DbInfo;
use Doctrine\DBAL\Connection;
use Piwigo\Admin\AdminService;
use Piwigo\Admin\Plugins;
use Piwigo\Admin\Themes;
use Piwigo\Cache\RequestCache;
use Piwigo\Config\Config;
use Piwigo\Image\ImageStdParams;
use Piwigo\Template\Template;
use Piwigo\Template\TemplateRegistry;
use Piwigo\Users\CurrentUser;
use Psr\Log\LoggerInterface;

final readonly class Util
{
    public function __construct(
        private Connection $conn,
        private LoggerInterface $log,
    ) {
    }

    public function mkgetdir(string $dir, int $flags = MKGETDIR_DEFAULT): bool
    {
        if (!is_dir($dir)) {
            if (str_starts_with(PHP_OS, 'WIN')) {
                $dir = str_replace('/', DIRECTORY_SEPARATOR, $dir);
            }
            $umask = umask(0);
            set_error_handler(static fn (): bool => true);
            try {
                $mkd = mkdir($dir, Config::chmodValue(), ($flags & MKGETDIR_RECURSIVE) ? true : false);
            } finally {
                restore_error_handler();
            }
            umask($umask);
            if ($mkd == false) {
                if (!($flags & MKGETDIR_DIE_ON_ERROR)) {
                    return false;
                }
                fatal_error("$dir " . l10n('no write access'));
            }
            if ($flags & MKGETDIR_PROTECT_HTACCESS) {
                $file = $dir . '/.htaccess';
                if (!file_exists($file) && is_writable($dir)) {
                    file_put_contents($file, 'deny from all');
                }
            }
            if ($flags & MKGETDIR_PROTECT_INDEX) {
                $file = $dir . '/index.htm';
                if (!file_exists($file) && is_writable($dir)) {
                    file_put_contents($file, 'Not allowed!');
                }
            }
        }
        if (!is_writable($dir)) {
            if (!($flags & MKGETDIR_DIE_ON_ERROR)) {
                return false;
            }
            fatal_error("$dir " . l10n('no write access'));
        }
        return true;
    }

    public function pwgDebug(string $string): void
    {
        $t2 = is_numeric($GLOBALS['t2'] ?? null) ? (float) $GLOBALS['t2'] : 0.0;
        $now = explode(' ', microtime());
        $now2 = explode('.', $now[0]);
        $now2Float = (float) ($now[1] . '.' . $now2[1]);
        $time = number_format($now2Float - $t2, 3, '.', ' ') . ' s';
        if (!isset($GLOBALS['debug']) || !is_string($GLOBALS['debug'])) {
            $GLOBALS['debug'] = '';
        }
        $GLOBALS['debug'] .= '<p>';
        $GLOBALS['debug'] .= '[' . $time . ', ';
        $GLOBALS['debug'] .= PageState::current()->countQueries . ' queries] : ' . $string;
        $GLOBALS['debug'] .= "</p>\n";
    }

    public function redirectHttp(string $url): void
    {
        if (ob_get_length() !== false) {
            ob_clean();
        }
        $url = html_entity_decode($url);
        header('Request-URI: ' . $url);
        header('Content-Location: ' . $url);
        header('Location: ' . $url);
        exit();
    }

    public function redirectHtml(string $url, string $msg = '', int $refreshTime = 0): void
    {
        if (!LanguageStack::initialized() || !TemplateRegistry::isInitialized()) {
            CurrentUser::setRawAttributes(build_user(Config::guestId(), true));
            load_language('common.lang');
            trigger_notify('loading_lang');
            load_language('lang', PHPWG_ROOT_PATH . PWG_LOCAL_DIR, ['no_fallback' => true, 'local' => true]);
            $tpl = new Template(PHPWG_ROOT_PATH . 'themes', get_default_theme());
            TemplateRegistry::set($tpl);
        } elseif (defined('IN_ADMIN') && IN_ADMIN) {
            $tpl = new Template(PHPWG_ROOT_PATH . 'themes', get_default_theme());
            TemplateRegistry::set($tpl);
        }

        if (empty($msg)) {
            $msg = nl2br(l10n('Redirection...'));
        }

        $refresh  = $refreshTime;
        $url_link = $url;
        $title    = 'redirection';

        $tpl = TemplateRegistry::current();
        $tpl->set_filenames(['redirect' => 'redirect.tpl']);

        include PHPWG_ROOT_PATH . 'include/page_header.php';

        $tpl->set_filenames(['redirect' => 'redirect.tpl']);
        $tpl->assign('REDIRECT_MSG', $msg);
        $tpl->parse('redirect');

        include PHPWG_ROOT_PATH . 'include/page_tail.php';
        exit();
    }

    public function redirect(string $url, string $msg = '', int $refreshTime = 0): void
    {
        if (Config::defaultRedirectMethod() === 'http' && $refreshTime === 0 && !headers_sent()) {
            $this->redirectHttp($url);
        } else {
            $this->redirectHtml($url, $msg, $refreshTime);
        }
    }

    /** @return array<string,string> */
    public function getPwgThemes(bool $showMobile = false): array
    {
        $themes = [];
        if (ServiceLocator::has(ThemeRepository::class)) {
            $rows = ServiceLocator::get(ThemeRepository::class)->findAll();
        } else {
            $rows = $this->conn->executeQuery('SELECT id, name FROM ' . THEMES_TABLE . ' ORDER BY name ASC;')->fetchAllAssociative();
        }
        foreach ($rows as $row) {
            if ($row['id'] === Config::mobilTheme()) {
                if (!$showMobile) {
                    continue;
                }
                $row['name'] = (is_scalar($row['name']) ? (string) $row['name'] : '') . (' (' . l10n('Mobile') . ')');
            }
            $themeId = is_scalar($row['id']) ? (string) $row['id'] : '';
            if ($this->checkThemeInstalled($themeId)) {
                $themes[$themeId] = is_scalar($row['name']) ? (string) $row['name'] : '';
            }
        }
        $themes = trigger_change('get_pwg_themes', $themes);
        return $themes;
    }

    public function checkThemeInstalled(string $themeId): bool
    {
        return file_exists(Config::themesDir() . '/' . $themeId . '/themeconf.inc.php');
    }

    public function getThemeconf(string $key): mixed
    {
        /** @var Template $template */
        $template = $GLOBALS['template'];
        return $template->get_themeconf($key);
    }

    public function getFilterPageValue(string $valueName): mixed
    {
        $pageName = script_basename();
        /** @var array<string, array<string, mixed>> $filterPages */
        $filterPages = Config::filterPages();
        return $filterPages[$pageName][$valueName] ?? $filterPages['default'][$valueName] ?? null;
    }

    public function getWebmasterMailAddress(): string
    {
        $userFields = Config::userFields();
        $email = ServiceLocator::get(UserRepository::class)
            ->getWebmasterEmail(
                $userFields['email'],
                $userFields['id'],
                USERS_TABLE,
                Config::webmasterId()
            );
        $email = trigger_change('get_webmaster_mail_address', $email);
        return (string) $email;
    }

    /** @param array<int|string> $elementsId */
    public function fillCaddie(array $elementsId): void
    {
        $userId = CurrentUser::get()->id;
        $query  = 'SELECT element_id FROM ' . CADDIE_TABLE . ' WHERE user_id = ' . $userId . ';';
        $inCaddie = array_column(get_dbal_connection()->executeQuery($query)->fetchAllAssociative(), 'element_id');
        $caddiables = array_diff($elementsId, array_map(static fn (mixed $v): string => is_scalar($v) ? (string) $v : '0', $inCaddie));
        $datas = [];
        foreach ($caddiables as $caddiable) {
            $datas[] = ['element_id' => $caddiable, 'user_id' => $userId];
        }
        if (count($caddiables) > 0) {
            mass_inserts(CADDIE_TABLE, ['element_id', 'user_id'], $datas);
        }
    }

    public function getNbAvailableComments(): int
    {
        $cached = RequestCache::remember('user', 'nb_available_comments', function (): int {
            $where = [];
            if (!is_admin()) {
                $where[] = "validated='true'";
            }
            $where[] = get_sql_condition_FandF(
                ['forbidden_categories' => 'category_id', 'forbidden_images' => 'ic.image_id'],
                '',
                true
            );
            $query = 'SELECT COUNT(DISTINCT(com.id)) FROM ' . IMAGE_CATEGORY_TABLE . ' AS ic'
                . ' INNER JOIN ' . COMMENTS_TABLE . ' AS com ON ic.image_id = com.image_id'
                . ' WHERE ' . implode(' AND ', $where);
            $count = $this->conn->executeQuery($query)->fetchOne();
            $nb    = is_numeric($count) ? (int) $count : 0;
            single_update(USER_CACHE_TABLE, ['nb_available_comments' => $nb], ['user_id' => CurrentUser::get()->id]);
            return $nb;
        });
        return is_int($cached) ? $cached : 0;
    }

    public function checkPwgToken(): void
    {
        if (!empty($_REQUEST['pwg_token'])) {
            if ($this->getPwgToken() !== $_REQUEST['pwg_token']) {
                access_denied();
            }
        } else {
            bad_request('missing token');
        }
    }

    public function getPwgToken(): string
    {
        return hash_hmac('md5', (string) session_id(), (string) Config::secretKey());
    }

    /** @param array<mixed> $paramArray */
    public function checkInputParameter(string $paramName, array $paramArray, bool $isArray, ?string $pattern, bool $mandatory = false): bool
    {
        $paramValue = null;
        if (isset($paramArray[$paramName])) {
            $paramValue = $paramArray[$paramName];
        }
        if (empty($paramValue)) {
            if ($mandatory) {
                fatal_error('[Hacking attempt] the input parameter "' . $paramName . '" is not valid');
            }
            return true;
        }
        if ($isArray) {
            if (!is_array($paramValue)) {
                fatal_error('[Hacking attempt] the input parameter "' . $paramName . '" should be an array');
            }
            foreach ($paramValue as $key => $itemToCheck) {
                if (!preg_match(PATTERN_ID, (string) $key) || !preg_match($pattern ?? '', is_scalar($itemToCheck) ? (string) $itemToCheck : '')) {
                    fatal_error('[Hacking attempt] an item is not valid in input parameter "' . $paramName . '"');
                }
            }
            return true;
        }
        if (!preg_match($pattern ?? '', is_scalar($paramValue) ? (string) $paramValue : '')) {
            fatal_error('[Hacking attempt] the input parameter "' . $paramName . '" is not valid');
        }
        return true;
    }

    /** @return string[] */
    public function getPrivacyLevelOptions(): array
    {
        $options = [];
        $label   = '';
        foreach (array_reverse(Config::availablePermissionLevels()) as $level) {
            if ($level === 0) {
                $label = l10n('Everybody');
            } else {
                if (strlen($label)) {
                    $label .= ', ';
                }
                $label .= l10n(sprintf('Level %d', $level));
            }
            $options[$level] = $label;
        }
        return $options;
    }

    /** @return array<mixed>|false */
    public function getIcon(?string $date, bool $isChildDate = false): array|false
    {
        if (empty($date)) {
            return false;
        }
        $raw         = CurrentUser::get()->rawAttributes;
        $recentPeriod = is_scalar($raw['recent_period'] ?? null) ? (int) $raw['recent_period'] : 7;
        $title = RequestCache::remember('get_icon', 'title', static fn (): string => l10n(
            'photos posted during the last %d days',
            $recentPeriod
        ));
        $icon = ['TITLE' => $title, 'IS_CHILD_DATE' => $isChildDate];
        if (RequestCache::has('get_icon', $date)) {
            return RequestCache::get('get_icon', $date) ? $icon : [];
        }
        $sqlRecentDate = RequestCache::remember(
            'get_icon',
            'sql_recent_date',
            static function () use ($recentPeriod): string {
                $v = get_dbal_connection()->executeQuery('SELECT ' . SqlExpr::recentPeriodExpr((string) $recentPeriod))->fetchOne();
                return is_scalar($v) ? (string) $v : '';
            }
        );
        $isRecent = $date > $sqlRecentDate;
        RequestCache::set('get_icon', $date, $isRecent);
        return $isRecent ? $icon : [];
    }

    public function getEphemeralKey(int $validAfterSeconds, string $additionalData = ''): string
    {
        $time       = round(microtime(true), 1);
        $remoteAddr = is_scalar($_SERVER['REMOTE_ADDR'] ?? '') ? (string) ($_SERVER['REMOTE_ADDR'] ?? '') : '';
        return $time . ':' . $validAfterSeconds . ':'
            . hash_hmac(
                'md5',
                $time . substr($remoteAddr, 0, 5) . $validAfterSeconds . $additionalData,
                (string) Config::secretKey()
            );
    }

    public function verifyEphemeralKey(string $key, string $additionalData = ''): bool
    {
        $time       = microtime(true);
        $key        = explode(':', $key);
        $remoteAddr = is_scalar($_SERVER['REMOTE_ADDR'] ?? '') ? (string) ($_SERVER['REMOTE_ADDR'] ?? '') : '';
        if (count($key) !== 3
            || $key[0] > $time - (float) $key[1]
            || $key[0] < $time - 3600
            || hash_hmac('md5', $key[0] . substr($remoteAddr, 0, 5) . $key[1] . $additionalData, (string) Config::secretKey()) !== $key[2]
        ) {
            return false;
        }
        return true;
    }

    /** @return array<mixed> */
    public function createNavigationBar(string $url, int $nbElement, int $start, int $nbElementPage, bool $cleanUrl = false, string $paramName = 'start'): array
    {
        $navbar      = [];
        $pagesAround = Config::paginatePagesAround();
        $startStr    = $cleanUrl ? '/' . $paramName . '-' : (!str_contains($url, '?') ? '?' : '&amp;') . $paramName . '=';

        if ($start < 0) {
            $start = 0;
        }

        if ($nbElement > $nbElementPage) {
            $urlStart = $url . $startStr;
            $curPage  = $navbar['CURRENT_PAGE'] = $start / $nbElementPage + 1;
            $maximum  = ceil($nbElement / $nbElementPage);
            $start    = $nbElementPage * round($start / $nbElementPage);
            $previous = $start - $nbElementPage;
            $next     = $start + $nbElementPage;
            $last     = ($maximum - 1) * $nbElementPage;

            if ($curPage != 1) {
                $navbar['URL_FIRST'] = $url;
                $navbar['URL_PREV']  = $previous > 0 ? $urlStart . $previous : $url;
            }
            if ($curPage != $maximum) {
                $navbar['URL_NEXT'] = $urlStart . ($next < $last ? $next : $last);
                $navbar['URL_LAST'] = $urlStart . $last;
            }

            $navbar['pages']    = [];
            $navbar['pages'][1] = $url;
            for ($i = (int) max(floor($curPage) - $pagesAround, 2), $stop = (int) min(ceil($curPage) + $pagesAround + 1, $maximum); $i < $stop; $i++) {
                $navbar['pages'][$i] = $url . $startStr . (($i - 1) * $nbElementPage);
            }
            $navbar['pages'][(int) $maximum] = $urlStart . $last;
            $navbar['NB_PAGE']               = $maximum;
        }
        return $navbar;
    }

    public function getDevice(): string
    {
        $device = pwg_get_session_var('device', '');
        if ($device === '') {
            $uagentObj = new \uagent_info();
            if ($uagentObj->DetectSmartphone()) {
                $device = 'mobile';
            } elseif ($uagentObj->DetectTierTablet()) {
                $device = 'tablet';
            } else {
                $device = 'desktop';
            }
            pwg_set_session_var('device', $device);
        }
        return $device;
    }

    public function mobileTheme(): bool
    {
        if (empty(Config::mobilTheme())) {
            return false;
        }
        if (isset($_GET['mobile'])) {
            $isMobileTheme = BoolUtil::fromMixed($_GET['mobile']);
            pwg_set_session_var('mobile_theme', $isMobileTheme);
        } else {
            $isMobileTheme = pwg_get_session_var('mobile_theme');
        }
        if (is_null($isMobileTheme)) {
            $isMobileTheme = ($this->getDevice() === 'mobile');
            pwg_set_session_var('mobile_theme', $isMobileTheme);
        }
        return (bool) $isMobileTheme;
    }

    public function doLog(mixed $imageId = null, mixed $imageType = null): bool
    {
        $doLog = Config::logConf();
        if (is_admin()) {
            $doLog = Config::historyAdmin();
        }
        if (is_a_guest()) {
            $doLog = Config::historyGuest();
        }
        return (bool) trigger_change('pwg_log_allowed', $doLog, $imageId, $imageType);
    }

    public function pwgLog(int|string|null $imageId = null, ?string $imageType = null, ?string $formatId = null): bool
    {
        $user = is_array($GLOBALS['user'] ?? null) ? $GLOBALS['user'] : [];
        $page = is_array($GLOBALS['page'] ?? null) ? $GLOBALS['page'] : [];

        if ($imageId !== null) {
            $imageId = (int) $imageId;
        }

        $userId    = CurrentUser::get()->id;
        $lastVisit = is_scalar($user['last_visit'] ?? null) ? (string) $user['last_visit'] : '';
        $updateLastVisit = empty($lastVisit) || strtotime($lastVisit) < time() - Config::sessionLength();
        $updateLastVisit = trigger_change('pwg_log_update_last_visit', $updateLastVisit);

        if ($updateLastVisit) {
            ServiceLocator::get(UserRepository::class)->updateLastVisit($userId);
        }

        if (!$this->doLog($imageId, $imageType)) {
            return false;
        }

        $tagsString  = null;
        $pageSection = is_scalar($page['section'] ?? null) ? (string) $page['section'] : '';
        if ($pageSection === 'tags') {
            $tagIds     = is_array($page['tag_ids'] ?? null) ? $page['tag_ids'] : [];
            $tagsString = implode(',', array_map(static fn (mixed $v): string => is_scalar($v) ? (string) $v : '', $tagIds));
            if (strlen($tagsString) > 50) {
                $tagsString  = substr($tagsString, 0, 50);
                $commaPos    = strrpos($tagsString, ',');
                if ($commaPos !== false) {
                    $tagsString = substr($tagsString, 0, $commaPos);
                }
            }
        }

        $ipRaw = $_SERVER['REMOTE_ADDR'];
        $ip    = is_scalar($ipRaw) ? (string) $ipRaw : '';
        if (strlen($ip) > 39) {
            $ip = substr($ip, 0, 39);
        }

        if ($pageSection !== '') {
            if (!Config::has('history_sections_cache')) {
                conf_update_param('history_sections_cache', SchemaHelper::getEnums(HISTORY_TABLE, 'section'), true);
            }
            $historySectionsCache = safe_unserialize(Config::historySectionsCache() ?? '');
            Config::override('history_sections_cache', $historySectionsCache);
            if (
                in_array($pageSection, $historySectionsCache)
                || in_array(strtolower($pageSection), array_map(static fn (mixed $s): string => strtolower(is_scalar($s) ? (string) $s : ''), $historySectionsCache))
            ) {
                $section = $pageSection;
            } elseif (preg_match('/^[a-zA-Z0-9_-]+$/', $pageSection)) {
                $historySections = SchemaHelper::getEnums(HISTORY_TABLE, 'section');
                $historySections[] = $pageSection;
                $this->conn->executeStatement(
                    'ALTER TABLE ' . HISTORY_TABLE . " CHANGE section section enum('" .
                    implode("','", array_unique($historySections)) . "') DEFAULT NULL"
                );
                conf_update_param('history_sections_cache', SchemaHelper::getEnums(HISTORY_TABLE, 'section'), true);
                $section = $pageSection;
            }
        }

        $category   = is_array($page['category'] ?? null) ? $page['category'] : null;
        $categoryId = $category !== null && is_scalar($category['id'] ?? null) ? (string) $category['id'] : 'NULL';
        $searchId   = is_scalar($page['search_id'] ?? null) ? (string) $page['search_id'] : 'NULL';
        $authKeyId  = is_scalar($page['auth_key_id'] ?? null) ? (string) $page['auth_key_id'] : 'NULL';

        $historyId = ServiceLocator::get(HistoryRepository::class)->insertLog(
            $userId,
            $ip,
            $section ?? null,
            $categoryId !== 'NULL' ? $categoryId : null,
            $searchId !== 'NULL' ? $searchId : null,
            $imageId,
            $imageType ?? null,
            $formatId,
            $authKeyId !== 'NULL' ? $authKeyId : null,
            $tagsString ?? null
        );
        if ($historyId % 1000 === 0) {
            include_once PHPWG_ROOT_PATH . 'admin/include/functions_history.inc.php';
            history_summarize(50000);
        }
        if (Config::historyAutopurgeEvery() > 0 && $historyId % Config::historyAutopurgeEvery() === 0) {
            include_once PHPWG_ROOT_PATH . 'admin/include/functions_history.inc.php';
            history_autopurge();
        }
        return true;
    }

    /**
     * @param int[]|int|string $objectId
     * @param array<mixed> $details
     */
    public function pwgActivity(string $object, array|int|string $objectId, string $action, array $details = []): void
    {
        if (isset($_REQUEST['method']) && $_REQUEST['method'] === 'pwg.images.uploadAsync' && $action === 'login') {
            return;
        }
        if (isset($_REQUEST['method']) && $_REQUEST['method'] === 'pwg.plugins.performAction' && $_REQUEST['action'] !== $action) {
            return;
        }

        $objectIds = is_array($objectId) ? $objectId : [$objectId];

        if (isset($_REQUEST['method'])) {
            $details['method'] = $_REQUEST['method'];
        } else {
            $details['script'] = script_basename();
            if ($details['script'] === 'admin' && isset($_GET['page'])) {
                $details['script'] .= '/' . (is_scalar($_GET['page']) ? (string) $_GET['page'] : '');
            }
        }

        if ($action === 'autoupdate') {
            unset($details['method']);
            unset($details['script']);
        }

        $userAgent = null;
        if ($object === 'user' && $action === 'login' && isset($_SERVER['HTTP_USER_AGENT'])) {
            $userAgent = strip_tags(is_scalar($_SERVER['HTTP_USER_AGENT']) ? (string) $_SERVER['HTTP_USER_AGENT'] : '');
        }
        if (isset($_SESSION['connected_with']) && $_SESSION['connected_with'] === 'api_key' && isset($_SERVER['HTTP_USER_AGENT'])) {
            $details['connected_with'] = 'api_key';
            $userAgent = strip_tags(is_scalar($_SERVER['HTTP_USER_AGENT']) ? (string) $_SERVER['HTTP_USER_AGENT'] : '');
        }
        if ($object === 'user' && $action === 'login') {
            if (function_exists('debug_backtrace')) {
                $calledFunctions = array_flip(array_column(debug_backtrace(), 'function'));
                foreach (['auto_login', 'auth_key_login'] as $authFunction) {
                    if (isset($calledFunctions[$authFunction])) {
                        $details['auth_function'] = $authFunction;
                    }
                }
            }
        }
        if ($object === 'photo' && $action === 'add' && !isset($details['sync'])) {
            $details['added_with'] = 'app';
            if (isset($_SERVER['HTTP_REFERER']) && preg_match('/page=photos_add/', is_scalar($_SERVER['HTTP_REFERER']) ? (string) $_SERVER['HTTP_REFERER'] : '')) {
                $details['added_with'] = 'browser';
            }
        }
        if (in_array($object, ['album', 'photo']) && $action === 'delete' && isset($_GET['page']) && $_GET['page'] === 'site_update') {
            $details['sync'] = true;
        }
        if ($object === 'tag' && $action === 'delete' && isset($_POST['destination_tag'])) {
            $details['action']          = 'merge';
            $details['destination_tag'] = $_POST['destination_tag'];
        }

        $inserts        = [];
        $detailsInsert  = serialize($details);
        $ipAddress      = $_SERVER['REMOTE_ADDR'] ?? null;
        $sessionId      = !empty(session_id()) ? session_id() : 'none';

        foreach ($objectIds as $loopObjectId) {
            $performedBy = CurrentUser::isInitialized() ? CurrentUser::get()->id : 0;
            if ($action === 'logout') {
                $performedBy = $loopObjectId;
            }
            $inserts[] = [
                'object'      => $object,
                'object_id'   => $loopObjectId,
                'action'      => $action,
                'performed_by' => $performedBy,
                'session_idx' => $sessionId,
                'ip_address'  => $ipAddress,
                'details'     => $detailsInsert,
                'user_agent'  => $userAgent ?? '',
            ];
        }
        mass_inserts(ACTIVITY_TABLE, array_keys($inserts[0]), $inserts);
    }

    public function checkLounge(): void
    {
        if (!Config::has('lounge_active') || !Config::loungeActive()) {
            return;
        }
        if (isset($_REQUEST['method']) && in_array($_REQUEST['method'], ['pwg.images.upload', 'pwg.images.uploadAsync'])) {
            return;
        }
        $query   = 'SELECT image_id, date_available, NOW() AS dbnow FROM ' . LOUNGE_TABLE . ' JOIN ' . IMAGES_TABLE . ' ON image_id = id ORDER BY image_id ASC LIMIT 1;';
        $voyagers = get_dbal_connection()->executeQuery($query)->fetchAllAssociative();
        if (count($voyagers)) {
            $voyager = $voyagers[0];
            $age = strtotime(is_scalar($voyager['dbnow']) ? (string) $voyager['dbnow'] : '') - strtotime(is_scalar($voyager['date_available']) ? (string) $voyager['date_available'] : '');
            if ($age > Config::loungeMaxDuration()) {
                include_once PHPWG_ROOT_PATH . 'admin/include/functions.php';
                empty_lounge();
            }
        }
    }

    public function pwgUniqueExecBegins(string $tokenName, int $timeout = 60): false|string
    {
        $execId = substr(sha1(random_bytes(1000)), 0, 8);
        $this->log->info('[' . $tokenName . '][exec=' . $execId . '] starts now');

        if (Config::has($tokenName . '_running')) {
            $runningRaw = Config::raw($tokenName . '_running');
            [$runningExecId, $runningExecStartTime] = explode('-', is_scalar($runningRaw) ? (string) $runningRaw : '-');
            if (time() - (int) $runningExecStartTime > $timeout) {
                $this->log->info('[' . $tokenName . '][exec=' . $execId . '] exec=' . $runningExecId . ', timeout stopped by another call');
                $this->pwgUniqueExecEnds($tokenName);
            }
        }

        $this->conn->executeStatement('INSERT IGNORE INTO ' . CONFIG_TABLE . ' SET param=?, value=?', [$tokenName . '_running', $execId . '-' . time()]);
        $runningExec = $this->conn->executeQuery('SELECT value FROM ' . CONFIG_TABLE . ' WHERE param = ?', [$tokenName . '_running'])->fetchOne();
        [$runningExecId] = explode('-', is_scalar($runningExec) ? (string) $runningExec : '');

        if ($runningExecId !== $execId) {
            $this->log->info('[' . $tokenName . '][exec=' . $execId . '] skip');
            return false;
        }
        $this->log->info('[' . $tokenName . '][exec=' . $execId . '] wins the race and gets the token!');
        return $execId;
    }

    public function pwgUniqueExecIsRunning(string $tokenName): bool
    {
        $counter = $this->conn->executeQuery('SELECT COUNT(*) FROM ' . CONFIG_TABLE . ' WHERE param = ?', [$tokenName . '_running'])->fetchOne();
        return is_numeric($counter) ? (int) $counter > 0 : false;
    }

    public function pwgUniqueExecEnds(string $tokenName): void
    {
        conf_delete_param($tokenName . '_running');
        $this->log->info('[' . $tokenName . '] ends now');
    }

    public function sendPiwigoInfosRetryLater(int $waitTime): void
    {
        $lastNotice = Config::has('send_piwigo_infos_last_notice') ? strtotime(Config::sendPiwigoInfosLastNotice() ?? '') : time();
        $lastNotice += $waitTime;
        conf_update_param('send_piwigo_infos_last_notice', date('c', $lastNotice), true);
        $this->log->info('[sendPiwigoInfosRetryLater] new send_piwigo_infos_last_notice=' . Config::sendPiwigoInfosLastNotice());
    }

    public function sendPiwigoInfos(): void
    {
        $startTime = get_moment();

        if (!Config::sendPiwigoInfos()) {
            return;
        }

        load_conf_from_db('param = "send_piwigo_infos_last_notice"', false);

        $doSend = false;
        if (Config::has('send_piwigo_infos_last_notice')) {
            $period = conf_get_param('send_piwigo_infos_period', 7 * 24 * 60 * 60);
            if (strtotime(Config::sendPiwigoInfosLastNotice() ?? '') < strtotime((is_scalar($period) ? (string) $period : '604800') . ' second ago')) {
                $doSend = true;
            }
        } else {
            $doSend = true;
        }

        if (!$doSend) {
            return;
        }

        $this->log->info('[sendPiwigoInfos] last_notice=' . (Config::sendPiwigoInfosLastNotice() ?? 'notFound') . ' => lets do it');

        if (!pwg_is_dbconf_writeable()) {
            $this->log->info('[sendPiwigoInfos] conf is not writeable, abort');
            return;
        }

        $execId = $this->pwgUniqueExecBegins('send_piwigo_infos');
        if ($execId === false) {
            $this->log->info('[sendPiwigoInfos] another execution is running, abort');
            return;
        }

        include_once PHPWG_ROOT_PATH . 'admin/include/functions.php';

        $dbCurrentDate = new \DateTimeImmutable()->format('Y-m-d H:i:s');

        if (!Config::has('send_piwigo_infos_origin_hash')) {
            conf_update_param('send_piwigo_infos_origin_hash', sha1(random_bytes(1000)), true);
        }

        [$containerType, $containerVersion] = get_container_info();

        $piwigoInfos = [
            'origin_hash' => Config::sendPiwigoInfosOriginHash(),
            'technical'   => [
                'php_version'       => PHP_VERSION,
                'piwigo_version'    => PHPWG_VERSION,
                'os_version'        => PHP_OS,
                'container_type'    => $containerType,
                'container_version' => $containerVersion,
                'db_version'        => DbInfo::version(),
                'php_datetime'      => date('Y-m-d H:i:s'),
                'db_datetime'       => $dbCurrentDate,
                'graphics_library'  => get_graphics_library(),
            ],
            'general_stats' => get_pwg_general_statitics(),
        ];

        $du = $piwigoInfos['general_stats']['disk_usage'] ?? 0;
        $piwigoInfos['general_stats']['disk_usage']        = intval((is_numeric($du) ? $du : 0) / 1024);
        $piwigoInfos['general_stats']['installed_on']      = get_installation_date();
        $piwigoInfos['general_stats']['nb_photos_synced']  = 0;
        $piwigoInfos['general_stats']['last_photo_synced'] = null;
        $piwigoInfos['general_stats']['last_photo']        = null;

        if ($piwigoInfos['general_stats']['nb_photos'] > 0) {
            $query = 'SELECT COUNT(*) AS counter FROM `' . IMAGES_TABLE . '` WHERE storage_category_id IS NOT NULL;';
            if (array_column(get_dbal_connection()->executeQuery($query)->fetchAllAssociative(), 'counter')[0] > 0) {
                $query = 'SELECT IF(storage_category_id IS NULL, \'api\', \'sync\') AS add_method, MAX(date_available) AS last_added_on, COUNT(*) AS nb_files FROM `' . IMAGES_TABLE . '` GROUP BY add_method;';
                $filesByMethod = array_column(get_dbal_connection()->executeQuery($query)->fetchAllAssociative(), null, 'add_method');
                $piwigoInfos['general_stats']['nb_photos_synced']  = $filesByMethod['sync']['nb_files'];
                $piwigoInfos['general_stats']['last_photo_synced'] = $filesByMethod['sync']['last_added_on'];
                $methodOfLastPhoto = 'sync';
                if (isset($filesByMethod['api']) && strtotime(is_scalar($filesByMethod['api']['last_added_on']) ? (string) $filesByMethod['api']['last_added_on'] : '') > strtotime(is_scalar($filesByMethod['sync']['last_added_on']) ? (string) $filesByMethod['sync']['last_added_on'] : '')) {
                    $methodOfLastPhoto = 'api';
                }
                $piwigoInfos['general_stats']['last_photo'] = $filesByMethod[$methodOfLastPhoto]['last_added_on'];
            } else {
                $query  = 'SELECT date_available FROM `' . IMAGES_TABLE . '` ORDER BY id DESC LIMIT 1;';
                $images = get_dbal_connection()->executeQuery($query)->fetchAllAssociative();
                if (count($images) > 0) {
                    $piwigoInfos['general_stats']['last_photo'] = $images[0]['date_available'];
                }
            }

            $query = 'SELECT SUBSTRING_INDEX(path,".",-1) AS ext, COUNT(*) AS counter, SUM(filesize) AS filesize FROM `' . IMAGES_TABLE . '` GROUP BY ext;';
            $piwigoInfos['file_extensions'] = array_column(get_dbal_connection()->executeQuery($query)->fetchAllAssociative(), null, 'ext');
        }

        $url         = PEM_URL . '/api/get_extension_list.php';
        $pemExtensions = ServiceLocator::get(AdminService::class)->fetchRemote($url, $result) ? safe_unserialize($result) : [];

        if ($pemExtensions !== []) {
            $officialExts = [];
            foreach ($pemExtensions as $eid => $ext) {
                if (is_array($ext) && !empty($ext['archive_root_dir'])) {
                    $idxCat     = $ext['idx_category'] ?? null;
                    $archiveDir = $ext['archive_root_dir'];
                    if (is_string($idxCat) || is_int($idxCat)) {
                        $officialExts[$idxCat][is_string($archiveDir) ? $archiveDir : ''] = $eid;
                    }
                }
            }
        } else {
            $this->log->info('[sendPiwigoInfos][exec=' . $execId . '] fetchRemote on ' . $url . ' has failed');
            $this->sendPiwigoInfosRetryLater(1 * 60 * 60);
            $this->pwgUniqueExecEnds('send_piwigo_infos');
            $this->log->info('[sendPiwigoInfos][exec=' . $execId . '] executed in ' . get_elapsed_time($startTime, get_moment()));
            return;
        }

        $plugins = new Plugins();
        $piwigoInfos['general_stats']['nb_private_plugins'] = 0;
        $piwigoInfos['plugins'] = [];
        foreach ($plugins->db_plugins_by_id as $plugin) {
            $pluginId      = is_string($plugin['id'] ?? null) ? $plugin['id'] : '';
            $pluginState   = is_string($plugin['state'] ?? null) ? $plugin['state'] : '';
            $pluginVersion = is_string($plugin['version'] ?? null) ? $plugin['version'] : '';
            if ($pluginState === 'active') {
                $eid      = null;
                $fsPlugin = $plugins->fs_plugins[$pluginId] ?? null;
                if (is_array($fsPlugin)) {
                    $uri = is_string($fsPlugin['uri'] ?? null) ? $fsPlugin['uri'] : '';
                    if (preg_match('/eid=(\d+)/', $uri, $matches) && isset($pemExtensions[$matches[1]])) {
                        $eid = $matches[1];
                    }
                }
                if (empty($eid)) {
                    $eid = $officialExts[Config::pemPluginsCategory()][$pluginId] ?? null;
                }
                if (empty($eid)) {
                    $this->log->info('[sendPiwigoInfos][exec=' . $execId . '] ' . $pluginId . ' is a private plugin');
                    $piwigoInfos['general_stats']['nb_private_plugins']++;
                    continue;
                }
                $pemExt   = is_array($pemExtensions[$eid] ?? null) ? $pemExtensions[$eid] : [];
                $codename = is_string($pemExt['archive_root_dir'] ?? null) ? $pemExt['archive_root_dir'] : $pluginId;
                $piwigoInfos['plugins'][] = '#' . (string) $eid . '/' . $codename . '/' . $pluginVersion;
            }
        }
        $piwigoInfos['general_stats']['nb_plugins'] = $piwigoInfos['general_stats']['nb_private_plugins'] + count($piwigoInfos['plugins']);

        $themes  = new Themes();
        $piwigoInfos['general_stats']['nb_private_themes'] = 0;
        $piwigoInfos['themes'] = [];
        $privateThemes = [];
        foreach ($themes->db_themes_by_id as $theme) {
            $themeId      = is_string($theme['id'] ?? null) ? $theme['id'] : '';
            $themeVersion = is_string($theme['version'] ?? null) ? $theme['version'] : '';
            $eid          = null;
            $fsTheme = $themes->fs_themes[$themeId] ?? null;
            if (is_array($fsTheme)) {
                $uri = is_string($fsTheme['uri'] ?? null) ? $fsTheme['uri'] : '';
                if (preg_match('/eid=(\d+)/', $uri, $matches) && isset($pemExtensions[$matches[1]])) {
                    $eid = $matches[1];
                }
            }
            if (empty($eid)) {
                $eid = $officialExts[Config::pemThemesCategory()][$themeId] ?? null;
            }
            if (empty($eid)) {
                $this->log->info('[sendPiwigoInfos][exec=' . $execId . '] ' . $themeId . ' is a private theme');
                $privateThemes[$themeId] = 1;
                continue;
            }
            $pemExt   = is_array($pemExtensions[$eid] ?? null) ? $pemExtensions[$eid] : [];
            $codename = is_string($pemExt['archive_root_dir'] ?? null) ? $pemExt['archive_root_dir'] : $themeId;
            $piwigoInfos['themes'][] = '#' . (string) $eid . '/' . $codename . '/' . $themeVersion;
        }
        $piwigoInfos['general_stats']['nb_private_themes'] = count(array_keys($privateThemes));
        $piwigoInfos['general_stats']['nb_themes']         = $piwigoInfos['general_stats']['nb_private_themes'] + count($piwigoInfos['themes']);

        $defaultTheme = get_default_theme();
        if (isset($privateThemes[$defaultTheme])) {
            $defaultTheme = 'private theme';
        }
        $piwigoInfos['general_stats']['default_theme'] = $defaultTheme;

        $piwigoInfos['themes_usage'] = [];
        $query      = 'SELECT theme, COUNT(*) AS theme_counter FROM ' . USER_INFOS_TABLE . ' GROUP BY theme ORDER BY theme;';
        $themesUsed = array_column(get_dbal_connection()->executeQuery($query)->fetchAllAssociative(), 'theme_counter', 'theme');
        foreach ($themesUsed as $themeUsed => $counter) {
            if (isset($privateThemes[$themeUsed])) {
                $themeUsed = 'private theme';
            }
            $piwigoInfos['themes_usage'][$themeUsed] = ($piwigoInfos['themes_usage'][$themeUsed] ?? 0) + (is_numeric($counter) ? (int) $counter : 0);
        }

        $piwigoInfos['general_stats']['default_language'] = get_default_language();

        $query = 'SELECT language, COUNT(*) AS language_counter FROM ' . USER_INFOS_TABLE . ' GROUP BY language ORDER BY language;';
        $piwigoInfos['languages_usage'] = array_column(get_dbal_connection()->executeQuery($query)->fetchAllAssociative(), 'language_counter', 'language');

        $piwigoInfos['activities']                      = [];
        $piwigoInfos['general_stats']['nb_activities']  = 0;

        $query      = 'SELECT object, action, COUNT(*) AS counter FROM ' . ACTIVITY_TABLE . " WHERE object != 'system' GROUP BY object, action;";
        $activities = get_dbal_connection()->executeQuery($query)->fetchAllAssociative();
        foreach ($activities as $activity) {
            $piwigoInfos['general_stats']['nb_activities'] += is_numeric($activity['counter']) ? (int) $activity['counter'] : 0;
            $objectKey = is_scalar($activity['object']) ? (string) $activity['object'] : '';
            $actionKey = is_scalar($activity['action']) ? (string) $activity['action'] : '';
            if (!isset($piwigoInfos['activities'][$objectKey])) {
                $piwigoInfos['activities'][$objectKey] = [];
            }
            $piwigoInfos['activities'][$objectKey][$actionKey] = $activity['counter'];
        }

        $labelForSystemObjectId = [1 => 'core', 2 => 'plugin', 3 => 'theme'];
        $query      = 'SELECT object, object_id, action, COUNT(*) AS counter FROM ' . ACTIVITY_TABLE . " WHERE object = 'system' GROUP BY object, object_id, action;";
        $activities = get_dbal_connection()->executeQuery($query)->fetchAllAssociative();
        $systemActivities = [];
        foreach ($activities as $activity) {
            $objectIdKey = is_numeric($activity['object_id']) ? (int) $activity['object_id'] : 0;
            $actionKey   = is_scalar($activity['action']) ? (string) $activity['action'] : '';
            $labelKey    = (string) ($labelForSystemObjectId[$objectIdKey] ?? 'undefined');
            if (!isset($systemActivities[$labelKey])) {
                $systemActivities[$labelKey] = [];
            }
            $systemActivities[$labelKey][$actionKey] = $activity['counter'];
        }
        $piwigoInfos['activities']['system'] = $systemActivities;

        $query   = 'SELECT action, occured_on, details FROM ' . ACTIVITY_TABLE . " WHERE object = 'system' AND object_id = " . ACTIVITY_SYSTEM_CORE . " AND action IN ('update', 'autoupdate') ORDER BY activity_id ASC;";
        $updates = get_dbal_connection()->executeQuery($query)->fetchAllAssociative();
        foreach ($updates as $update) {
            $details = safe_unserialize(is_string($update['details']) ? $update['details'] : '');
            if (isset($details['from_version']) && isset($details['to_version'])) {
                $piwigoInfos['updates'][] = [
                    'action'       => $update['action'],
                    'occured_on'   => $update['occured_on'],
                    'from_version' => $details['from_version'],
                    'to_version'   => $details['to_version'],
                ];
            }
        }

        $watermark = ImageStdParams::get_watermark();
        $piwigoInfos['features'] = ['use_watermark' => !empty($watermark->file) ? 'yes' : 'no'];

        $query      = 'SELECT user_agent, COUNT(*) AS counter, MIN(occured_on) AS first_encounter, MAX(occured_on) AS last_encounter FROM ' . ACTIVITY_TABLE . " WHERE user_agent NOT LIKE 'Mozilla/5%' GROUP BY user_agent;";
        $activities = get_dbal_connection()->executeQuery($query)->fetchAllAssociative();
        $apps       = [];
        $appsPattern = [
            'Piwigo iOS'          => '/^Piwigo\/\d+ CFNetwork/',
            'Piwigo NG'           => '/^Dart\/[\d\.]+ \(dart:io\)$/',
            'Piwigo Android'      => '/^Piwigo-Android/',
            'Lightroom'           => '/Lightroom/',
            'Piwigo Remote Sync'  => '/(PiwigoRemoteSync|Apache-HttpClient)/',
            'darktable'           => '/darktable/',
            'Piwigo Client'       => '/PiwigoClient/',
            'Aperture'            => '/ApertureToPiwigoPlugIn/',
            'MacShare'            => '/MacShareToPiwigo/',
            'WordPress'           => '/WordPress/',
            'pLoader'             => '/pLoader/',
        ];
        foreach ($activities as $activity) {
            foreach ($appsPattern as $appName => $pattern) {
                if (preg_match($pattern, is_scalar($activity['user_agent']) ? (string) $activity['user_agent'] : '')) {
                    $apps[$appName]['counter'] = (is_numeric($apps[$appName]['counter'] ?? null) ? (int) $apps[$appName]['counter'] : 0) + (is_numeric($activity['counter']) ? (int) $activity['counter'] : 0);
                    if (!isset($apps[$appName]['first_encounter']) || strtotime(is_scalar($apps[$appName]['first_encounter']) ? (string) $apps[$appName]['first_encounter'] : '') > strtotime(is_scalar($activity['first_encounter']) ? (string) $activity['first_encounter'] : '')) {
                        $apps[$appName]['first_encounter'] = $activity['first_encounter'];
                    }
                    if (!isset($apps[$appName]['last_encounter']) || strtotime(is_scalar($apps[$appName]['last_encounter']) ? (string) $apps[$appName]['last_encounter'] : '') < strtotime(is_scalar($activity['last_encounter']) ? (string) $activity['last_encounter'] : '')) {
                        $apps[$appName]['last_encounter'] = $activity['last_encounter'];
                    }
                }
            }
        }
        $piwigoInfos['apps'] = $apps;

        foreach (['activate_comments', 'rate', 'log', 'history_guest', 'history_admin'] as $feature) {
            $piwigoInfos['features'][$feature] = Config::raw($feature) ? 'yes' : 'no';
        }

        $updateUrl = conf_get_param('send_piwigo_infos_update_url', PHPWG_URL);
        $url = (is_scalar($updateUrl) ? (string) $updateUrl : PHPWG_URL) . '/ws.php';

        $getData  = ['format' => 'php', 'method' => 'porg.installs.update', 'origin_hash' => $piwigoInfos['origin_hash']];
        $postData = ['data' => json_encode($piwigoInfos)];

        if (!ServiceLocator::get(AdminService::class)->fetchRemote($url, $result, $getData, $postData)) {
            $this->log->info('[sendPiwigoInfos][exec=' . $execId . '] fetchRemote on ' . $url . ' method=porg.installs.update has failed');
            $this->sendPiwigoInfosRetryLater(24 * 60 * 60);
        } else {
            $lastNotice = date('c');
            conf_update_param('send_piwigo_infos_last_notice', $lastNotice, true);
            $this->log->info('[sendPiwigoInfos][exec=' . $execId . '] fetchRemote success, new last_notice=' . Config::sendPiwigoInfosLastNotice());
        }

        $this->pwgUniqueExecEnds('send_piwigo_infos');
        $this->log->info('[sendPiwigoInfos][exec=' . $execId . '] executed in ' . get_elapsed_time($startTime, get_moment()));
    }
}
