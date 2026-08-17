<?php

declare(strict_types=1);

namespace Piwigo\Core;

use Closure;

/**
 * The one shared "what events do I care about" contract for both
 * first-party listeners (`Piwigo\Listener\*`, implementing this directly)
 * and plugin/theme extensions (`PluginConfig\ExtensionInterface extends
 * SubscriberInterface`, layering lifecycle methods on top) -- both
 * ultimately wired onto `PluginConfig\EventDispatcher::
 * registerSubscriber()`/`addTypedHandler()`. Lives in `Piwigo\Core`
 * (L1Infrastructure), not `Piwigo\Listener\*` (L3Presentation) or
 * `Piwigo\PluginConfig\*` (also L3Presentation): `EventDispatcher`
 * (L1Infrastructure) needs to reference this type for
 * `registerSubscriber()`'s parameter, and deptrac only allows downward
 * dependencies.
 *
 * Entries are bound `Closure`s (`$this->onFoo(...)`), never method-name
 * strings, deliberately diverging from Symfony's own
 * `EventSubscriberInterface::getSubscribedEvents()` shape. The real,
 * load-bearing reason: `EventSubscriberInterface::getSubscribedEvents()`
 * is declared `public
 * static function`, so it has no `$this` -- confirmed both ways, PHPStan
 * itself rejects `$this->onFoo(...)` written inside a static method
 * (`return.type`/`method.nonObject`/`variable.undefined`), and actually
 * calling it throws a real `Error: Using $this when not in object
 * context`. Every real implementor of this interface (or of
 * `ExtensionInterface`) is a genuine per-request instance, so a bound
 * closure needs a real, live `$this` -- a static method can't provide
 * one, which would force registration back onto bare method-name strings
 * and reopen exactly the case this shape avoids. First-class callable
 * syntax on a literal method name inside each implementor's own
 * `subscribedEvents()` keeps every registration a real, renamable,
 * typo-checked reference -- the AST always sees a literal `Identifier`,
 * never a variable one. Priority tuples aren't part of this shape either:
 * `EventDispatcher::addTypedHandler()`'s priority param already defaults
 * to 50, and zero real call sites anywhere in `src/Piwigo/` pass a
 * non-default priority today -- add tuple support back only once a real
 * caller needs it.
 */
interface SubscriberInterface
{
    /**
     * @return array<class-string, Closure|list<Closure>>
     */
    public function subscribedEvents(): array;
}
