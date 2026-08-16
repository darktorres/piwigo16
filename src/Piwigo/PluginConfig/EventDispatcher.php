<?php

declare(strict_types=1);

namespace Piwigo\PluginConfig;

use Closure;
use Error;
use LogicException;
use Override;
use Psr\EventDispatcher\EventDispatcherInterface;
use ReflectionFunction;
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
 * **P32 Stage A3**: storage/dispatch is now delegated to a real
 * `Symfony\Component\EventDispatcher\EventDispatcher` (`$inner`), matching
 * Symfony's own priority convention (higher runs first) natively -- no
 * more hand-rolled `krsort()`. `addTypedHandler()`'s own `callable
 * $handler` param is already a real, currently-valid callable by the time
 * PHP lets it through, so nothing needs wrapping there; `addEventHandler()`
 * still allows registering a not-yet-existing function name (see its own
 * comment below), which Symfony's own `callable|array`-typed
 * `addListener()` would reject eagerly if handed the raw value -- every
 * `addEventHandler()` registration is wrapped in a small deferred closure
 * to preserve that, tracked in `$wrapped` so `removeEventHandler()` can
 * find it again by the caller-visible original callable
 * (`callablesEqual()`'s dedup semantics, unchanged). `addTypedHandler()`
 * still delegates to `addEventHandler()` (unchanged, own dedup contract
 * preserved) rather than calling `$inner->addListener()` directly, so
 * every registration -- typed or not -- flows through the one tracked
 * path `removeEventHandler()` already knows how to search.
 *
 * `addEventHandler()`/`removeEventHandler()`/`includePath`/
 * `callablesEqual()` are all still here, unchanged in observable
 * behaviour -- P32 Stage A4 deletes them for real, once every caller using
 * them is migrated. `EventHandler` itself is already gone: A3's own
 * redesign made it genuinely dead the moment `$wrapped` (a plain tuple,
 * not that value object) replaced it as the tracked-registration side
 * table, so it was deleted here rather than kept as an unreferenced file
 * until A4. Not adopting Symfony's own `EventSubscriberInterface`
 * anywhere in this codebase: see `Listener\ListenerInterface`'s own
 * docblock for why (a real, verified blocker -- its `getSubscribedEvents()`
 * is `static`, so it has no `$this` for the bound-closure registration
 * style every implementor here relies on).
 */
final class EventDispatcher implements EventDispatcherInterface
{
    private SymfonyEventDispatcher $inner;

    /**
     * Tracks every `addEventHandler()` registration (including the ones
     * `addTypedHandler()` makes on a caller's behalf) as [the original,
     * caller-visible callable, the deferred wrapper closure actually
     * registered on `$inner`] pairs, keyed by event -- `removeEventHandler()`
     * needs this to translate a removal request (given in terms of the
     * original callable) into the wrapper Symfony's own `removeListener()`
     * actually has registered; `$inner` alone has no way to look that up,
     * since two structurally-different closures are never `==`-equal.
     *
     * @var array<string, list<array{0: array{0: object|string, 1: string}|object|string, 1: Closure}>>
     */
    private array $wrapped = [];

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
        $this->wrapped = [];
    }

    /**
     * 'function' is declared string|array|object rather than PHPStan's
     * usual `callable` -- PHP's native `callable` type hint validates
     * callability EAGERLY, at registration time. That matters because
     * Bootstrap\RequestBootstrap registers 'pwg_image_resize' for the
     * 'UploadImageResize'/'UploadThumbnailResize' events, but that
     * function doesn't exist anywhere in this codebase; neither event is
     * ever actually triggered, so the registration is dead but harmless.
     * Callability is validated lazily, only at invocation
     * (call_user_func()), never at registration -- the same reason every
     * registration here is wrapped in a deferred closure rather than
     * handed to `$inner->addListener()` as-is: Symfony's own `callable|
     * array`-typed parameter would validate `$func` eagerly the moment
     * PHP binds the argument, which is exactly the eager check this
     * class exists to not do.
     *
     * @param array{0: object|string, 1: string}|object|string $func
     */
    public function addEventHandler(
        string $event,
        string|array|object $func,
        int $priority = 50,
        ?string $includePath = null,
    ): bool {
        foreach ($this->wrapped[$event] ?? [] as [$existingFunc]) {
            if (self::callablesEqual($existingFunc, $func)) {
                return false;
            }
        }

        $wrapper = static function (object $dispatchedEvent) use ($func, $includePath): void {
            if ($includePath !== null && $includePath !== '') {
                include_once $includePath;
            }

            if (! is_callable($func)) {
                throw new Error('Event handler is not callable.');
            }

            call_user_func($func, $dispatchedEvent);
        };

        $this->wrapped[$event][] = [$func, $wrapper];
        $this->inner->addListener($event, $wrapper, $priority);

        return true;
    }

    /**
     * @param array{0: object|string, 1: string}|object|string $func
     */
    public function removeEventHandler(string $event, string|array|object $func, int $priority = 50): bool
    {
        foreach ($this->wrapped[$event] ?? [] as $i => [$existingFunc, $wrapper]) {
            if (! self::callablesEqual($existingFunc, $func)) {
                continue;
            }

            $eventWrapped = $this->wrapped[$event];
            unset($eventWrapped[$i]);

            if ($eventWrapped === []) {
                unset($this->wrapped[$event]);
            } else {
                $this->wrapped[$event] = array_values($eventWrapped);
            }

            $this->inner->removeListener($event, $wrapper);

            return true;
        }

        return false;
    }

    /**
     * Thin ergonomic wrapper over addEventHandler() for typed-event
     * registration -- real per-event handler-signature checking at
     * registration sites, with zero change to storage/dispatch internals
     * ($event is a class-string, itself just a string; the untyped
     * registry doesn't need to know the difference). Higher priority runs
     * first -- see addEventHandler()'s own docblock.
     *
     * @template T of object
     * @param class-string<T> $event
     * @param callable(T): (T|void) $handler
     */
    public function addTypedHandler(string $event, callable $handler, int $priority = 50): bool
    {
        // addEventHandler()'s own $func param is intentionally NOT `callable`
        // (see its own docblock), so this boundary needs a real narrow, not
        // a cast: PHP's `callable` type has no other shape than
        // string|array|object, and PHPStan verifies that structurally via
        // these three checks rather than trusting an @var override.
        if (is_string($handler) || is_array($handler) || is_object($handler)) {
            return $this->addEventHandler($event, $handler, $priority);
        }

        throw new LogicException('Unreachable: a callable is always string, array, or object.');
    }

    /**
     * Faithfully replicates PHP's `==` semantics for two callables (this
     * project's PHPStan rules disallow the operator itself) -- string and
     * array callables always compare identically under `==`/`===`, but
     * Closures don't: `$this->method(...)` produces a NEW Closure instance
     * on every evaluation, so registering/removing the same bound method
     * across separate calls (the real add_event_handler()/
     * remove_event_handler() usage pattern, see
     * src/Piwigo/Admin/Integrity/C13yInternal.php) needs the loose,
     * same-binding comparison `==` gives, not `===` identity.
     *
     * @param array{0: object|string, 1: string}|object|string $a
     * @param array{0: object|string, 1: string}|object|string $b
     */
    private static function callablesEqual(string|array|object $a, string|array|object $b): bool
    {
        if ($a instanceof Closure && $b instanceof Closure) {
            $ra = new ReflectionFunction($a);
            $rb = new ReflectionFunction($b);

            // getClosureScopeClass() returns a fresh ReflectionClass wrapper
            // on every call, so compare by class name, not object identity.
            return $ra->getClosureThis() === $rb->getClosureThis()
                && $ra->getClosureScopeClass()?->getName() === $rb->getClosureScopeClass()?->getName()
                && $ra->getName() === $rb->getName();
        }

        return $a === $b;
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
     * turns true, identically to what this method used to do by hand.
     * `call_user_func()`'s own native "undefined function" `Error` (raised
     * from inside each `addEventHandler()`-registered deferred wrapper --
     * see that method's own docblock) already reproduces the "not
     * callable" guard this method used to raise explicitly.
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
