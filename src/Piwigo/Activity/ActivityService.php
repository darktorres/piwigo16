<?php

declare(strict_types=1);

namespace Piwigo\Activity;

use LogicException;
use Override;
use Piwigo\Activity\Projection\UserActivityLogEntry;
use Piwigo\Activity\Request\ActivityContextRequest;
use Piwigo\Common\ValueObject\IpAddress;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\ActivityLoggerInterface;
use Piwigo\Core\Env;
use Piwigo\Core\Kernel;
use Piwigo\Core\PageFilterHelper;
use Piwigo\Users\CurrentUser;

/**
 * Activity domain business logic: request-context detection/enrichment
 * for pwg_activity()'s log line(s), plus thin read wrappers for
 * admin/user_activity.php's own dashboard queries. Constructor-injects
 * only ActivityRepository (plain constructor injection).
 *
 * Implements {@see \Piwigo\Core\ActivityLoggerInterface} so the
 * `L2aCoreDomain` classes that call record() (UserService/GroupService/
 * AuthService) can constructor-inject it without a forbidden
 * `L2a` -> `L2b` dependency (see that interface's own docblock); every
 * other caller retargets straight to this class.
 */
final readonly class ActivityService implements ActivityLoggerInterface
{
    public function __construct(
        private ActivityRepository $repo,
    ) {}

    /**
     * Container resolve, not a constructor property -- used only inside
     * record()'s own internal wasRealUserResolved() read below. A
     * required constructor param here would ripple across this class's
     * own many `new ActivityService(...)` call sites across the
     * codebase, especially in test files. Falls back to a fresh,
     * unmemoized instance when Kernel::isBooted() is false.
     */
    private function currentUser(): CurrentUser
    {
        if (Kernel::isBooted()) {
            $currentUser = Kernel::container()->get(CurrentUser::class);
            if (! $currentUser instanceof CurrentUser) {
                throw new LogicException('Container returned an unexpected type for ' . CurrentUser::class);
            }

            return $currentUser;
        }

        return new CurrentUser(new CurrentConfig());
    }

    /**
     * Same "container resolve, not a constructor property" reasoning as
     * currentUser() above -- used only inside record()'s own internal
     * PageFilterHelper::scriptBasename() call below.
     */
    private function currentConfig(): CurrentConfig
    {
        if (Kernel::isBooted()) {
            $currentConfig = Kernel::container()->get(CurrentConfig::class);
            if (! $currentConfig instanceof CurrentConfig) {
                throw new LogicException('Container returned an unexpected type for ' . CurrentConfig::class);
            }

            return $currentConfig;
        }

        return new CurrentConfig();
    }

    /**
     * @param int|string|array<int, int|string> $objectId
     * @param array<string, mixed> $details
     */
    #[Override]
    public function record(string $object, int|string|array $objectId, string $action, array $details = []): void
    {
        $activityContextRequest = ActivityContextRequest::fromGlobals();

        $requestMethod = $activityContextRequest->requestMethod;

        // in case of uploadAsync, do not log the automatic login as an independant activity
        if ($requestMethod === 'pwg.images.uploadAsync' && $action === 'login') {
            return;
        }

        if ($requestMethod === 'pwg.plugins.performAction') {
            // for example, if you "restore" a plugin, the internal sequence will perform deactivate/uninstall/install/activate.
            // We only want to keep the last call to record() with the "restore" action.
            $requestAction = $activityContextRequest->requestAction;
            if ($requestAction !== $action) {
                return;
            }
        }

        $objectIds = is_array($objectId) ? $objectId : [$objectId];

        if ($requestMethod !== null) {
            $details['method'] = $requestMethod;
        } else {
            $script = PageFilterHelper::scriptBasename($this->currentConfig());
            $pageParam = $activityContextRequest->pageParam;
            $details['script'] = $script === 'admin' && $pageParam !== null ? $script . '/' . $pageParam : $script;
        }

        if ($action === 'autoupdate') {
            // autoupdate on a plugin can happen anywhere, the "script/method" is not meaningfull
            unset($details['method'], $details['script']);
        }

        $userAgentHeader = isset($_SERVER['HTTP_USER_AGENT']) && is_string($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : null;

        $userAgent = null;
        if ($object === 'user' && $action === 'login' && $userAgentHeader !== null) {
            $userAgent = strip_tags($userAgentHeader);
        }

        $connectedWith = $_SESSION['connected_with'] ?? null;
        if ($connectedWith === 'api_key' && $userAgentHeader !== null) {
            $details['connected_with'] = 'api_key';
            $userAgent = strip_tags($userAgentHeader);
        }

        // we want to know if the login is automatic with remember_me (auto_login)
        // or with an authentication key provided in the URL (auth_key_login)
        if ($object === 'user' && $action === 'login') {
            $calledFunctions = array_flip(array_column(debug_backtrace(), 'function'));
            foreach (['auto_login', 'auth_key_login'] as $authFunction) {
                if (isset($calledFunctions[$authFunction])) {
                    $details['auth_function'] = $authFunction;
                }
            }
        }

        if ($object === 'photo' && $action === 'add' && ! isset($details['sync'])) {
            $details['added_with'] = 'app';
            $referer = isset($_SERVER['HTTP_REFERER']) && is_string($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : '';
            if (preg_match('/page=photos_add/', $referer) === 1) {
                $details['added_with'] = 'browser';
            }
        }

        if (in_array($object, ['album', 'photo'], true) && $action === 'delete' && $activityContextRequest->pageParam === 'site_update') {
            $details['sync'] = true;
        }

        if ($object === 'tag' && $action === 'delete' && $activityContextRequest->destinationTagPresent) {
            $details['action'] = 'merge';
            $details['destination_tag'] = $activityContextRequest->destinationTag;
        }

        $ipAddress = IpAddress::fromRemoteAddr();
        $sessionId = session_id();
        $sessionIdx = $sessionId !== false && $sessionId !== '' ? $sessionId : 'none';
        // Explicit, not left to the column's DEFAULT CURRENT_TIMESTAMP, so
        // this respects the frozen test-mode clock the same way other
        // Env::now() call sites already do -- real behavior outside test
        // mode is unaffected.
        $occuredOn = Env::now()
            ->format('Y-m-d H:i:s');

        $rows = [];
        foreach ($objectIds as $loopObjectId) {
            // activity.performed_by has an ON DELETE SET NULL foreign key
            // to users.id, and 0 is not a valid user id (AUTO_INCREMENT
            // starts at 1), so a plugin autoupdate with no real user
            // resolved yet records null (the column's "unknown actor"
            // value, matching the FK's own semantics for a since-deleted
            // user) rather than 0.
            //
            // CurrentUser::wasRealUserResolved() is used rather than
            // CurrentUser::isInitialized(): attachGlobals()
            // unconditionally guest-seeds CurrentUser on every bootstrap
            // path (including CLI), so isInitialized() alone would
            // misattribute these rows to the guest user instead of
            // recording 'unknown actor' (null).
            $performedBy = $this->currentUser()
                ->wasRealUserResolved() ? $this->currentUser()
                ->get()
                ->id->value : null;

            if ($action === 'logout') {
                $performedBy = is_numeric($loopObjectId) ? (int) $loopObjectId : null;
            }

            $rows[] = [
                'object' => $object,
                'objectId' => $loopObjectId,
                'action' => $action,
                'performedBy' => $performedBy,
                'sessionIdx' => $sessionIdx,
                'ipAddress' => $ipAddress,
                'occuredOn' => $occuredOn,
                'details' => $details,
                'userAgent' => $userAgent,
            ];
        }

        $this->repo->insertMany($rows);
    }

    /**
     * @return array<int, int> performed_by => count
     */
    public function getCountByUser(): array
    {
        return $this->repo->countByUser();
    }

    public function getMinOccuredOn(): ?string
    {
        return $this->repo->findMinOccuredOn();
    }

    public function getMaxOccuredOn(): ?string
    {
        return $this->repo->findMaxOccuredOn();
    }

    public function getOccuredOnForObject(int $objectId, string $object, string $action): ?string
    {
        return $this->repo->findOccuredOnForObject($objectId, $object, $action);
    }

    /**
     * @return list<array{object: string, action: string, counter: int}>
     */
    public function getActionCounts(?string $objectFilter): array
    {
        return $this->repo->findActionCounts($objectFilter);
    }

    /**
     * @return list<UserActivityLogEntry>
     */
    public function getUserObjectLogWithUsernames(): array
    {
        return $this->repo->findUserObjectLogWithUsernames();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getPaginated(ActivityListCriteria $criteria, int $limit, int $offset): array
    {
        return $this->repo->findPaginated($criteria, $limit, $offset);
    }

    /**
     * @return list<array{activity_day: string, object: string, action: string, counter: int}>
     */
    public function getDailyActionCountsSince(string $sinceDate): array
    {
        return $this->repo->findDailyActionCountsSince($sinceDate);
    }

    /**
     * @return list<array{action: string, occured_on: ?string, details: ?string}>
     */
    public function getCoreUpdateHistory(): array
    {
        return $this->repo->findCoreUpdateHistory();
    }

    /**
     * @return list<array{object: string, object_id: int, action: string, counter: int}>
     */
    public function getSystemActionCountsByObjectId(): array
    {
        return $this->repo->findSystemActionCountsByObjectId();
    }

    /**
     * @return list<array{user_agent: ?string, counter: int, first_encounter: ?string, last_encounter: ?string}>
     */
    public function getUserAgentBreakdown(): array
    {
        return $this->repo->findUserAgentBreakdown();
    }
}
