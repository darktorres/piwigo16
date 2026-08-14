<?php

declare(strict_types=1);

namespace Piwigo\PluginConfig;

use Closure;
use Error;
use LogicException;
use Override;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\EventDispatcher\StoppableEventInterface;
use ReflectionFunction;

/**
 * Plugin event-handler registry, held as a container-shared instance --
 * the sole source of truth for registered handlers (no parallel global
 * variable mirrors this state).
 *
 * Implements PSR-14 (`Psr\EventDispatcher\EventDispatcherInterface`) via
 * `dispatch()`, a thin alias over `dispatchChange()` -- matches this
 * codebase's own PSR-11/PSR-7/PSR-15/PSR-3 conformance elsewhere. Not a
 * delegation to Symfony's own concrete `EventDispatcher`: `addEventHandler()`'s
 * own string-keyed legacy registration, `addTypedHandler()`'s priority-bucket
 * ordering (`ksort()` on each event's own priority map), `includePath`-based
 * lazy handler inclusion, and `callablesEqual()`'s custom closure-identity
 * dedup are all Piwigo-specific mechanics Symfony's own dispatcher doesn't
 * provide. Every other method on this class is unrelated to and unaffected
 * by this interface.
 */
final class EventDispatcher implements EventDispatcherInterface
{
    // 'function' is declared string|array|object rather than PHPStan's
    // usual `callable` -- PHP's native `callable` type hint validates
    // callability EAGERLY, at registration time. That matters because
    // Bootstrap\RequestBootstrap registers 'pwg_image_resize' for the
    // 'UploadImageResize'/'UploadThumbnailResize' events, but that
    // function doesn't exist anywhere in this codebase; neither event is
    // ever actually triggered, so the registration is dead but harmless.
    // Callability is validated lazily, only at invocation
    // (call_user_func_array()), never at registration.
    /**
     * @var array<string, array<int, list<EventHandler>>>
     */
    private array $handlers = [];

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
        $this->handlers = [];
    }

    /**
     * Higher priority runs first, matching Symfony's own `EventDispatcher`
     * convention (the concrete dispatcher `../piwigo16-rewrite`'s own P27
     * reference implementation uses) -- established by `krsort()` below.
     *
     * @param array{0: object|string, 1: string}|object|string $func
     */
    public function addEventHandler(
        string $event,
        string|array|object $func,
        int $priority = 50,
        ?string $includePath = null,
    ): bool {
        if (isset($this->handlers[$event][$priority])) {
            foreach ($this->handlers[$event][$priority] as $handler) {
                if (self::callablesEqual($handler->function, $func)) {
                    return false;
                }
            }
        }

        $handlersAtPriority = $this->handlers[$event][$priority] ?? [];
        $handlersAtPriority[] = new EventHandler($func, $includePath);
        $this->handlers[$event][$priority] = $handlersAtPriority;

        krsort($this->handlers[$event]);
        return true;
    }

    /**
     * @param array{0: object|string, 1: string}|object|string $func
     */
    public function removeEventHandler(string $event, string|array|object $func, int $priority = 50): bool
    {
        if (! isset($this->handlers[$event][$priority])) {
            return false;
        }

        $handlersAtPriority = $this->handlers[$event][$priority];

        foreach ($handlersAtPriority as $i => $handler) {
            if (! self::callablesEqual($handler->function, $func)) {
                continue;
            }

            unset($handlersAtPriority[$i]);
            $handlersAtPriority = array_values($handlersAtPriority);

            if ($handlersAtPriority === []) {
                unset($this->handlers[$event][$priority]);
                if ($this->handlers[$event] === []) {
                    unset($this->handlers[$event]);
                }
            } else {
                $this->handlers[$event][$priority] = $handlersAtPriority;
            }

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
     * Modifier event: every registered handler for $event::class receives
     * and must return an instance of $event::class, in priority order.
     *
     * Callers must capture and use the return value, not the pre-dispatch
     * object -- a handler for a `readonly` event class cannot mutate its
     * argument in place (the language forbids it), so it can only convey a
     * change by returning a new instance. Relying on the original variable
     * instead would silently discard that. The @template makes this the
     * same instance for the common "handler mutates a non-readonly
     * property and returns $event" case too, so capturing the return value
     * is always correct and never a behavior change either way.
     *
     * Stoppable: if `$event` implements `Psr\EventDispatcher\
     * StoppableEventInterface` and `isPropagationStopped()` becomes true
     * after a handler runs, no further handlers are called -- matches
     * every other typed event class exactly (no changes needed to opt
     * in/out), zero behavior change for the 156 existing event classes
     * that don't implement the interface.
     *
     * @template T of object
     * @param T $event
     * @return T
     */
    public function dispatchChange(object $event): object
    {
        $eventClass = $event::class;

        if (isset($this->handlers[$eventClass])) {
            foreach ($this->handlers[$eventClass] as $handlersAtPriority) {
                foreach ($handlersAtPriority as $handler) {
                    if ($handler->includePath !== null && $handler->includePath !== '') {
                        include_once $handler->includePath;
                    }

                    if (! is_callable($handler->function)) {
                        throw new Error("Event handler for '{$eventClass}' is not callable.");
                    }

                    $result = call_user_func($handler->function, $event);

                    if (! $result instanceof $eventClass) {
                        throw new Error("Change handler for '{$eventClass}' must return an instance of {$eventClass}.");
                    }

                    $event = $result;

                    if ($event instanceof StoppableEventInterface && $event->isPropagationStopped()) {
                        break 2;
                    }
                }
            }
        }

        return $event;
    }

    /**
     * PSR-14 `EventDispatcherInterface::dispatch()` -- a safe alias over
     * `dispatchChange()`, which already takes and returns `object`. See
     * this class's own docblock for why this can't be a delegation to a
     * separate PSR-14 implementation instead.
     */
    #[Override]
    public function dispatch(object $event): object
    {
        return $this->dispatchChange($event);
    }

    /**
     * Notifier event: fire-and-forget, no return value captured.
     *
     * Stoppable: same as `dispatchChange()`'s own docblock -- a handler
     * wanting to stop propagation mutates a non-`readonly` property on
     * `$event` directly (no return value here to reassign from).
     */
    public function dispatchNotify(object $event): void
    {
        $eventClass = $event::class;

        if (! isset($this->handlers[$eventClass])) {
            return;
        }

        foreach ($this->handlers[$eventClass] as $handlersAtPriority) {
            foreach ($handlersAtPriority as $handler) {
                if ($handler->includePath !== null && $handler->includePath !== '') {
                    include_once $handler->includePath;
                }

                if (! is_callable($handler->function)) {
                    throw new Error("Event handler for '{$eventClass}' is not callable.");
                }

                call_user_func($handler->function, $event);

                if ($event instanceof StoppableEventInterface && $event->isPropagationStopped()) {
                    break 2;
                }
            }
        }
    }
}
