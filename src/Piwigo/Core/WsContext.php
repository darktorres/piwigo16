<?php

declare(strict_types=1);

namespace Piwigo\Core;

/**
 * Marks whether the current request is dispatched through `ws.php`,
 * a typed replacement for the raw `IN_WS` constant (`defined('IN_WS')`
 * reads).
 *
 * Container-shared, immutable value: fixed once at container-build time
 * (`Piwigo\Core\Container`'s own build() method, threaded from
 * `public/ws.php`, the one entry-shell file that knows it's really being
 * dispatched through ws.php), never mutated afterward during a request --
 * no "current instance" concept needed at all.
 */
final readonly class WsContext
{
    public function __construct(
        private bool $active = false,
    ) {}

    public function isActive(): bool
    {
        return $this->active;
    }
}
