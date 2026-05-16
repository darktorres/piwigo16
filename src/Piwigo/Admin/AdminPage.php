<?php

declare(strict_types=1);

namespace Piwigo\Admin;

use Piwigo\Core\AccessLevel;

/**
 * Typed description of one admin page.
 *
 * Replaces the implicit registry that lived as `public const array PAGES`
 * arrays on each admin sub-controller. Each value object names:
 *
 *  - `$slug`            — the `?page=` value that selects this page in
 *                         the legacy URL surface (e.g. "album_notification").
 *  - `$label`           — i18n key used to render the menu/breadcrumb.
 *  - `$controllerClass` — FQCN of the controller that responds to
 *                         `handle($slug)`. Resolved by [[AdminPageRegistry]]
 *                         at dispatch time via the container, so DI still
 *                         drives construction.
 *  - `$menuGroup`       — top-level sidebar section.
 *  - `$permission`      — minimum [[AccessLevel]] required to view the
 *                         page. Defaults to `Administrator`; settings
 *                         pages use `Webmaster`.
 */
final readonly class AdminPage
{
    public function __construct(
        public string $slug,
        public string $label,
        public string $controllerClass,
        public AdminMenuGroup $menuGroup,
        public int $permission = AccessLevel::Administrator,
    ) {
    }
}
