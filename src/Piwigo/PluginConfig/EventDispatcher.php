<?php

declare(strict_types=1);

namespace Piwigo\PluginConfig;

/**
 * Plugin event-handler registry -- self-managed singleton, same bridging
 * pattern as Piwigo\Session\SessionService::get(). Holds the state
 * previously carried by include/functions_plugins.inc.php's `global
 * $pwg_event_handlers` (grep-confirmed: never read from outside that file,
 * safe to fully internalize instead of keeping a parallel global in sync).
 */
final class EventDispatcher
{
    private static ?self $instance = null;

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
     * @var array<string, array<int, list<array{function: string|array<int, mixed>|object, include_path: string|null}>>>
     */
    private array $handlers = [];

    public static function get(): self
    {
        return self::$instance ??= new self();
    }

    public static function set(self $dispatcher): void
    {
        self::$instance = $dispatcher;
    }

    /**
     * Test-only -- production code never needs to clear this mid-request.
     */
    public static function reset(): void
    {
        self::$instance = null;
    }

    /**
     * @param array<int, mixed>|object|string $func
     */
    public function addEventHandler(
        string $event,
        string|array|object $func,
        int $priority = 50,
        ?string $includePath = null,
    ): bool {
        if (isset($this->handlers[$event][$priority])) {
            foreach ($this->handlers[$event][$priority] as $handler) {
                if (self::callablesEqual($handler['function'], $func)) {
                    return false;
                }
            }
        }

        $handlersAtPriority = $this->handlers[$event][$priority] ?? [];
        $handlersAtPriority[] = [
            'function' => $func,
            'include_path' => $includePath,
        ];
        $this->handlers[$event][$priority] = $handlersAtPriority;

        ksort($this->handlers[$event]);
        return true;
    }

    /**
     * @param array<int, mixed>|object|string $func
     */
    public function removeEventHandler(string $event, string|array|object $func, int $priority = 50): bool
    {
        if (! isset($this->handlers[$event][$priority])) {
            return false;
        }

        $handlersAtPriority = $this->handlers[$event][$priority];

        foreach ($handlersAtPriority as $i => $handler) {
            if (! self::callablesEqual($handler['function'], $func)) {
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
     * Faithfully replicates PHP's `==` semantics for two callables (this
     * project's PHPStan rules disallow the operator itself) -- string and
     * array callables always compare identically under `==`/`===`, but
     * Closures don't: `$this->method(...)` produces a NEW Closure instance
     * on every evaluation, so registering/removing the same bound method
     * across separate calls (the real add_event_handler()/
     * remove_event_handler() usage pattern, see
     * src/Piwigo/Admin/Integrity/c13y_internal.php) needs the loose,
     * same-binding comparison `==` gives, not `===` identity.
     *
     * @param array<int, mixed>|object|string $a
     * @param array<int, mixed>|object|string $b
     */
    private static function callablesEqual(string|array|object $a, string|array|object $b): bool
    {
        if ($a instanceof \Closure && $b instanceof \Closure) {
            $ra = new \ReflectionFunction($a);
            $rb = new \ReflectionFunction($b);

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

                if ($handler['include_path'] !== null && $handler['include_path'] !== '') {
                    include_once $handler['include_path'];
                }

                if (! is_callable($handler['function'])) {
                    // Matches the original's un-guarded call_user_func_array()
                    // fatalling on a genuinely dead registration (see the
                    // $handlers docblock) -- never reached by any handler
                    // actually registered for a real, triggered event.
                    throw new \Error("Event handler for '{$event}' is not callable.");
                }

                $data = call_user_func_array($handler['function'], $args);
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
                if ($handler['include_path'] !== null && $handler['include_path'] !== '') {
                    include_once $handler['include_path'];
                }

                if (! is_callable($handler['function'])) {
                    throw new \Error("Event handler for '{$event}' is not callable.");
                }

                call_user_func_array($handler['function'], $args);
            }
        }
    }
}
