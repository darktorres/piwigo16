<?php

declare(strict_types=1);

namespace Piwigo\Plugins;

use Piwigo\Admin\Integrity\CheckIntegrity;
use Piwigo\Core\Kernel;
use Piwigo\Event\LegacyEventBridge;
use Piwigo\Menu\BlockManager;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;

/**
 * Static event dispatcher.
 *
 * § 1.4 B4 — alongside the legacy listener loop, every `dispatch()` and
 * `notify()` call also constructs the matching typed event class (via
 * {@see LegacyEventBridge}) and fires it through the PSR-14 dispatcher.
 * This double-firing lets B5 migrate dispatch sites and B6 migrate
 * subscribers one at a time without a big-bang switch.
 *
 * The whole class — including the bridge plumbing — is deleted in B17
 * once every src/ call site has moved to typed dispatch.
 */
final class EventDispatcher
{
    public const int NEUTRAL_PRIORITY = 50;

    /**
     * Singleton sentinel returned by bridgeToTyped() when no writeback
     * should propagate back into the legacy `$data` (Kernel not booted,
     * no typed event mapped, typed class threw, or every DTO field is
     * readonly). Identity-compared (===), so it cannot collide with any
     * legitimate event payload — strings, bools, arrays, even null can
     * all be valid writeback values for some event.
     */
    private static ?\stdClass $noWritebackSentinel = null;

    private static function noWriteback(): \stdClass
    {
        return self::$noWritebackSentinel ??= new \stdClass();
    }

    /** @var array<string, array<int, list<array{function: mixed, include_path: string|null}>>> */
    public static array $handlers = [];

    public static function init(): void
    {
        self::$handlers = [];
    }

    /**
     * @psalm-param \Closure(CheckIntegrity):void|\Closure(BlockManager):void|\Closure(array<mixed>, string):array<mixed>|\Closure((array<mixed>|string)):string|\Closure(bool, string, string, bool):bool|\Closure(mixed, string, array<mixed>):mixed|\Closure(string, array<string, mixed>):string|string $func
     */
    public static function addListener(string $event, string|\Closure $func, int $priority = self::NEUTRAL_PRIORITY, ?string $include_path = null): bool
    {
        if (isset(self::$handlers[$event][$priority])) {
            foreach (self::$handlers[$event][$priority] as $handler) {
                if ($handler['function'] == $func) {
                    return false;
                }
            }
        }

        self::$handlers[$event][$priority][] = [
            'function' => $func,
            'include_path' => $include_path,
        ];

        ksort(self::$handlers[$event]);
        return true;
    }

    public static function removeListener(string $event, mixed $func, int $priority = self::NEUTRAL_PRIORITY): bool
    {
        if (!isset(self::$handlers[$event][$priority])) {
            return false;
        }

        $bucket = &self::$handlers[$event][$priority];
        for ($i = 0; $i < count($bucket); $i++) {
            if ($bucket[$i]['function'] == $func) {
                unset($bucket[$i]);
                $bucket = array_values($bucket);

                if (empty($bucket)) {
                    unset(self::$handlers[$event][$priority]);
                    if (empty(self::$handlers[$event])) {
                        unset(self::$handlers[$event]);
                    }
                }
                return true;
            }
        }
        return false;
    }

    public static function dispatch(string $event, mixed ...$args): mixed
    {
        $data = $args[0] ?? null;

        if (isset(self::$handlers['trigger'])) {
            self::notify('trigger', ['type' => 'event', 'event' => $event, 'data' => $data]);
        }

        if (isset(self::$handlers[$event])) {
            foreach (self::$handlers[$event] as $handlers) {
                foreach ($handlers as $handler) {
                    $args[0] = $data;
                    if (isset($handler['include_path']) && $handler['include_path'] !== '') {
                        // Legacy plugin handler may carry an include_path
                        // computed at registration. Path is plugin-specific
                        // and not statically resolvable.
                        /** @psalm-suppress UnresolvableInclude */
                        include_once($handler['include_path']);
                    }
                    if (is_callable($handler['function'])) {
                        $data = call_user_func_array($handler['function'], $args);
                    }
                }
            }
        }

        $args[0] = $data;
        $bridgeResult = self::bridgeToTyped($event, ...$args);
        if ($bridgeResult !== self::noWriteback()) {
            $data = $bridgeResult;
        }

        if (isset(self::$handlers['trigger'])) {
            self::notify('trigger', ['type' => 'post_event', 'event' => $event, 'data' => $data]);
        }

        return $data;
    }

