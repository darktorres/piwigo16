<?php

declare(strict_types=1);

namespace Piwigo\Core;

use Doctrine\DBAL\Connection;
use Latte\Runtime\Html;
use Piwigo\Admin\History\HistoryAdminService;
use Piwigo\Config\Config;
use Piwigo\Config\ConfigService;
use Piwigo\Db\Dml;
use Piwigo\Db\SchemaHelper;
use Piwigo\Db\Tables;
use Piwigo\History\HistoryRepository;
use Piwigo\Html\HtmlService;
use Piwigo\Http\RequestContext;
use Piwigo\Http\RequestContextRegistry;
use Piwigo\Lang\LangService;
use Piwigo\Page\PageHeaderRenderer;
use Piwigo\Page\PageTailRenderer;
use Piwigo\Plugins\EventDispatcher;
use Piwigo\Section\SectionContextRegistry;
use Piwigo\Template\Template;
use Piwigo\Template\TemplateRegistry;
use Piwigo\Users\CurrentUser;
use Piwigo\Users\PermissionService;
use Piwigo\Users\UserRepository;
use Piwigo\Users\UserService;

final readonly class Util
{
    public function __construct(
        private Connection $conn,
        private LangService $langService,
        private UserRepository $userRepository,
        private PermissionService $permissionService,
        private HtmlService $htmlService,
        private ConfigService $configService,
        private HistoryRepository $historyRepository,
        private HistoryAdminService $historyAdminService,
    ) {
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
            CurrentUser::setRawAttributes(Kernel::service(UserService::class)->buildUser(Config::guestId(), true));
            $this->langService->loadLanguage('common.lang');
            EventDispatcher::notify('loading_lang');
            $this->langService->loadLanguage('lang', PHPWG_ROOT_PATH . PWG_LOCAL_DIR, ['no_fallback' => true, 'local' => true]);
            $tpl = new Template(PHPWG_ROOT_PATH . 'themes', Kernel::service(UserService::class)->getDefaultTheme());
            TemplateRegistry::set($tpl);
        } elseif (RequestContextRegistry::current() === RequestContext::Admin) {
            $tpl = new Template(PHPWG_ROOT_PATH . 'themes', Kernel::service(UserService::class)->getDefaultTheme());
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

    public function checkPwgToken(): void
    {
        if (isset($_REQUEST['pwg_token']) && $_REQUEST['pwg_token'] !== '') {
            if ($this->getPwgToken() !== $_REQUEST['pwg_token']) {
                $this->htmlService->accessDenied();
            }
        } else {
            $this->htmlService->badRequest('missing token');
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

    public function doLog(int|null $imageId = null, string|null $imageType = null): bool
    {
        $doLog = Config::logConf();
        if ($this->permissionService->isAdmin()) {
            $doLog = Config::historyAdmin();
        }
        if ($this->permissionService->isAGuest()) {
            $doLog = Config::historyGuest();
        }
        return (bool) EventDispatcher::dispatch('pwg_log_allowed', $doLog, $imageId, $imageType);
    }

    public function pwgLog(int|string|null $imageId = null, ?string $imageType = null, ?string $formatId = null): bool
    {
        $user = CurrentUser::get()->rawAttributes;
        $ctx  = SectionContextRegistry::current();

        if ($imageId !== null) {
            $imageId = (int) $imageId;
        }

        $userId    = CurrentUser::get()->id;
        $lastVisit = is_scalar($user['last_visit'] ?? null) ? (string) $user['last_visit'] : '';
        $updateLastVisit = empty($lastVisit) || strtotime($lastVisit) < time() - Config::sessionLength();
        $updateLastVisit = EventDispatcher::dispatch('pwg_log_update_last_visit', $updateLastVisit);

        if ($updateLastVisit) {
            $this->userRepository->updateLastVisit($userId);
        }

        if (!$this->doLog($imageId, $imageType)) {
            return false;
        }

        $tagsString  = null;
        $pageSection = $ctx->section;
        if ($pageSection === 'tags') {
            $tagsString = implode(',', array_map(static fn (int $v): string => (string) $v, $ctx->tagIds));
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
                $this->configService->confUpdateParam('history_sections_cache', SchemaHelper::getEnums(Tables::history(), 'section'), true);
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
                $this->configService->confUpdateParam('history_sections_cache', SchemaHelper::getEnums(Tables::history(), 'section'), true);
                $section = $pageSection;
            }
        }

        $category   = $ctx->category;
        $categoryId = $category !== null && is_scalar($category['id'] ?? null) ? (string) $category['id'] : 'NULL';
        $searchId   = $ctx->searchId ?? 'NULL';
        $authKeyId  = PageState::current()->authKeyId !== null ? (string) PageState::current()->authKeyId : 'NULL';

        $historyId = $this->historyRepository->insertLog(
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
            $this->historyAdminService->historySummarize(50000);
        }
        if (Config::historyAutopurgeEvery() > 0 && $historyId % Config::historyAutopurgeEvery() === 0) {
            $this->historyAdminService->historyAutopurge();
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

}
