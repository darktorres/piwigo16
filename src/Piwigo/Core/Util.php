<?php

declare(strict_types=1);

namespace Piwigo\Core;

use Doctrine\DBAL\Connection;
use Latte\Runtime\Html;
use Piwigo\Admin\AdminService;
use Piwigo\Admin\Category\CategoryAdminService;
use Piwigo\Admin\History\HistoryAdminService;
use Piwigo\Admin\Plugins;
use Piwigo\Admin\Themes;
use Piwigo\Cache\RequestCache;
use Piwigo\Config\Config;
use Piwigo\Config\ConfigService;
use Piwigo\Db\DbConnection;
use Piwigo\Db\DbInfo;
use Piwigo\Db\Dml;
use Piwigo\Db\SchemaHelper;
use Piwigo\Db\SqlExpr;
use Piwigo\Db\Tables;
use Piwigo\History\HistoryRepository;
use Piwigo\Html\HtmlService;
use Piwigo\Image\ImageStdParams;
use Piwigo\Lang\LangService;
use Piwigo\Language\LanguageRepository;
use Piwigo\Page\PageHeaderRenderer;
use Piwigo\Page\PageTailRenderer;
use Piwigo\Plugins\EventDispatcher;
use Piwigo\Session\SessionService;
use Piwigo\Template\Template;
use Piwigo\Template\TemplateRegistry;
use Piwigo\Theme\ThemeRepository;
use Piwigo\Users\CurrentUser;
use Piwigo\Users\PermissionService;
use Piwigo\Users\UserRepository;
use Piwigo\Users\UserService;
use Psr\Log\LoggerInterface;

defined('MKGETDIR_NONE') or define('MKGETDIR_NONE', 0);
defined('MKGETDIR_RECURSIVE') or define('MKGETDIR_RECURSIVE', 1);
defined('MKGETDIR_DIE_ON_ERROR') or define('MKGETDIR_DIE_ON_ERROR', 2);
defined('MKGETDIR_PROTECT_INDEX') or define('MKGETDIR_PROTECT_INDEX', 4);
defined('MKGETDIR_DEFAULT') or define('MKGETDIR_DEFAULT', MKGETDIR_RECURSIVE | MKGETDIR_DIE_ON_ERROR | MKGETDIR_PROTECT_INDEX);

