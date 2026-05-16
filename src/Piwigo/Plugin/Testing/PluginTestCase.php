<?php

declare(strict_types=1);

namespace Piwigo\Plugin\Testing;

use DI\Container;
use DI\ContainerBuilder;
use PHPUnit\Framework\TestCase;
use Piwigo\Plugin\PluginInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\EventDispatcher\EventDispatcher;

/**
 * Base test class for plugin authors.
 *
 * Spins up an isolated PHP-DI container with a Symfony EventDispatcher
 * + NullLogger pre-bound and exposes:
 *
 *  - `bindService($id, $service)` — register a service or stub for the
 *    plugin to consume via `boot($container)`.
 *  - `bootPlugin($plugin)` — calls `$plugin->boot($this->container)` then
 *    registers each entry of `$plugin->subscribedEvents()` with the
 *    dispatcher so subsequent `dispatch()` calls flow through the plugin.
 *  - `dispatch($event)` — convenience wrapper returning the same event
 *    instance after PSR-14 dispatch (Symfony idiom).
 *
 * Unused inside this repository — there are no in-tree plugins yet.
 * Shipped under `src/` (not `tests/`) so external plugin packages can
 * inherit it once they `composer require piwigo/piwigo`.
 *
 * @api  Plugin authors extend this in their own test suite.
 */
abstract class PluginTestCase extends TestCase
{
    protected Container $container;

    protected EventDispatcher $dispatcher;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->dispatcher = new EventDispatcher();
        $this->container = (new ContainerBuilder())->build();
        $this->container->set(EventDispatcherInterface::class, $this->dispatcher);
        $this->container->set(LoggerInterface::class, new NullLogger());
    }

    /**
     * Bind a service (or stub) into the test container so the plugin's
     * `boot()` can pull it from `$container`.
     */
    protected function bindService(string $id, object $service): void
    {
        $this->container->set($id, $service);
    }

    /**
     * Boot the plugin against the test container and register every
     * declared event subscriber with the dispatcher. Returns the same
     * plugin instance so the call chain reads naturally.
     */
    protected function bootPlugin(PluginInterface $plugin): PluginInterface
    {
        $plugin->boot($this->container);

        foreach ($plugin->subscribedEvents() as $eventClass => $spec) {
            foreach (self::normalizeSubscribeSpec($spec) as [$method, $priority]) {
                $this->dispatcher->addListener(
                    $eventClass,
                    static function (object $event) use ($plugin, $method): void {
                        // Plugin authors keep handler methods on $plugin itself —
                        // this mirrors the production wiring done by PluginRegistry.
                        $plugin->{$method}($event);
                    },
                    $priority,
                );
            }
        }
        return $plugin;
    }

    /**
     * Dispatch a typed event through the test dispatcher. PSR-14 returns
     * the *same* event instance after every listener has run, so callers
     * read mutated fields (selective-mutability pattern from B6) off the
     * argument.
     */
    protected function dispatch(object $event): object
    {
        return $this->dispatcher->dispatch($event);
    }

    /**
     * Normalize one entry of subscribedEvents() into a list of
     * `[$method, $priority]` pairs.
     *
     * Accepted shapes (mirrors Symfony's `EventSubscriberInterface`):
     *  - `'methodName'`                        — single handler, priority 0
     *  - `['methodName', 17]`                  — single handler with priority
     *  - `[['methodA', 0], ['methodB', 5], …]` — multiple handlers
     *
     * @param string|array{0:string,1?:int}|list<array{0:string,1?:int}> $spec
     * @return list<array{0:string,1:int}>
     */
    private static function normalizeSubscribeSpec(string|array $spec): array
    {
        if (is_string($spec)) {
            return [[$spec, 0]];
        }
        if (is_string($spec[0] ?? null)) {
            /** @var array{0:string,1?:int} $spec */
            return [[$spec[0], $spec[1] ?? 0]];
        }
        $out = [];
        foreach ($spec as $entry) {
            /** @var array{0:string,1?:int} $entry */
            $out[] = [$entry[0], $entry[1] ?? 0];
        }
        return $out;
    }
}
