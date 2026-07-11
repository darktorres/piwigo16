<?php

declare(strict_types=1);

use Piwigo\Routing\Router;
use function DI\factory;

// DI\autowire() is the default -- a service with only typed class-reference
// constructor params needs no entry here at all; PHP-DI resolves it via
// reflection. Add an explicit entry only for:
//   - interface bindings (e.g. SomeInterface::class => \DI\get(SomeImpl::class))
//   - non-obvious construction (config values, factory methods, conditional logic)
//   - unresolvable string/config parameters
//
// This grows incrementally, one entry at a time, as later phases find a
// concrete class that genuinely needs one -- never pre-populated ahead of
// need. See src/Piwigo/Core/Container.php.

/**
 * @return array<string, mixed>
 */
return [
    // Unresolvable string param (the routes file path) -- Router::fromFile()
    // needs a path autowire can't provide.
    Router::class => factory(static fn (): Router => Router::fromFile(dirname(__DIR__) . '/config/routes.php')),
];
