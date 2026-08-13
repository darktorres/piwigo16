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
 * strings: this codebase's own phpstan-strict-rules config bans variable
 * method calls outright (`method.dynamicName` -- `$obj->{$stringVar}()`
 * or its first-class-callable-syntax form are both flagged, no matter how
 * the string got there), so a later dynamic dispatch step keyed off a
 * stored method name is unbuildable at this project's PHPStan level.
 * First-class callable syntax on a literal method name inside each
 * implementor's own `subscribedEvents()` sidesteps this entirely -- the
 * AST always sees a literal `Identifier`, never a variable one. Priority
 * tuples aren't part of this shape either: `EventDispatcher::
 * addTypedHandler()`'s priority param already defaults to 50, and zero
 * real call sites anywhere in `src/Piwigo/` pass a non-default priority
 * today -- add tuple support back only once a real caller needs it.
 */
interface ListenerInterface
{
    /**
     * @return array<class-string, Closure|list<Closure>>
     */
    public function subscribedEvents(): array;
}
