<?php

declare(strict_types=1);

namespace Piwigo\Activity;

/**
 * Activity domain business logic: request-context detection/enrichment
 * for pwg_activity()'s log line(s), plus thin read wrappers for
 * admin/user_activity.php's own dashboard queries. Constructor-injects
 * only ActivityRepository (plain constructor injection).
 *
 * record() itself stays a free-function delegate at its original call
 * sites (pwg_activity(), still in include/functions.inc.php) rather than
 * being replaced by direct DI everywhere -- it's called from dozens of
 * sites app-wide, same "wide-fanout write stays a free-function delegate"
 * shape as P17's get_pwg_token().
 */
final class ActivityService
{
    public function __construct(
        private readonly ActivityRepository $repo,
    ) {}

    /**
     * @param int|string|array<int, int|string> $objectId
     * @param array<string, mixed> $details
     */
    public function record(string $object, int|string|array $objectId, string $action, array $details = []): void
    {
        /** @var array<string, mixed> $user */
        global $user;

        $requestMethod = isset($_REQUEST['method']) && is_string($_REQUEST['method']) ? $_REQUEST['method'] : null;

        // in case of uploadAsync, do not log the automatic login as an independant activity
        if ($requestMethod === 'pwg.images.uploadAsync' && $action === 'login') {
            return;
        }

        if ($requestMethod === 'pwg.plugins.performAction') {
            // for example, if you "restore" a plugin, the internal sequence will perform deactivate/uninstall/install/activate.
            // We only want to keep the last call to record() with the "restore" action.
            $requestAction = isset($_REQUEST['action']) && is_string($_REQUEST['action']) ? $_REQUEST['action'] : null;
            if ($requestAction !== $action) {
                return;
            }
        }

        $objectIds = is_array($objectId) ? $objectId : [$objectId];

        if ($requestMethod !== null) {
            $details['method'] = $requestMethod;
        } else {
            $script = script_basename();
            $pageParam = isset($_GET['page']) && is_string($_GET['page']) ? $_GET['page'] : null;
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

        if (in_array($object, ['album', 'photo'], true) && $action === 'delete' && ($_GET['page'] ?? null) === 'site_update') {
            $details['sync'] = true;
        }

        if ($object === 'tag' && $action === 'delete' && isset($_POST['destination_tag'])) {
            $details['action'] = 'merge';
            $details['destination_tag'] = $_POST['destination_tag'];
        }

        $detailsInsert = serialize($details);
        $ipAddress = isset($_SERVER['REMOTE_ADDR']) && is_string($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : null;
        $sessionId = session_id();
        $sessionIdx = $sessionId !== false && $sessionId !== '' ? $sessionId : 'none';
        // Explicit, not left to the column's DEFAULT CURRENT_TIMESTAMP, so
        // this respects the frozen test-mode clock the same way other
        // pwg_now() call sites already do -- real behavior outside test
        // mode is unaffected.
        $occuredOn = pwg_now()
            ->format('Y-m-d H:i:s');

        $rows = [];
        foreach ($objectIds as $loopObjectId) {
            $performedBy = $user['id'] ?? 0; // on a plugin autoupdate, $user is not yet loaded
            $performedBy = is_numeric($performedBy) ? (int) $performedBy : 0;

            if ($action === 'logout') {
                $performedBy = is_numeric($loopObjectId) ? (int) $loopObjectId : 0;
            }

            $rows[] = [
                'object' => $object,
                'objectId' => $loopObjectId,
                'action' => $action,
                'performedBy' => $performedBy,
                'sessionIdx' => $sessionIdx,
                'ipAddress' => $ipAddress,
                'occuredOn' => $occuredOn,
                'details' => $detailsInsert,
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

    /**
     * @return list<array{object: string, action: string, counter: int}>
     */
    public function getActionCounts(?string $objectFilter): array
    {
        return $this->repo->findActionCounts($objectFilter);
    }

    /**
     * @return list<array{
     *   activity_id: int,
     *   performed_by: ?int,
     *   object: string,
     *   object_id: int,
     *   action: string,
     *   ip_address: ?string,
     *   occured_on: string,
     *   details: ?string,
     *   username: string,
     * }>
     */
    public function getUserObjectLogWithUsernames(string $usernameColumn, string $idColumn): array
    {
        return $this->repo->findUserObjectLogWithUsernames($usernameColumn, $idColumn);
    }
}