    public static function notify(string $event, mixed ...$args): void
    {
        if (isset(self::$handlers['trigger']) && $event !== 'trigger') {
            self::notify('trigger', ['type' => 'action', 'event' => $event, 'data' => null]);
        }

        if (isset(self::$handlers[$event])) {
            foreach (self::$handlers[$event] as $handlers) {
                foreach ($handlers as $handler) {
                    if (isset($handler['include_path']) && $handler['include_path'] !== '') {
                        // Same as the dispatch branch above — handler may
                        // ship an include_path registered at runtime.
                        /** @psalm-suppress UnresolvableInclude */
                        include_once($handler['include_path']);
                    }
                    if (is_callable($handler['function'])) {
                        call_user_func_array($handler['function'], $args);
                    }
                }
            }
        }

        // 'trigger' is the legacy plugin-API meta-event — itself not bridged,
        // since its concept disappears with the typed event system.
        if ($event !== 'trigger') {
            self::bridgeToTyped($event, ...$args);
        }
    }

    /**
     * Fire the typed event matching `$event` (if any) through the PSR-14
     * dispatcher. Silently skipped when the Kernel container isn't booted
     * (early bootstrap, certain test contexts) or when no typed class is
     * mapped for the event name. Construction or dispatch errors are
     * logged when a PSR-3 logger is reachable, then swallowed so legacy
     * dispatch continues unaffected.
     *
     * Returns the post-dispatch value of the typed event's single mutable
     * public property (the B6b "selective readonly" convention), or the
     * noWriteback() sentinel when no writeback should flow back into the
     * legacy `$data`. Legacy callers in `dispatch()` use this to surface
     * typed-subscriber mutations to plugin code that hasn't migrated off
     * the static API yet.
     */
    private static function bridgeToTyped(string $event, mixed ...$args): mixed
    {
        if (!Kernel::isBooted()) {
            return self::noWriteback();
        }

        $class = LegacyEventBridge::classFor($event);
        if ($class === null) {
            return self::noWriteback();
        }

        try {
            // $class is always a typed class-string sourced from
            // LegacyEventBridge::MAP, where every value is a ::class FQN.
            // The codebase-wide NoDynamicNewRule (tools/phpstan/NoDynamicNewRule.php)
            // forbids `new $var()` in src/; this site is the one
            // intentional exception, and goes away in B17 with the rest
            // of the legacy dispatcher.
            // @phpstan-ignore-next-line piwigo.noDynamicNew
            $typed = new $class(...$args);
            Kernel::service(EventDispatcherInterface::class)->dispatch($typed);

            // Find the single mutable public property (the B6b convention:
            // filter events demote exactly one field from readonly so
            // subscribers can write into it). Reflection on the freshly
            // dispatched event surfaces that field's post-subscriber value.
            $reflection = new \ReflectionObject($typed);
            foreach ($reflection->getProperties(\ReflectionProperty::IS_PUBLIC) as $prop) {
                if (!$prop->isReadOnly()) {
                    return $prop->getValue($typed);
                }
            }
            return self::noWriteback();
        } catch (\Throwable $e) {
            try {
                Kernel::service(LoggerInterface::class)->warning(
                    'LegacyEventBridge failed to fire typed event',
                    ['event' => $event, 'class' => $class, 'error' => $e->getMessage()],
                );
            } catch (\Throwable) {
                // Logger unavailable; legacy flow continues either way.
            }
            return self::noWriteback();
        }
    }

    public static function reset(): void
    {
        self::$handlers = [];
    }
}
