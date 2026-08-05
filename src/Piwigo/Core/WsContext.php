<?php

declare(strict_types=1);

namespace Piwigo\Core;

/**
 * "This request is dispatched through ws.php" marker -- Legacy Coupling
 * Retirement gap-closure (entry-shell define()/include round, Part 0b),
 * typed replacement for the raw IN_WS constant (`defined('IN_WS')`
 * reads). Same shape as Piwigo\Core\AdminContext -- see AdminContext's
 * own docblock for why reset() exists.
 *
 * Container-shared, immutable value (singleton/service-locator elimination
 * campaign, Phase 3): the value is fixed once, at container-build time
 * (`Piwigo\Core\Container`'s own build() method, threaded from
 * `public/ws.php`, the one entry-shell file that knows it's really being
 * dispatched through ws.php),
 * never mutated afterward during a request -- no "current instance"
 * concept needed at all (same lesson as the Phase 0 `CurrentPersistentCache`
 * pilot). Its own `isActiveStatic()` transitional bridge was deleted in
 * Phase 11 sub-phase 11G once Admin\Upload\UploadService::addUploadedFile()
 * (its last real caller) converted to real constructor injection.
 */
final class WsContext
{
    public function __construct(
        private readonly bool $active = false,
    ) {}

    public function isActive(): bool
    {
        return $this->active;
    }
}
