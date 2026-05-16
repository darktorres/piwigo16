<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin;

/**
 * Contract for an admin sub-controller dispatched by [[AdminController]]
 * via the [[\Piwigo\Admin\AdminPageRegistry]].
 *
 * Every controller listed under `AdminPage::$controllerClass` must
 * implement this — that's how [[AdminController::dispatchToSubController]]
 * can call `handle()` against a registry-supplied class-string without
 * dropping back to `method_exists()` and friends. The interface stays
 * minimal so the existing per-controller method signature is preserved
 * verbatim; B17's cleanup will move slug-routing logic out of `handle()`
 * once each controller exposes one method per slug.
 */
interface AdminSubControllerInterface
{
    public function handle(string $page): void;
}
