<?php

declare(strict_types=1);

namespace Piwigo\Activity;

use Doctrine\DBAL\Connection;
use Piwigo\Admin\History\HistoryAdminService;
use Piwigo\Config\Config;
use Piwigo\Config\ConfigService;
use Piwigo\Core\PageState;
use Piwigo\Core\StringUtil;
use Piwigo\Db\Dml;
use Piwigo\Db\SchemaHelper;
use Piwigo\Db\Tables;
use Piwigo\History\HistoryRepository;
use Piwigo\Plugins\EventDispatcher;
use Piwigo\Section\SectionContextRegistry;
use Piwigo\Users\CurrentUser;
use Piwigo\Users\PermissionService;
use Piwigo\Users\UserRepository;

/**
 * Persists both the user-visible activity feed (`activity` table, via
 * `log()`) and per-request page-view history (`history` table, via
 * `pageView()`).
 *
 * The two were a single grab-bag on `Util` before Phase 5 Batch 3
 * (`pwgActivity` + `pwgLog` + `doLog`). They share enough infrastructure
 * — current user, current section, IP capture — that splitting them into
 * separate services would just duplicate the boilerplate.
 */
final readonly class ActivityLogger
{
    public function __construct(
        private Connection $conn,
        private ConfigService $configService,
        private HistoryRepository $historyRepository,
        private HistoryAdminService $historyAdminService,
        private PermissionService $permissionService,
        private UserRepository $userRepository,
    ) {
    }

    /**
     * Persist a user-visible activity event to the `activity` table. May
     * insert multiple rows when {@see ActivityEvent::$objectId} is an
     * array (bulk action on several objects).
     */
    public function log(ActivityEvent $event): void
    {
        $object  = $event->object->value;
        $action  = $event->action;
        $details = $event->details;

        if (isset($_REQUEST['method']) && $_REQUEST['method'] === 'pwg.images.uploadAsync' && $action === 'login') {
            return;
        }
        if (isset($_REQUEST['method']) && $_REQUEST['method'] === 'pwg.plugins.performAction' && $_REQUEST['action'] !== $action) {
            return;
        }

        $objectIds = is_array($event->objectId) ? $event->objectId : [$event->objectId];
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
        if ($event->object === ActivityObject::User && $action === 'login' && isset($_SERVER['HTTP_USER_AGENT'])) {
            /** @var mixed $uaRaw */
            $uaRaw     = $_SERVER['HTTP_USER_AGENT'];
            $userAgent = strip_tags(is_string($uaRaw) ? $uaRaw : '');
        }
        if (isset($_SESSION['connected_with']) && $_SESSION['connected_with'] === 'api_key' && isset($_SERVER['HTTP_USER_AGENT'])) {
            $details['connected_with'] = 'api_key';
            /** @var mixed $uaRaw */
            $uaRaw     = $_SERVER['HTTP_USER_AGENT'];
            $userAgent = strip_tags(is_string($uaRaw) ? $uaRaw : '');
        }
        if ($event->object === ActivityObject::User && $action === 'login') {
            if (function_exists('debug_backtrace')) {
                $calledFunctions = array_flip(array_column(debug_backtrace(), 'function'));
                foreach (['auto_login', 'auth_key_login'] as $authFunction) {
                    if (isset($calledFunctions[$authFunction])) {
                        $details['auth_function'] = $authFunction;
                    }
                }
            }
        }
        if ($event->object === ActivityObject::Photo && $action === 'add' && !isset($details['sync'])) {
            $details['added_with'] = 'app';
            $refRaw                = $_SERVER['HTTP_REFERER'] ?? null;
            if (is_string($refRaw) && preg_match('/page=photos_add/', $refRaw)) {
                $details['added_with'] = 'browser';
            }
        }
        if (in_array($event->object, [ActivityObject::Album, ActivityObject::Photo], true)
            && $action === 'delete'
            && isset($_GET['page']) && $_GET['page'] === 'site_update'
        ) {
            $details['sync'] = true;
        }
        if ($event->object === ActivityObject::Tag && $action === 'delete' && isset($_POST['destination_tag'])) {
            $details['action']          = 'merge';
            $details['destination_tag'] = $_POST['destination_tag'];
        }

        $inserts       = [];
        $detailsInsert = serialize($details);
        $ipAddress     = $_SERVER['REMOTE_ADDR'] ?? null;
        $sessionId     = session_id() !== '' ? session_id() : 'none';

        foreach ($objectIds as $loopObjectId) {
            $performedBy = CurrentUser::isInitialized() ? CurrentUser::get()->id : 0;
            if ($action === 'logout') {
                $performedBy = $loopObjectId;
            }
            $inserts[] = [
                'object'       => $object,
                'object_id'    => $loopObjectId,
                'action'       => $action,
                'performed_by' => $performedBy,
                'session_idx'  => $sessionId,
                'ip_address'   => $ipAddress,
                'details'      => $detailsInsert,
                'user_agent'   => $userAgent ?? '',
            ];
        }
        Dml::massInserts(Tables::activity(), array_keys($inserts[0]), $inserts);
    }

    /**
     * Should this request actually write to the history table? Reads the
     * `log` / `history_admin` / `history_guest` config flags layered by
     * the current user's permission level, then lets a plugin override
     * via the `pwg_log_allowed` hook.
     */
    public function isLoggingEnabled(int|null $imageId = null, string|null $imageType = null): bool
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

    /**
     * Record a page view in the `history` table. Also opportunistically
     * updates the current user's `last_visit` timestamp and runs periodic
     * history summarize / autopurge passes when the just-inserted row id
     * lands on the configured multiple.
     */
    public function pageView(int|string|null $imageId = null, ?string $imageType = null, ?string $formatId = null): bool
    {
        $user = CurrentUser::get()->rawAttributes;
        $ctx  = SectionContextRegistry::current();

        if ($imageId !== null) {
            $imageId = (int) $imageId;
        }

        $userId          = CurrentUser::get()->id;
        $lastVisit       = is_scalar($user['last_visit'] ?? null) ? (string) $user['last_visit'] : '';
        $updateLastVisit = empty($lastVisit) || strtotime($lastVisit) < time() - Config::sessionLength();
        $updateLastVisit = EventDispatcher::dispatch('pwg_log_update_last_visit', $updateLastVisit);

        if ($updateLastVisit) {
            $this->userRepository->updateLastVisit($userId);
        }

        if (!$this->isLoggingEnabled($imageId, $imageType)) {
            return false;
        }

        $tagsString  = null;
        $pageSection = $ctx->section;
        if ($pageSection === 'tags') {
            $tagsString = implode(',', array_map(static fn (int $v): string => (string) $v, $ctx->tagIds));
            if (strlen($tagsString) > 50) {
                $tagsString = substr($tagsString, 0, 50);
                $commaPos   = strrpos($tagsString, ',');
                if ($commaPos !== false) {
                    $tagsString = substr($tagsString, 0, $commaPos);
                }
            }
        }

        /** @var mixed $ipRaw */
        $ipRaw = $_SERVER['REMOTE_ADDR'] ?? '';
        $ip    = is_string($ipRaw) ? $ipRaw : '';
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
                $historySections   = SchemaHelper::getEnums(Tables::history(), 'section');
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
}
