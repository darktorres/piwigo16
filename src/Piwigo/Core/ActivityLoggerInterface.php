<?php

declare(strict_types=1);

namespace Piwigo\Core;

/**
 * `Piwigo\Activity\ActivityService`'s free-function delegate
 * `pwg_activity()` (include/functions.inc.php) has real `L2aCoreDomain`
 * callers (`Users\UserService`, `Group\GroupService`, `Auth\AuthService`).
 * This interface lives in `Piwigo\Core` (`L1Infrastructure`, same direction
 * as `MailerInterface`) so those classes can depend downward on it instead
 * of the concrete class. `ActivityService` implements it; bound in
 * `config/container.php`. `Activity` and its L2a callers are the same
 * layer today, so this interface indirection isn't required by deptrac
 * anymore, but removing it is a separate decision.
 */
interface ActivityLoggerInterface
{
    /**
     * $details is a genuinely heterogeneous per-action log payload (config
     * diffs, batch-edit field lists, install metadata, permission grants,
     * ...) -- 40+ real call sites across Admin/Controller/domain
     * services each build their own one-off shape, matching PSR-3
     * LoggerInterface's own `$context` parameter, not a single reusable
     * domain concept.
     *
     * @param int|string|array<int, int|string> $objectId
     * @param array<string, mixed> $details
     */
    public function record(string $object, int|string|array $objectId, string $action, array $details = []): void;
}
