<?php

declare(strict_types=1);

use Piwigo\Bootstrap\RouteDefinitions;

// Validates RouteDefinitions::all() generically: every route's
// `_controller` default names a loadable class or interface. Mirrors
// tests/Unit/Core/ContainerDefinitionsTest.php's own precedent.

test('every route has a _controller default naming a loadable class', function (): void {
    $missing = [];
    foreach (RouteDefinitions::all() as $name => $route) {
        $controller = $route->getDefault('_controller');
        $loadable = is_string($controller) && $controller !== ''
            && (class_exists($controller) || interface_exists($controller));
        if (! $loadable) {
            $missing[] = $name;
        }
    }
    expect($missing)
        ->toBe([]);
});
