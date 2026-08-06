<?php

declare(strict_types=1);

namespace Piwigo\PluginConfig;

use Closure;
use Error;
use LogicException;
use ReflectionFunction;

/**
 * Plugin event-handler registry -- container-shared instance (former
 * `get()` transitional bridge shim closed outright in sub-phase 12F-9,
 * same bridging pattern Piwigo\Session\SessionService::get()'s own former
 * shim had). Holds the state previously carried by include/
 * functions_plugins.inc.php's `global $pwg_event_handlers`
 * (grep-confirmed: never read from outside that file, safe to fully
 * internalize instead of keeping a parallel global in sync).
 */
final class EventDispatcher
{
    // 'function' is declared string|array|object rather than PHPStan's
    // usual `callable` -- PHP's native `callable` type hint validates
    // callability EAGERLY, at registration time. A real pre-existing bug
    // this surfaced: include/common.inc.php (formerly admin/include/
    // functions_upload.inc.php, relocated in P23 sub-batch 8b-3) registers
    // 'pwg_image_resize' for 'upload_image_resize'/'upload_thumbnail_resize',
    // but that function doesn't exist anywhere in this codebase (dead
    // legacy code -- confirmed absent from both reference branches too,
    // and those two events are never actually triggered). The original
    // `global $pwg_event_handlers` array never validated callability until
    // actual invocation (call_user_func_array()), so the dead registration
    // was silently harmless. Preserving that laziness here, rather than
    // "fixing" pwg_image_resize (out of this phase's scope).
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

        ksort($this->handlers[$event]);
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
     * registry doesn't need to know the difference).
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
     * Modifier event: $data flows through every registered handler in
     * priority order, each handler's return value feeding the next.
     * $extra (beyond $data) is forwarded unchanged to every handler --
     * matches the original's func_get_args()-based unbounded arity (real
     * callers pass extra context args, e.g.
     * trigger_change('format_exif_data', null, $filename, $map)).
     */
    public function triggerChange(string $event, mixed $data = null, mixed ...$extra): mixed
    {
        if (isset($this->handlers['trigger'])) {
            $this->triggerNotify('trigger', [
                'type' => 'event',
                'event' => $event,
                'data' => $data,
            ]);
        }

        if (! isset($this->handlers[$event])) {
            return $data;
        }

        $args = [$data, ...$extra];

        foreach ($this->handlers[$event] as $handlersAtPriority) {
            foreach ($handlersAtPriority as $handler) {
                $args[0] = $data;

                if ($handler->includePath !== null && $handler->includePath !== '') {
                    include_once $handler->includePath;
                }

                if (! is_callable($handler->function)) {
                    // Matches the original's un-guarded call_user_func_array()
                    // fatalling on a genuinely dead registration (see the
                    // $handlers docblock) -- never reached by any handler
                    // actually registered for a real, triggered event.
                    throw new Error("Event handler for '{$event}' is not callable.");
                }

                $data = call_user_func_array($handler->function, $args);
            }
        }

        if (isset($this->handlers['trigger'])) {
            $this->triggerNotify('trigger', [
                'type' => 'post_event',
                'event' => $event,
                'data' => $data,
            ]);
        }

        return $data;
    }

    /**
     * Typed-event counterpart to triggerChange(): every registered handler
     * for $event::class receives and must return an instance of
     * $event::class, in priority order. Mirrors triggerChange()'s own
     * 'trigger' meta-notification pre/post firing exactly, passing the
     * event object itself as 'data' (richer than the raw value
     * triggerChange() passes, since the event also carries its own
     * readonly context, which triggerChange()'s ...$extra never reached
     * 'trigger' listeners with).
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
     * @template T of object
     * @param T $event
     * @return T
     */
    public function dispatchChange(object $event): object
    {
        $eventClass = $event::class;

        if (isset($this->handlers['trigger'])) {
            $this->triggerNotify('trigger', [
                'type' => 'event',
                'event' => $eventClass,
                'data' => $event,
            ]);
        }

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
                }
            }
        }

        if (isset($this->handlers['trigger'])) {
            $this->triggerNotify('trigger', [
                'type' => 'post_event',
                'event' => $eventClass,
                'data' => $event,
            ]);
        }

        return $event;
    }

    /**
     * Notifier event: no return value, just calls every registered handler.
     */
    public function triggerNotify(string $event, mixed ...$args): void
    {
        if (isset($this->handlers['trigger']) && $event !== 'trigger') {
            $this->triggerNotify('trigger', [
                'type' => 'action',
                'event' => $event,
                'data' => null,
            ]);
        }

        if (! isset($this->handlers[$event])) {
            return;
        }

        foreach ($this->handlers[$event] as $handlersAtPriority) {
            foreach ($handlersAtPriority as $handler) {
                if ($handler->includePath !== null && $handler->includePath !== '') {
                    include_once $handler->includePath;
                }

                if (! is_callable($handler->function)) {
                    throw new Error("Event handler for '{$event}' is not callable.");
                }

                call_user_func_array($handler->function, $args);
            }
        }
    }

    /**
     * Typed-event counterpart to triggerNotify(): fire-and-forget, no
     * return value captured. The 'trigger' meta-notification uses
     * 'type' => 'action' (matching triggerNotify()'s own value, not
     * dispatchChange()'s 'event'/'post_event') and passes the event
     * object as 'data' -- a deliberate improvement over triggerNotify()'s
     * always-null 'data' (that method takes variadic args with no single
     * canonical value to report; dispatchNotify() takes exactly one, so
     * that ambiguity doesn't apply). No recursion guard is needed here:
     * $event is typed object, so it can never actually be the string
     * 'trigger'.
     */
    public function dispatchNotify(object $event): void
    {
        $eventClass = $event::class;

        if (isset($this->handlers['trigger'])) {
            $this->triggerNotify('trigger', [
                'type' => 'action',
                'event' => $eventClass,
                'data' => $event,
            ]);
        }

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
            }
        }
    }
}
