<?php

declare(strict_types=1);

namespace Piwigo\Event\Lifecycle;

/**
 * Typed event for the legacy `load_conf` notification. No handler is
 * registered for it anywhere today. `$param` matches
 * `ConfigService::loadConfFromDb()`'s own `?string $param` -- null when
 * that call loaded every config row rather than one specific param.
 */
final readonly class LoadConf
{
    public function __construct(
        public ?string $param,
    ) {}
}
