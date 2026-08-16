<?php

declare(strict_types=1);

namespace Piwigo\PluginConfig;

use Override;
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
 * **P32 Stage A4**: storage/dispatch is a thin, direct wrapper over a real
 * `Symfony\Component\EventDispatcher\EventDispatcher` (`$inner`) --
 * `addTypedHandler()`/`removeTypedHandler()` pass straight through to
 * `$inner->addListener()`/`removeListener()`, no wrapping or side table.
 * That's only possible because the untyped legacy API (`addEventHandler()`/
 * `removeEventHandler()`/`includePath`/`callablesEqual()`, plus the
 * `EventHandler` value object A3 already found dead) is gone: it existed
 * solely to let a caller register a not-yet-existing function name (PHP's
 * native `callable` type validates eagerly; Symfony's own `addListener()`
 * parameter is `callable|array`, same eager check) -- `Bootstrap\
 * RequestBootstrap`'s own docblock confirms the one real registration that
 * ever needed this (`'pwg_image_resize'`, for `UploadImageResize`/
 * `UploadThumbnailResize`) was deleted outright during the P27.0 Listener
 * migration, not ported, since dead-but-lazily-tolerated registrations
 * aren't worth a permanent mechanism. Every real caller left --
 * `src/Piwigo/Admin/Integrity/C13yInternal.php` included, once cited as
 * `callablesEqual()`'s own real-usage justification -- already used
 * `addTypedHandler()`. Symfony's own `removeListener()` already compares
 * callables the same way the deleted `callablesEqual()` did by hand
 * (verified: PHP's native `==` on two `$this->method(...)`-produced
 * Closures is true for the same bound object + method, exactly the
 * loose-but-correct comparison a fresh Closure needs on every
 * registration call), so no replacement dedup/removal helper was needed
 * either.
 *
 * Not adopting Symfony's own `EventSubscriberInterface` anywhere in this
 * codebase: see `Listener\ListenerInterface`'s own docblock for why (a
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
     * There's no "not callable" guard to raise here anymore either -- PHP's
     * own `callable` type on `addTypedHandler()`'s own parameter already
     * rejects a not-yet-real callable at the registration call site
     * itself, before it ever reaches `$inner`, so a dead handler
     * registration that only fails lazily at dispatch time (the old
     * `addEventHandler()`'s whole reason to exist) is no longer
     * constructible at all.
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
