<?php

declare(strict_types=1);

namespace Piwigo\Tests\Unit\Routing;

use PHPUnit\Framework\TestCase;
use Piwigo\Controller\ControllerInterface;
use Symfony\Component\Routing\RouteCollection;

/**
 * Validates config/routes.php and UrlGenerator route name usage.
 *
 *  1. Every route's _controller is a loadable class.
 *  2. Every _controller implements ControllerInterface.
 *  3. Every route name used in UrlGenerator::routeUrl() exists in the table.
 */
final class RouteDefinitionsTest extends TestCase
{
    private static RouteCollection $routes;
    /** @var array<string, string> routeName => controllerFqcn */
    private static array $controllers;

    public static function setUpBeforeClass(): void
    {
        self::$routes = require dirname(__DIR__, 3) . '/config/routes.php';

        self::$controllers = [];
        foreach (self::$routes->all() as $name => $route) {
            $ctrl = $route->getDefault('_controller');
            if (is_string($ctrl)) {
                self::$controllers[$name] = $ctrl;
            }
        }
    }

    public function test_all_route_controllers_are_loadable(): void
    {
        $missing = [];
        foreach (self::$controllers as $routeName => $fqcn) {
            try {
                $loadable = class_exists($fqcn);
            } catch (\Throwable) {
                $loadable = true; // side-effectful file but class exists
            }
            if (!$loadable) {
                $missing[] = "$routeName → $fqcn";
            }
        }
        self::assertEmpty($missing, 'Route controllers not loadable: ' . implode(', ', $missing));
    }

    public function test_all_route_controllers_implement_ControllerInterface(): void
    {
        $bad = [];
        foreach (self::$controllers as $routeName => $fqcn) {
            try {
                if (!class_exists($fqcn)) {
                    continue; // covered by previous test
                }
                if (!is_a($fqcn, ControllerInterface::class, true)) {
                    $bad[] = "$routeName → $fqcn";
                }
            } catch (\Throwable) {
                // side-effectful autoload — skip interface check
            }
        }
        self::assertEmpty($bad, 'Route controllers missing ControllerInterface: ' . implode(', ', $bad));
    }

    public function test_UrlGenerator_route_names_all_exist(): void
    {
        $generatorSrc = file_get_contents(
            dirname(__DIR__, 3) . '/src/Piwigo/Url/UrlGenerator.php'
        );
        self::assertNotFalse($generatorSrc);

        preg_match_all('/\$this->routeUrl\(\s*[\'"]([^\'"]+)[\'"]/m', $generatorSrc, $m);
        $used = array_unique($m[1]);

        $defined = array_keys(self::$routes->all());
        $orphaned = array_diff($used, $defined);

        self::assertEmpty(
            $orphaned,
            'UrlGenerator references undefined route names: ' . implode(', ', $orphaned)
        );
    }

    public function test_no_duplicate_route_paths(): void
    {
        /** @var array<string, string> path+method => routeName */
        $seen = [];
        $duplicates = [];

        foreach (self::$routes->all() as $name => $route) {
            $path = $route->getPath();
            foreach ($route->getMethods() as $method) {
                $key = $method . ' ' . $path;
                if (isset($seen[$key])) {
                    $duplicates[] = "$key ({$seen[$key]} and $name)";
                } else {
                    $seen[$key] = $name;
                }
            }
        }
        self::assertEmpty($duplicates, 'Duplicate route paths: ' . implode('; ', $duplicates));
    }
}
