<?php

declare(strict_types=1);

namespace Piwigo\Core;

/**
 * Implemented by a `View` that needs client-side page data/translated
 * strings exposed via the JSON island -- the declarative replacement for
 * `{do exposeData(...)}`/`{do exposeString(...)}` (docs/PLAN.md's P42).
 * `Renderer::render()` applies both return values to `Template::
 * exposeData()`/`exposeString()` before the View's own `.latte` file ever
 * runs.
 *
 * `exposedPageData()`'s value union matches `Template::exposeData()`'s
 * own real parameter type exactly (`string|int|float|bool|null|array`,
 * not a looser `mixed`) -- a View returning something that union can't
 * express would otherwise only be caught by PHPStan at `Renderer::
 * render()`'s call site instead of at the View's own declaration.
 *
 * A plain standalone interface (not `extends View`) -- a View class
 * implements this alongside `Core\View`.
 */
interface ExposesPageData
{
    /**
     * @return array<string, string|int|float|bool|null|array<mixed>>
     */
    public function exposedPageData(): array;

    /**
     * @return list<string>
     */
    public function exposedStrings(): array;
}
