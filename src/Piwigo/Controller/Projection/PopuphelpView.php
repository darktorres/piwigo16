<?php

declare(strict_types=1);

namespace Piwigo\Controller\Projection;

use Override;
use Piwigo\Asset\AssetContribution;
use Piwigo\Asset\HasPageAssets;
use Piwigo\Asset\LoadMode;
use Piwigo\Core\View;
use Piwigo\Template\Latte\Attribute\Template;

/**
 * `popuphelp.latte`'s own typed view -- constructed by both the
 * front-end {@see \Piwigo\Controller\PopuphelpController} and the
 * admin-context {@see \Piwigo\Controller\Admin\AdminPopuphelpController},
 * two real callers of the same template. The admin-context caller
 * actually renders a DIFFERENT physical file
 * (`themes/admin/default/template/popuphelp.latte`, same bare
 * `#[Template('popuphelp.latte')]` name, resolved per active theme),
 * which has zero registration calls of its own -- `$isAdminContext`
 * exists solely so `pageAssets()` (a single class-level method, unable
 * to tell which physical template resolved) can replicate that real
 * difference: the front-end file's own `combineScript('popuphelp',
 * ...)` call must NOT fire for the admin-context render.
 */
#[Template('popuphelp.latte')]
final readonly class PopuphelpView implements View, HasPageAssets
{
    public function __construct(
        public string $helpContent,
        public bool $isAdminContext,
    ) {}

    /**
     * `popuphelp.latte`'s own unconditional `{do combineScript(...)}`
     * (docs/PLAN.md's P42-B) -- only for the front-end physical file;
     * the admin-context physical file never had this call.
     */
    #[Override]
    public function pageAssets(): array
    {
        if ($this->isAdminContext) {
            return [];
        }

        return [
            AssetContribution::script('popuphelp', 'themes/default/js/popuphelp.ts', loadMode: LoadMode::Footer),
        ];
    }
}
