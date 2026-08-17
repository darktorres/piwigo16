<?php

declare(strict_types=1);

namespace Piwigo\PluginConfig;

use Override;
use Piwigo\Core\SubscriberInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\EventDispatcher\EventDispatcher as SymfonyEventDispatcher;

/**
 * Plugin event-handler registry, held as a container-shared instance --
 * the sole source of truth for registered handlers (no parallel global
 * variable mirrors this state).
 *
 * Implements PSR-14 (`Psr\EventDispatcher\EventDispatcherInterface`) via
 * `dispatch()`, the single verb for both value-transformation ("filter")
 * and fire-and-forget ("notify") dispatch -- matches this codebase's own
 * PSR-11/PSR-7/PSR-15/PSR-3 conformance elsewhere.
 *
 * Storage/dispatch is a thin, direct wrapper over a real
 * `Symfony\Component\EventDispatcher\EventDispatcher` (`$inner`) --
 * `addTypedHandler()`/`removeTypedHandler()` pass straight through to
 * `$inner->addListener()`/`removeListener()`, no wrapping or side table.
 * Every real caller -- `src/Piwigo/Admin/Integrity/C13yInternal.php`
 * included -- registers via `addTypedHandler()`; there is no
 * not-yet-existing-function-name registration path (PHP's native
 * `callable` type on that method's parameter validates eagerly, same as
 * Symfony's own `addListener()`). Symfony's own `removeListener()`
 * compares callables correctly with no dedup helper needed here
 * (verified: PHP's native `==` on two `$this->method(...)`-produced
 * Closures is true for the same bound object + method, exactly the
 * loose-but-correct comparison a fresh Closure needs on every
 * registration call).
 *
 * Not adopting Symfony's own `EventSubscriberInterface` anywhere in this
 * codebase: see `Core\SubscriberInterface`'s own docblock for why (a
 * real, verified blocker -- its `getSubscribedEvents()` is `static`, so it
 * has no `$this` for the bound-closure registration style every
 * implementor here relies on).
 */
final class EventDispatcher implements EventDispatcherInterface
{
    private SymfonyEventDispatcher $inner;

    public function __construct()
    {
        $this->inner = new SymfonyEventDispatcher();
    }

    /**
     * Test-only. Clears every registered handler back to a pristine state
     * -- same observable end state as a fresh `new self()`, but mutates the
     * shared instance in place instead of replacing which object every
     * other constructor-injected holder's reference points at (real
     * callers -- Kernel::container()->get(self::class)->reset() -- need
     * this so a booted container's shared instance can be reset between
     * tests, matching Translator's own reset() precedent).
     */
    public function reset(): void
    {
        $this->inner = new SymfonyEventDispatcher();
    }

    /**
     * Higher priority runs first, matching Symfony's own `EventDispatcher`
     * convention natively (its own `krsort()` on each event's priority
     * map).
     *
     * @template T of object
     * @param class-string<T> $event
     * @param callable(T): (T|void) $handler
     */
    public function addTypedHandler(string $event, callable $handler, int $priority = 50): void
    {
        $this->inner->addListener($event, $handler, $priority);
    }

    /**
     * @template T of object
     * @param class-string<T> $event
     * @param callable(T): (T|void) $handler
     */
    public function removeTypedHandler(string $event, callable $handler): void
    {
        $this->inner->removeListener($event, $handler);
    }

    /**
     * Registers every entry of a `Core\SubscriberInterface` implementor's
     * own `subscribedEvents()` map (a first-party `Listener\*` instance,
     * or a plugin/theme's `ExtensionInterface` instance) onto
     * `addTypedHandler()` -- the one shared implementation of this loop,
     * used by `Bootstrap\RequestBootstrap::registerListener()`,
     * `Bootstrap\UserResolutionMiddleware`'s early `AuthListener`
     * registration, `PluginConfig\PluginRegistry::bootActive()` and
     * `PluginConfig\ThemeRegistry::bootCurrent()`. `$subscriber` is
     * already fully constructed by the
     * caller; this method only wires its declared events onto the
     * dispatcher, in whatever order `subscribedEvents()` returns them.
     */
    public function registerSubscriber(SubscriberInterface $subscriber): void
    {
        foreach ($subscriber->subscribedEvents() as $eventClass => $handlers) {
            foreach (is_array($handlers) ? $handlers : [$handlers] as $handler) {
                $this->addTypedHandler($eventClass, $handler);
            }
        }
    }

    /**
     * PSR-14 `EventDispatcherInterface::dispatch()` -- the single verb for
     * every registered handler of $event::class, in priority order. A
     * handler mutates $event's own non-`readonly` field(s) directly (see
     * each event class's own docblock for which field is mutable, if
     * any); its return value, if any, is never read. A caller that cares
     * about a result reads it off the same $event reference it passed
     * in -- this method's own return value is the identical object,
     * returned for PSR-14 interface conformance and call-site chaining
     * convenience.
     *
     * Delegates straight to `$inner`: Symfony's own `dispatch()` already
     * handles priority ordering and stops calling further handlers once
     * `Psr\EventDispatcher\StoppableEventInterface::isPropagationStopped()`
     * turns true. No "not callable" guard is needed here -- PHP's own
     * `callable` type on `addTypedHandler()`'s own parameter already
     * rejects a not-yet-real callable at the registration call site
     * itself, before it ever reaches `$inner`.
     *
     * @template T of object
     * @param T $event
     * @return T
     */
    #[Override]
    public function dispatch(object $event): object
    {
        return $this->inner->dispatch($event);
    }
}
