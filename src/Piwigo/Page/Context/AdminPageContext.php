<?php

declare(strict_types=1);

namespace Piwigo\Page\Context;

/**
 * Base page context for admin pages (AdminController + sub-controllers).
 *
 * Not final — individual admin sections may extend this with their own
 * typed properties (e.g. AlbumAdminContext, UserAdminContext) once #23
 * admin template waves begin.
 *
 * Consumed by #23 Wave 1 Latte templates (admin/themes/default/template/).
 */
readonly class AdminPageContext
{
    /**
     * @param array<string,mixed>         $pageMeta       body_id, admin_theme, meta_robots, etc.
     * @param array<string,mixed>         $themeAssets    Combined script/CSS descriptors for this page.
     * @param list<array<string,mixed>>   $flashMessages  Success/error/warning messages (type + message keys).
     */
    public function __construct(
        public string $pageTitle,
        public array  $pageMeta,
        public array  $themeAssets,
        public array  $flashMessages,
    ) {}
}
