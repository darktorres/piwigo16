<?php

declare(strict_types=1);

namespace Piwigo\Core;

/**
 * Marks whether the current request is dispatched through the
 * machine-facing `/api/v1` surface (`public/api.php`), rather than a
 * regular HTML page.
 *
 * Container-shared, immutable value: fixed once at container-build time
 * (`Piwigo\Core\Container`'s own build() method, threaded from
 * `public/api.php`, the one entry-shell file that knows it's really
 * being dispatched through the API), never mutated afterward during a
 * request -- no "current instance" concept needed at all.
 */
final readonly class ApiContext
{
    public function __construct(
        private bool $active = false,
    ) {}

    public function isActive(): bool
    {
        return $this->active;
    }
}
