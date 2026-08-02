<?php

declare(strict_types=1);

namespace Piwigo\Admin\Integrity\Event;

use Piwigo\Admin\Integrity\CheckIntegrity;

/**
 * Typed event for the legacy `list_check_integrity` filter (notify).
 * Registered 3x (`C13yInternal::registerHandlers()`). Lives under
 * `Piwigo\Admin\Integrity\Event\`, not `Piwigo\Event\Picture\`, since it
 * carries a real `Piwigo\Admin\Integrity\CheckIntegrity` instance --
 * deptrac's L0Data layer may depend on nothing. `$value` stays readonly:
 * handlers mutate the CheckIntegrity instance itself (`$event->value->
 * add_anomaly(...)`), not the event's own property.
 */
final readonly class ListCheckIntegrity
{
    public function __construct(
        public CheckIntegrity $value,
    ) {}
}
