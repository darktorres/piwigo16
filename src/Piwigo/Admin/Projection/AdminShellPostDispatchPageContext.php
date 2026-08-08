<?php

declare(strict_types=1);

namespace Piwigo\Admin\Projection;

use Override;
use Piwigo\Core\TemplatePageContext;

/**
 * The template variable set assigned by
 * {@see \Piwigo\Admin\AdminShell::runDispatch()} after dispatching to the
 * page-slug sub-controller -- kept separate from
 * {@see AdminShellFramePageContext} because the sub-controller dispatch
 * between them parses its own `ADMIN_CONTENT` template using only the
 * vars assigned up to that point; assigning these 2 keys earlier would
 * make them visible to that parse when the original never did.
 */
final readonly class AdminShellPostDispatchPageContext implements TemplatePageContext
{
    /**
     * @param array<array-key, string> $pwgmenu
     */
    public function __construct(
        public int $activeMenu,
        public array $pwgmenu,
    ) {}

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function toArray(): array
    {
        return [
            'ACTIVE_MENU' => $this->activeMenu,
            'pwgmenu' => $this->pwgmenu,
        ];
    }
}
