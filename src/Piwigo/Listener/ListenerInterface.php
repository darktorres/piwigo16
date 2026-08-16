<?php

declare(strict_types=1);

namespace Piwigo\Listener;

use Closure;

/**
 * Contract for a first-party event listener class, replacing an inline
 * `Bootstrap\RequestBootstrap` registration. Constructed by
 * `RequestBootstrap` through normal container/DI resolution (unlike
 * `Piwigo\PluginConfig\ExtensionInterface::subscribedEvents()`, which runs
 * on a bare `new $class()` instance with nothing injected yet) -- so this
 * method is free to read real constructor-injected state (e.g. a config
 * flag) to decide what to subscribe to.
 *
 * Uses the exact same return shape as `ExtensionInterface::
 * subscribedEvents()`: one consistent "what events do I care about"
 * convention for first-party listeners and plugin/theme extensions alike,
 * both ultimately wired onto `PluginConfig\EventDispatcher::
 * addTypedHandler()`.
 *
 * Entries are bound `Closure`s (`$this->onFoo(...)`), never method-name
 * strings, deliberately diverging from Symfony's own `EventSubscriberInterface
 * ::getSubscribedEvents()` shape -- not because of a PHPStan rule collision
 * (an earlier version of this docblock claimed `method.dynamicName` bans
 * this; verified false: that rule only flags a literal `$obj->$var()` AST
 * node, which this pattern never writes anywhere, and shipmonk/dead-code-
 * detector's `SymfonyUsageProvider` already has first-class support for
 * tracing `getSubscribedEvents()`'s string method names as real usages --
 * confirmed with a throwaway probe class analysed clean under the full,
 * unscoped `composer analyse`). The real, load-bearing reason:
 * `EventSubscriberInterface::getSubscribedEvents()` is declared `public
 * static function`, so it has no `$this` -- confirmed both ways, PHPStan
 * itself rejects `$this->onFoo(...)` written inside a static method
 * (`return.type`/`method.nonObject`/`variable.undefined`), and actually
 * calling it throws a real `Error: Using $this when not in object
 * context`. Every implementor here (this interface's own listeners,
 * constructed via real DI resolution; `ExtensionInterface`'s plugins/
 * themes, constructed via `new $class()`) is a genuine per-request
 * instance, so a bound closure needs a real, live `$this` -- a static
 * method can't provide one, which would force registration back onto
 * bare method-name strings and reopen exactly the case this shape avoids.
 * First-class callable syntax on a literal method name inside each
 * implementor's own `subscribedEvents()` keeps every registration a real,
 * renamable, typo-checked reference -- the AST always sees a literal
 * `Identifier`, never a variable one. Priority tuples aren't part of this
 * shape either: `EventDispatcher::addTypedHandler()`'s priority param
 * already defaults to 50, and zero real call sites anywhere in
 * `src/Piwigo/` pass a non-default priority today -- add tuple support
 * back only once a real caller needs it.
 */
interface ListenerInterface
{
    /**
     * @return array<class-string, Closure|list<Closure>>
     */
    public function subscribedEvents(): array;
}