final readonly class Util
{
    public static function get(): self
    {
        return ServiceLocator::get(self::class);
    }

    public function __construct(
        private Connection $conn,
        private LoggerInterface $log,
    ) {
    }

    /** @pre-boot Safe to call before Kernel::boot() — no DI container required. */
    public static function mkgetdir(string $dir, int $flags = MKGETDIR_DEFAULT): bool
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
                HtmlService::fatalError("$dir " . Lang::t('no write access'));
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
            HtmlService::fatalError("$dir " . Lang::t('no write access'));
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
            CurrentUser::setRawAttributes(UserService::get()->buildUser(Config::guestId(), true));
            LangService::get()->loadLanguage('common.lang');
            EventDispatcher::notify('loading_lang');
            LangService::get()->loadLanguage('lang', PHPWG_ROOT_PATH . PWG_LOCAL_DIR, ['no_fallback' => true, 'local' => true]);
            $tpl = new Template(PHPWG_ROOT_PATH . 'themes', UserService::get()->getDefaultTheme());
            TemplateRegistry::set($tpl);
        } elseif (defined('IN_ADMIN')) {
            $tpl = new Template(PHPWG_ROOT_PATH . 'themes', UserService::get()->getDefaultTheme());
            TemplateRegistry::set($tpl);
        }

        if (empty($msg)) {
            $msg = nl2br(Lang::t('Redirection...'));
        }

        $refresh  = $refreshTime;
        $url_link = $url;
        $title    = 'redirection';

        $tpl = TemplateRegistry::current();

        PageHeaderRenderer::render($title, $refresh, $url_link);

        $tpl->assign('REDIRECT_MSG', new Html($msg));
        $tpl->parse('redirect.latte');

        PageTailRenderer::render();
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
    /** @return string[] */
    public function getLanguages(): array
    {
        $languages = [];
        foreach (ServiceLocator::get(LanguageRepository::class)->findIdNameMap() as $id => $name) {
            if (is_dir(PHPWG_ROOT_PATH . 'language/' . $id)) {
                $languages[$id] = $name;
            }
        }
        return $languages;
    }

    /** @return array<string,string> */
    public function getPwgThemes(bool $showMobile = false): array
    {
        $themes = [];
        if (ServiceLocator::has(ThemeRepository::class)) {
            $rows = ServiceLocator::get(ThemeRepository::class)->findAll();
        } else {
            $rows = $this->conn->executeQuery('SELECT id, name FROM ' . Tables::themes() . ' ORDER BY name ASC;')->fetchAllAssociative();
        }
        foreach ($rows as $row) {
            if ($row['id'] === Config::mobilTheme()) {
                if (!$showMobile) {
                    continue;
                }
                $row['name'] = (is_string($row['name'] ?? null) ? $row['name'] : '') . (' (' . Lang::t('Mobile') . ')');
            }
            $themeId = is_string($row['id'] ?? null) ? $row['id'] : '';
            if ($this->checkThemeInstalled($themeId)) {
                $themes[$themeId] = is_string($row['name'] ?? null) ? $row['name'] : '';
            }
        }
        $dispatched = EventDispatcher::dispatch('get_pwg_themes', $themes);
        return $dispatched;
    }

    public function checkThemeInstalled(string $themeId): bool
    {
        return file_exists(Config::themesDir() . '/' . $themeId . '/themeconf.inc.php');
    }

    public function getThemeconf(string $key): mixed
    {
        /** @var Template $template */
        $template = $GLOBALS['template'];
        return $template->getThemeconf($key);
    }

    public function getFilterPageValue(string $valueName): mixed
    {
        $pageName = StringUtil::scriptBasename();
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
                Tables::users(),
                Config::webmasterId()
            );
        $email = EventDispatcher::dispatch('get_webmaster_mail_address', $email);
        return (string) $email;
    }

    /** @param array<int|string> $elementsId */
    public function fillCaddie(array $elementsId): void
    {
        $userId = CurrentUser::get()->id;
        $query  = 'SELECT element_id FROM ' . Tables::caddie() . ' WHERE user_id = ' . $userId . ';';
        $inCaddie = array_column(DbConnection::get()->executeQuery($query)->fetchAllAssociative(), 'element_id');
        $caddiables = array_diff($elementsId, array_map(static fn (mixed $v): string => is_scalar($v) ? (string) $v : '0', $inCaddie));
        $datas = [];
        foreach ($caddiables as $caddiable) {
            $datas[] = ['element_id' => $caddiable, 'user_id' => $userId];
        }
        if (count($caddiables) > 0) {
            Dml::massInserts(Tables::caddie(), ['element_id', 'user_id'], $datas);
        }
    }

    public function getNbAvailableComments(): int
    {
        $cached = RequestCache::remember('user', 'nb_available_comments', function (): int {
            $where = [];
            if (!PermissionService::get()->isAdmin()) {
                $where[] = "validated='true'";
            }
            $where[] = PermissionService::get()->getSqlConditionFandF(
                ['forbidden_categories' => 'category_id', 'forbidden_images' => 'ic.image_id'],
                '',
                true
            );
            $query = 'SELECT COUNT(DISTINCT(com.id)) FROM ' . Tables::imageCategory() . ' AS ic'
                . ' INNER JOIN ' . Tables::comments() . ' AS com ON ic.image_id = com.image_id'
                . ' WHERE ' . implode(' AND ', $where);
            $count = $this->conn->executeQuery($query)->fetchOne();
            $nb    = is_numeric($count) ? (int) $count : 0;
            Dml::singleUpdate(Tables::userCache(), ['nb_available_comments' => $nb], ['user_id' => CurrentUser::get()->id]);
            return $nb;
        });
        return is_int($cached) ? $cached : 0;
    }

    public function checkPwgToken(): void
    {
        if (isset($_REQUEST['pwg_token']) && $_REQUEST['pwg_token'] !== '') {
            if ($this->getPwgToken() !== $_REQUEST['pwg_token']) {
                ServiceLocator::get(HtmlService::class)->accessDenied();
            }
        } else {
            ServiceLocator::get(HtmlService::class)->badRequest('missing token');
        }
    }

    public function getPwgToken(): string
    {
        return hash_hmac('md5', (string) session_id(), Config::secretKey());
    }

    /** @param array<mixed> $paramArray */
    public function checkInputParameter(string $paramName, array $paramArray, bool $isArray, ?string $pattern, bool $mandatory = false): bool
    {
        $paramValue = null;
        if (isset($paramArray[$paramName])) {
            $paramValue = $paramArray[$paramName];
        }
        if ($paramValue === null || $paramValue === '' || $paramValue === []) {
            if ($mandatory) {
                HtmlService::fatalError('[Hacking attempt] the input parameter "' . $paramName . '" is not valid');
            }
            return true;
        }
        if ($isArray) {
            if (!is_array($paramValue)) {
                HtmlService::fatalError('[Hacking attempt] the input parameter "' . $paramName . '" should be an array');
            }
            foreach ($paramValue as $key => $itemToCheck) {
                $effectivePattern = $pattern !== null && $pattern !== '' ? $pattern : '//';
                if (!preg_match(ValidationPattern::ID, (string) $key) || !preg_match($effectivePattern, is_scalar($itemToCheck) ? (string) $itemToCheck : '')) {
                    HtmlService::fatalError('[Hacking attempt] an item is not valid in input parameter "' . $paramName . '"');
                }
            }
            return true;
        }
        $effectivePattern = $pattern !== null && $pattern !== '' ? $pattern : '//';
        if (!preg_match($effectivePattern, is_scalar($paramValue) ? (string) $paramValue : '')) {
            HtmlService::fatalError('[Hacking attempt] the input parameter "' . $paramName . '" is not valid');
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
                $label = Lang::t('Everybody');
            } else {
                if (strlen($label)) {
                    $label .= ', ';
                }
                $label .= Lang::t(sprintf('Level %d', $level));
            }
            $options[$level] = $label;
        }
        return $options;
    }

    /** @return array<mixed>|false */
    public function getIcon(?string $date, bool $isChildDate = false): array|false
    {
        if ($date === null || $date === '') {
            return false;
        }
        $raw         = CurrentUser::get()->rawAttributes;
        $recentPeriod = is_scalar($raw['recent_period'] ?? null) ? (int) $raw['recent_period'] : 7;
        $title = RequestCache::remember('get_icon', 'title', static fn (): string => Lang::t(
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
                $v = DbConnection::get()->executeQuery('SELECT ' . SqlExpr::recentPeriodExpr((string) $recentPeriod))->fetchOne();
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
        /** @var mixed $remoteAddrRaw */
        $remoteAddrRaw = $_SERVER['REMOTE_ADDR'] ?? '';
        $remoteAddr = is_string($remoteAddrRaw) ? $remoteAddrRaw : '';
        return number_format($time, 1, '.', '') . ':' . $validAfterSeconds . ':'
            . hash_hmac(
                'md5',
                number_format($time, 1, '.', '') . substr($remoteAddr, 0, 5) . $validAfterSeconds . $additionalData,
                Config::secretKey()
            );
    }

    public function verifyEphemeralKey(string $key, string $additionalData = ''): bool
    {
        $time       = microtime(true);
        $key        = explode(':', $key);
        /** @var mixed $remoteAddrRaw */
        $remoteAddrRaw = $_SERVER['REMOTE_ADDR'] ?? '';
        $remoteAddr = is_string($remoteAddrRaw) ? $remoteAddrRaw : '';
        if (count($key) !== 3
            || $key[0] > $time - (float) $key[1]
            || $key[0] < $time - 3600.0
            || hash_hmac('md5', $key[0] . substr($remoteAddr, 0, 5) . $key[1] . $additionalData, Config::secretKey()) !== $key[2]
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
        $startStr    = $cleanUrl ? '/' . $paramName . '-' : (!str_contains($url, '?') ? '?' : '&') . $paramName . '=';

        if ($start < 0) {
            $start = 0;
        }

        if ($nbElement > $nbElementPage) {
            $urlStart = $url . $startStr;
            $curPage  = $navbar['CURRENT_PAGE'] = (float) $start / (float) $nbElementPage + 1.0;
            $maximum  = ceil((float) $nbElement / (float) $nbElementPage);
            $start    = (int) ((float) $nbElementPage * round((float) $start / (float) $nbElementPage));
            $previous = $start - $nbElementPage;
            $next     = $start + $nbElementPage;
            $last     = (int) (($maximum - 1.0) * (float) $nbElementPage);

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
            for ($i = (int) max(floor($curPage) - (float) $pagesAround, 2.0), $stop = (int) min(ceil($curPage) + (float) $pagesAround + 1.0, $maximum); $i < $stop; $i++) {
                $navbar['pages'][$i] = $url . $startStr . (($i - 1) * $nbElementPage);
            }
            $navbar['pages'][(int) $maximum] = $urlStart . $last;
            $navbar['NB_PAGE']               = $maximum;
        }
        return $navbar;
    }

    public function getDevice(): string
    {
        $rawDevice = ServiceLocator::get(SessionService::class)->getSessionVar('device', '');
        $device = is_string($rawDevice) ? $rawDevice : '';
        if ($device === '') {
            $uagentObj = new \uagent_info();
            if ($uagentObj->DetectSmartphone()) {
                $device = 'mobile';
            } elseif ($uagentObj->DetectTierTablet()) {
                $device = 'tablet';
            } else {
                $device = 'desktop';
            }
            ServiceLocator::get(SessionService::class)->setSessionVar('device', $device);
        }
        return $device;
    }

    public function mobileTheme(): bool
    {
        if (empty(Config::mobilTheme())) {
            return false;
        }
        if (isset($_GET['mobile'])) {
            /** @var mixed $mobileRaw */
            $mobileRaw = $_GET['mobile'];
            $isMobileTheme = (is_string($mobileRaw) || is_int($mobileRaw) || is_float($mobileRaw))
                ? BoolUtil::fromMixed($mobileRaw)
                : false;
            ServiceLocator::get(SessionService::class)->setSessionVar('mobile_theme', $isMobileTheme);
        } else {
            $isMobileTheme = ServiceLocator::get(SessionService::class)->getSessionVar('mobile_theme');
        }
        if (is_null($isMobileTheme)) {
            $isMobileTheme = ($this->getDevice() === 'mobile');
            ServiceLocator::get(SessionService::class)->setSessionVar('mobile_theme', $isMobileTheme);
        }
        return (bool) $isMobileTheme;
    }

    public function doLog(int|null $imageId = null, string|null $imageType = null): bool
    {
        $doLog = Config::logConf();
        if (PermissionService::get()->isAdmin()) {
            $doLog = Config::historyAdmin();
        }
        if (PermissionService::get()->isAGuest()) {
            $doLog = Config::historyGuest();
        }
        return (bool) EventDispatcher::dispatch('pwg_log_allowed', $doLog, $imageId, $imageType);
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
        $updateLastVisit = EventDispatcher::dispatch('pwg_log_update_last_visit', $updateLastVisit);

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
            $tagsString = implode(',', array_map(static fn (mixed $v): string => is_scalar($v) ? (string) $v : '0', $tagIds));
            if (strlen($tagsString) > 50) {
                $tagsString  = substr($tagsString, 0, 50);
                $commaPos    = strrpos($tagsString, ',');
                if ($commaPos !== false) {
                    $tagsString = substr($tagsString, 0, $commaPos);
                }
            }
        }

        /** @var mixed $ipRaw */
        $ipRaw = $_SERVER['REMOTE_ADDR'] ?? '';
        $ip = is_string($ipRaw) ? $ipRaw : '';
        if (strlen($ip) > 39) {
            $ip = substr($ip, 0, 39);
        }

        if ($pageSection !== '') {
            if (!Config::has('history_sections_cache')) {
                ServiceLocator::get(ConfigService::class)->confUpdateParam('history_sections_cache', SchemaHelper::getEnums(Tables::history(), 'section'), true);
            }
            $historySectionsCache = StringUtil::safeUnserialize(Config::historySectionsCache() ?? '');
            Config::override('history_sections_cache', $historySectionsCache);
            if (
                in_array($pageSection, $historySectionsCache)
                || in_array(strtolower($pageSection), array_map(static fn (mixed $s): string => strtolower(is_scalar($s) ? (string) $s : ''), $historySectionsCache))
            ) {
                $section = $pageSection;
            } elseif (preg_match('/^[a-zA-Z0-9_-]+$/', $pageSection)) {
                $historySections = SchemaHelper::getEnums(Tables::history(), 'section');
                $historySections[] = $pageSection;
                $this->conn->executeStatement(
                    'ALTER TABLE ' . Tables::history() . " CHANGE section section enum('" .
                    implode("','", array_unique($historySections)) . "') DEFAULT NULL"
                );
                ServiceLocator::get(ConfigService::class)->confUpdateParam('history_sections_cache', SchemaHelper::getEnums(Tables::history(), 'section'), true);
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
            ServiceLocator::get(HistoryAdminService::class)->historySummarize(50000);
        }
        if (Config::historyAutopurgeEvery() > 0 && $historyId % Config::historyAutopurgeEvery() === 0) {
            ServiceLocator::get(HistoryAdminService::class)->historyAutopurge();
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
        if (count($objectIds) === 0) {
            return;
        }

        if (isset($_REQUEST['method'])) {
            $details['method'] = $_REQUEST['method'];
        } else {
            $details['script'] = StringUtil::scriptBasename();
            if ($details['script'] === 'admin' && isset($_GET['page'])) {
                $details['script'] .= '/' . (is_string($_GET['page']) ? $_GET['page'] : '');
            }
        }

        if ($action === 'autoupdate') {
            unset($details['method']);
            unset($details['script']);
        }

        $userAgent = null;
        if ($object === 'user' && $action === 'login' && isset($_SERVER['HTTP_USER_AGENT'])) {
            /** @var mixed $uaRaw */
            $uaRaw = $_SERVER['HTTP_USER_AGENT'];
            $userAgent = strip_tags(is_string($uaRaw) ? $uaRaw : '');
        }
        if (isset($_SESSION['connected_with']) && $_SESSION['connected_with'] === 'api_key' && isset($_SERVER['HTTP_USER_AGENT'])) {
            $details['connected_with'] = 'api_key';
            /** @var mixed $uaRaw */
            $uaRaw = $_SERVER['HTTP_USER_AGENT'];
            $userAgent = strip_tags(is_string($uaRaw) ? $uaRaw : '');
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
            $refRaw = $_SERVER['HTTP_REFERER'] ?? null;
            if (is_string($refRaw) && preg_match('/page=photos_add/', $refRaw)) {
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
        $sessionId      = session_id() !== '' ? session_id() : 'none';

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
        Dml::massInserts(Tables::activity(), array_keys($inserts[0]), $inserts);
    }

    public function checkLounge(): void
    {
        if (!Config::has('lounge_active') || !Config::loungeActive()) {
            return;
        }
        if (isset($_REQUEST['method']) && in_array($_REQUEST['method'], ['pwg.images.upload', 'pwg.images.uploadAsync'])) {
            return;
        }
        $query   = 'SELECT image_id, date_available, NOW() AS dbnow FROM ' . Tables::lounge() . ' JOIN ' . Tables::images() . ' ON image_id = id ORDER BY image_id ASC LIMIT 1;';
        $voyagers = DbConnection::get()->executeQuery($query)->fetchAllAssociative();
        if (count($voyagers)) {
            $voyager = $voyagers[0];
            $dbnowStr = is_string($voyager['dbnow'] ?? null) ? $voyager['dbnow'] : '';
            $dateAvailStr = is_string($voyager['date_available'] ?? null) ? $voyager['date_available'] : '';
            $dbnowTs = strtotime($dbnowStr);
            $dateAvailTs = strtotime($dateAvailStr);
            $age = ($dbnowTs !== false ? $dbnowTs : 0) - ($dateAvailTs !== false ? $dateAvailTs : 0);
            if ($age > Config::loungeMaxDuration()) {
                ServiceLocator::get(CategoryAdminService::class)->emptyLounge();
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

        $this->conn->executeStatement('INSERT IGNORE INTO ' . Tables::config() . ' SET param=?, value=?', [$tokenName . '_running', $execId . '-' . time()]);
        $runningExec = $this->conn->executeQuery('SELECT value FROM ' . Tables::config() . ' WHERE param = ?', [$tokenName . '_running'])->fetchOne();
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
        $counter = $this->conn->executeQuery('SELECT COUNT(*) FROM ' . Tables::config() . ' WHERE param = ?', [$tokenName . '_running'])->fetchOne();
        return is_numeric($counter) ? (int) $counter > 0 : false;
    }

    public function pwgUniqueExecEnds(string $tokenName): void
    {
        ServiceLocator::get(ConfigService::class)->confDeleteParam($tokenName . '_running');
        $this->log->info('[' . $tokenName . '] ends now');
    }

    public function sendPiwigoInfosRetryLater(int $waitTime): void
    {
        $strtotimeResult = Config::has('send_piwigo_infos_last_notice') ? strtotime(Config::sendPiwigoInfosLastNotice() ?? '') : false;
        $lastNotice = $strtotimeResult !== false ? $strtotimeResult : time();
        $lastNotice += $waitTime;
        ServiceLocator::get(ConfigService::class)->confUpdateParam('send_piwigo_infos_last_notice', date('c', $lastNotice), true);
        $this->log->info('[sendPiwigoInfosRetryLater] new send_piwigo_infos_last_notice=' . (Config::sendPiwigoInfosLastNotice() ?? ''));
    }

    public function sendPiwigoInfos(): void
    {
        $startTime = StringUtil::get()->getMoment();

        if (!Config::sendPiwigoInfos()) {
            return;
        }

        ConfigService::loadConfFromDb('param = "send_piwigo_infos_last_notice"', false);

        $doSend = false;
        if (Config::has('send_piwigo_infos_last_notice')) {
            $period = ServiceLocator::get(ConfigService::class)->confGetParam('send_piwigo_infos_period', 7 * 24 * 60 * 60);
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

        if (!ServiceLocator::get(ConfigService::class)->pwgIsDbconfWriteable()) {
            $this->log->info('[sendPiwigoInfos] conf is not writeable, abort');
            return;
        }

        $execId = $this->pwgUniqueExecBegins('send_piwigo_infos');
        if ($execId === false) {
            $this->log->info('[sendPiwigoInfos] another execution is running, abort');
            return;
        }

        $dbCurrentDate = new \DateTimeImmutable()->format('Y-m-d H:i:s');

        if (!Config::has('send_piwigo_infos_origin_hash')) {
            ServiceLocator::get(ConfigService::class)->confUpdateParam('send_piwigo_infos_origin_hash', sha1(random_bytes(1000)), true);
        }

        [$containerType, $containerVersion] = ServiceLocator::get(StringUtil::class)->getContainerInfo();

        $piwigoInfos = [
            'origin_hash' => Config::sendPiwigoInfosOriginHash(),
            'technical'   => [
                'php_version'       => PHP_VERSION,
                'piwigo_version'    => AppInfo::VERSION,
                'os_version'        => PHP_OS,
                'container_type'    => $containerType,
                'container_version' => $containerVersion,
                'db_version'        => DbInfo::version(),
                'php_datetime'      => date('Y-m-d H:i:s'),
                'db_datetime'       => $dbCurrentDate,
                'graphics_library'  => ServiceLocator::get(AdminService::class)->getGraphicsLibrary(),
            ],
            'general_stats' => ServiceLocator::get(AdminService::class)->getPwgGeneralStatitics(),
        ];

        $du = $piwigoInfos['general_stats']['disk_usage'] ?? 0;
        $piwigoInfos['general_stats']['disk_usage']        = intval((is_numeric($du) ? (float) $du : 0.0) / 1024.0);
        $piwigoInfos['general_stats']['installed_on']      = ServiceLocator::get(AdminService::class)->getInstallationDate();
        $piwigoInfos['general_stats']['nb_photos_synced']  = 0;
        $piwigoInfos['general_stats']['last_photo_synced'] = null;
        $piwigoInfos['general_stats']['last_photo']        = null;

        if ($piwigoInfos['general_stats']['nb_photos'] > 0) {
            $query = 'SELECT COUNT(*) AS counter FROM `' . Tables::images() . '` WHERE storage_category_id IS NOT NULL;';
            if (array_column(DbConnection::get()->executeQuery($query)->fetchAllAssociative(), 'counter')[0] > 0) {
                $query = 'SELECT IF(storage_category_id IS NULL, \'api\', \'sync\') AS add_method, MAX(date_available) AS last_added_on, COUNT(*) AS nb_files FROM `' . Tables::images() . '` GROUP BY add_method;';
                $filesByMethod = array_column(DbConnection::get()->executeQuery($query)->fetchAllAssociative(), null, 'add_method');
                $piwigoInfos['general_stats']['nb_photos_synced']  = $filesByMethod['sync']['nb_files'];
                $piwigoInfos['general_stats']['last_photo_synced'] = $filesByMethod['sync']['last_added_on'];
                $methodOfLastPhoto = 'sync';
                if (isset($filesByMethod['api']) && strtotime(is_scalar($filesByMethod['api']['last_added_on']) ? (string) $filesByMethod['api']['last_added_on'] : '') > strtotime(is_scalar($filesByMethod['sync']['last_added_on']) ? (string) $filesByMethod['sync']['last_added_on'] : '')) {
                    $methodOfLastPhoto = 'api';
                }
                $piwigoInfos['general_stats']['last_photo'] = $filesByMethod[$methodOfLastPhoto]['last_added_on'];
            } else {
                $query  = 'SELECT date_available FROM `' . Tables::images() . '` ORDER BY id DESC LIMIT 1;';
                $images = DbConnection::get()->executeQuery($query)->fetchAllAssociative();
                if (count($images) > 0) {
                    $piwigoInfos['general_stats']['last_photo'] = $images[0]['date_available'];
                }
            }

            $query = 'SELECT SUBSTRING_INDEX(path,".",-1) AS ext, COUNT(*) AS counter, SUM(filesize) AS filesize FROM `' . Tables::images() . '` GROUP BY ext;';
            $piwigoInfos['file_extensions'] = array_column(DbConnection::get()->executeQuery($query)->fetchAllAssociative(), null, 'ext');
        }

        $url         = PEM_URL . '/api/get_extension_list.php';
        $result = '';
        $pemExtensions = ServiceLocator::get(AdminService::class)->fetchRemote($url, $result) && is_string($result) ? StringUtil::safeUnserialize($result) : [];

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
            $this->log->info('[sendPiwigoInfos][exec=' . $execId . '] executed in ' . StringUtil::get()->getElapsedTime($startTime, StringUtil::get()->getMoment()));
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
                if ($eid === null) {
                    $eid = $officialExts[Config::pemPluginsCategory()][$pluginId] ?? null;
                }
                if ($eid === null || $eid === '') {
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
            if ($eid === null) {
                $eid = $officialExts[Config::pemThemesCategory()][$themeId] ?? null;
            }
            if ($eid === null || $eid === '') {
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

        $defaultTheme = UserService::get()->getDefaultTheme();
        if (isset($privateThemes[$defaultTheme])) {
            $defaultTheme = 'private theme';
        }
        $piwigoInfos['general_stats']['default_theme'] = $defaultTheme;

        $piwigoInfos['themes_usage'] = [];
        $query      = 'SELECT theme, COUNT(*) AS theme_counter FROM ' . Tables::userInfos() . ' GROUP BY theme ORDER BY theme;';
        $themesUsed = array_column(DbConnection::get()->executeQuery($query)->fetchAllAssociative(), 'theme_counter', 'theme');
        foreach ($themesUsed as $themeUsed => $counter) {
            if (isset($privateThemes[$themeUsed])) {
                $themeUsed = 'private theme';
            }
            $piwigoInfos['themes_usage'][$themeUsed] = ($piwigoInfos['themes_usage'][$themeUsed] ?? 0) + (is_numeric($counter) ? (int) $counter : 0);
        }

        $piwigoInfos['general_stats']['default_language'] = UserService::get()->getDefaultLanguage();

        $query = 'SELECT language, COUNT(*) AS language_counter FROM ' . Tables::userInfos() . ' GROUP BY language ORDER BY language;';
        $piwigoInfos['languages_usage'] = array_column(DbConnection::get()->executeQuery($query)->fetchAllAssociative(), 'language_counter', 'language');

        $piwigoInfos['activities']                      = [];
        $piwigoInfos['general_stats']['nb_activities']  = 0;

        $query      = 'SELECT object, action, COUNT(*) AS counter FROM ' . Tables::activity() . " WHERE object != 'system' GROUP BY object, action;";
        $activities = DbConnection::get()->executeQuery($query)->fetchAllAssociative();
        foreach ($activities as $activity) {
            $piwigoInfos['general_stats']['nb_activities'] += is_numeric($activity['counter']) ? (int) $activity['counter'] : 0;
            $objectKey = is_string($activity['object'] ?? null) ? $activity['object'] : '';
            $actionKey = is_string($activity['action'] ?? null) ? $activity['action'] : '';
            if (!isset($piwigoInfos['activities'][$objectKey])) {
                $piwigoInfos['activities'][$objectKey] = [];
            }
            $piwigoInfos['activities'][$objectKey][$actionKey] = $activity['counter'];
        }

        $labelForSystemObjectId = [1 => 'core', 2 => 'plugin', 3 => 'theme'];
        $query      = 'SELECT object, object_id, action, COUNT(*) AS counter FROM ' . Tables::activity() . " WHERE object = 'system' GROUP BY object, object_id, action;";
        $activities = DbConnection::get()->executeQuery($query)->fetchAllAssociative();
        $systemActivities = [];
        foreach ($activities as $activity) {
            $objectIdKey = is_numeric($activity['object_id']) ? (int) $activity['object_id'] : 0;
            $actionKey   = is_string($activity['action'] ?? null) ? $activity['action'] : '';
            $labelKey    = $labelForSystemObjectId[$objectIdKey] ?? 'undefined';
            if (!isset($systemActivities[$labelKey])) {
                $systemActivities[$labelKey] = [];
            }
            $systemActivities[$labelKey][$actionKey] = $activity['counter'];
        }
        $piwigoInfos['activities']['system'] = $systemActivities;

        $query   = 'SELECT action, occured_on, details FROM ' . Tables::activity() . " WHERE object = 'system' AND object_id = " . ActivitySystem::Core . " AND action IN ('update', 'autoupdate') ORDER BY activity_id ASC;";
        $updates = DbConnection::get()->executeQuery($query)->fetchAllAssociative();
        foreach ($updates as $update) {
            $details = StringUtil::safeUnserialize(is_string($update['details']) ? $update['details'] : '');
            if (isset($details['from_version']) && isset($details['to_version'])) {
                $piwigoInfos['updates'][] = [
                    'action'       => $update['action'],
                    'occured_on'   => $update['occured_on'],
                    'from_version' => $details['from_version'],
                    'to_version'   => $details['to_version'],
                ];
            }
        }

        $watermark = ImageStdParams::getWatermark();
        $piwigoInfos['features'] = ['use_watermark' => !empty($watermark->file) ? 'yes' : 'no'];

        $query      = 'SELECT user_agent, COUNT(*) AS counter, MIN(occured_on) AS first_encounter, MAX(occured_on) AS last_encounter FROM ' . Tables::activity() . " WHERE user_agent NOT LIKE 'Mozilla/5%' GROUP BY user_agent;";
        $activities = DbConnection::get()->executeQuery($query)->fetchAllAssociative();
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
                if (preg_match($pattern, is_string($activity['user_agent'] ?? null) ? $activity['user_agent'] : '')) {
                    $existingApp = $apps[$appName] ?? [];
                    /** @psalm-var mixed $existingCounter */
                    $existingCounter = $existingApp['counter'] ?? null;
                    $existingCounterInt = is_numeric($existingCounter) ? (int) $existingCounter : 0;
                    /** @psalm-var mixed $activityCounterRaw */
                    $activityCounterRaw = $activity['counter'] ?? null;
                    $activityCounter    = is_numeric($activityCounterRaw) ? (int) $activityCounterRaw : 0;
                    $apps[$appName]['counter'] = $existingCounterInt + $activityCounter;
                    if (!isset($apps[$appName]['first_encounter']) || strtotime(is_scalar($apps[$appName]['first_encounter']) ? (string) $apps[$appName]['first_encounter'] : '') > strtotime(is_string($activity['first_encounter'] ?? null) ? $activity['first_encounter'] : '')) {
                        $apps[$appName]['first_encounter'] = $activity['first_encounter'];
                    }
                    if (!isset($apps[$appName]['last_encounter']) || strtotime(is_scalar($apps[$appName]['last_encounter']) ? (string) $apps[$appName]['last_encounter'] : '') < strtotime(is_string($activity['last_encounter'] ?? null) ? $activity['last_encounter'] : '')) {
                        $apps[$appName]['last_encounter'] = $activity['last_encounter'];
                    }
                }
            }
        }
        $piwigoInfos['apps'] = $apps;

        foreach (['activate_comments', 'rate', 'log', 'history_guest', 'history_admin'] as $feature) {
            $piwigoInfos['features'][$feature] = Config::raw($feature) ? 'yes' : 'no';
        }

        $updateUrl = ServiceLocator::get(ConfigService::class)->confGetParam('send_piwigo_infos_update_url', PHPWG_URL);
        $url = (is_scalar($updateUrl) ? (string) $updateUrl : PHPWG_URL) . '/ws.php';

        $getData  = ['format' => 'php', 'method' => 'porg.installs.update', 'origin_hash' => $piwigoInfos['origin_hash']];
        $postData = ['data' => json_encode($piwigoInfos)];

        if (!ServiceLocator::get(AdminService::class)->fetchRemote($url, $result, $getData, $postData)) {
            $this->log->info('[sendPiwigoInfos][exec=' . $execId . '] fetchRemote on ' . $url . ' method=porg.installs.update has failed');
            $this->sendPiwigoInfosRetryLater(24 * 60 * 60);
        } else {
            $lastNotice = date('c');
            ServiceLocator::get(ConfigService::class)->confUpdateParam('send_piwigo_infos_last_notice', $lastNotice, true);
            $this->log->info('[sendPiwigoInfos][exec=' . $execId . '] fetchRemote success, new last_notice=' . (Config::sendPiwigoInfosLastNotice() ?? ''));
        }

        $this->pwgUniqueExecEnds('send_piwigo_infos');
        $this->log->info('[sendPiwigoInfos][exec=' . $execId . '] executed in ' . StringUtil::get()->getElapsedTime($startTime, StringUtil::get()->getMoment()));
    }
}
