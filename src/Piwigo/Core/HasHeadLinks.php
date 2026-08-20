<?php

declare(strict_types=1);

namespace Piwigo\Core;

/**
 * Implemented by a *top-level* `View` that needs `<link>` elements inside
 * `<head>` -- the declarative replacement for `{do htmlHead(...)}`
 * (docs/PLAN.md's P42). `Renderer::render()` applies the returned list
 * before the View's own `.latte` file ever runs, so declaration order in
 * the old template body no longer matters.
 *
 * Only meaningful on a top-level View: by the time a nested partial's own
 * `Renderer::render()` call happens (mid-render of its parent), `<head>`
 * has typically already been emitted, so a nested partial implementing
 * this would register data too late to affect the printed page. A plain
 * standalone interface (not `extends View`) -- a View class implements
 * this alongside `Core\View`.
 */
interface HasHeadLinks
{
    /**
     * @return list<HeadLink>
     */
    public function headLinks(): array;
}
