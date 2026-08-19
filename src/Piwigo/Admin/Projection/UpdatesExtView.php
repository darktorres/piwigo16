<?php

declare(strict_types=1);

namespace Piwigo\Admin\Projection;

use Piwigo\Core\View;
use Piwigo\Template\Latte\Attribute\Template;

/**
 * `updates_ext.latte`'s own typed view, constructed by {@see
 * \Piwigo\Admin\UpdatesExtPageRenderer::render()}. Shared by 4 real
 * callers -- `Controller\Admin\UpdatesSubController`'s own "ext" tab,
 * and `LanguagesSubController`/`ThemesSubController`/
 * `PluginsSubController`'s own "update" tab, each of which applies its
 * own `ADMIN_PAGE_TITLE` override via its own `AdminContentPageContext`
 * call after this render() call returns.
 */
#[Template('updates_ext.latte')]
final readonly class UpdatesExtView implements View
{
    /**
     * @param array<string, list<array<string, mixed>>> $updatesExtension
     */
    public function __construct(
        public array $updatesExtension,
        public bool $showReset,
        public string $pwgToken,
        public string $extType,
        public int $isWebmaster,
    ) {}
}
