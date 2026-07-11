<?php

declare(strict_types=1);

use Symfony\Component\Routing\RouteCollection;

// Validates config/routes.php generically: every route's `_controller`
// default names a loadable class or interface. Passes trivially today --
// config/routes.php is empty -- stays permanently useful as later phases
// (starting P22) add real routes. Mirrors
// tests/Unit/Core/ContainerDefinitionsTest.php's P8 precedent.

function loadRouteCollection(): RouteCollection
{
    $routes = require __DIR__ . '/../../../config/routes.php';
    if (!$routes instanceof RouteCollection) {
        throw new RuntimeException('config/routes.php must return a RouteCollection');
    }

    return $routes;
}

test('every route has a _controller default naming a loadable class', function (): void {
    $missing = [];
    foreach (loadRouteCollection() as $name => $route) {
        $controller = $route->getDefault('_controller');
        $loadable = is_string($controller) && $controller !== ''
            && (class_exists($controller) || interface_exists($controller));
        if (!$loadable) {
            $missing[] = $name;
        }
    }
    expect($missing)->toBe([]);
});
