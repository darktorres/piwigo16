<?php

declare(strict_types=1);

namespace Piwigo\Admin\Projection;

use Override;
use Piwigo\Core\TemplatePageContext;

/**
 * The template variable set assigned by
 * {@see \Piwigo\Admin\MenubarPageRenderer::render()}. `$blocks` is
 * always included -- the original code's own `foreach ($mb_conf as ...)`
 * loop that builds it runs unconditionally, and `menubar.latte`'s own
 * `{foreach from=$blocks}` has no guard around it either. Assigned as
 * `block_configs` (not `blocks`) -- the front-end's own, unrelated
 * `themes/default/template/menubar.latte` already uses a top-level
 * `blocks` variable of real `Menu\DisplayBlock` objects; sharing the
 * name made this admin-only `RegisteredBlock`-based shape leak into that
 * template's own inferred type via the phpstan-latte tool's global
 * same-name-variable fallback union.
 */
final readonly class MenubarPageContext implements TemplatePageContext
{
    /**
     * @param list<MenubarBlockConfigRow> $blocks
     */
    public function __construct(
        public string $formAction,
        public bool $isWebmaster,
        public string $adminPageTitle,
        public array $blocks,
    ) {}

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function toArray(): array
    {
        return [
            'F_ACTION' => $this->formAction,
            'isWebmaster' => $this->isWebmaster ? 1 : 0,
            'ADMIN_PAGE_TITLE' => $this->adminPageTitle,
            'block_configs' => array_map(static fn (MenubarBlockConfigRow $block): array => $block->toArray(), $this->blocks),
        ];
    }
}
