<?php

declare(strict_types=1);

namespace Piwigo\Asset;

/**
 * Implemented by a `View` that needs CSS/script/inline-script
 * contributions on its own page -- the declarative replacement for
 * `{do combineCss(...)}`/`{do combineScript(...)}`/`{do footerScript(...)}`
 * (docs/PLAN.md's P42). `Renderer::render()` applies the returned list to
 * `PageAssets` before the View's own `.latte` file ever runs, so there is
 * no ordering dependency on where in the template body the old calls used
 * to sit. A plain standalone interface (not `extends Core\View`) -- a
 * View class implements this alongside `Core\View`, e.g. `final readonly
 * class FooView implements View, HasPageAssets`.
 */
interface HasPageAssets
{
    /**
     * @return list<AssetContribution>
     */
    public function pageAssets(): array;
}
