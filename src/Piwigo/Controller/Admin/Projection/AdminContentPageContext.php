<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin\Projection;

use Latte\Runtime\Html;
use Override;
use Piwigo\Core\TemplatePageContext;

/**
 * The three fields every admin sub-controller assigns ambiently, shared
 * across page-family conversions the same way {@see
 * \Piwigo\Controller\Projection\CanonicalUrlPageContext} is on the
 * front end. `admin.latte`'s own shell renders `ADMIN_PAGE_TITLE`/
 * `U_HELP` in its own body (`<h1>`/help link), separately from and
 * after a page's own `Renderer::render()` call, so neither field can
 * live on a page-specific `View` -- and `ADMIN_CONTENT` itself is the
 * rendered `Html` a page's own View produces, handed back to this same
 * ambient mechanism for the shell to place. `$adminPageTitle` is
 * genuinely optional: `Admin\AdminShell`'s own `AdminShellFramePageContext`
 * already assigns a default (`'Piwigo Administration Page'`) before any
 * page-specific controller runs -- a page only needs to pass its own
 * value here when it overrides that default, matching each such page's
 * exact original behavior. `$adminContent` is also optional: a
 * multi-tab dispatcher like `Controller\Admin\MaintenanceSubController`
 * assigns its own shared `$adminPageTitle` in a separate call, after
 * its per-tab renderer has already assigned `$adminContent`/`$helpUrl`
 * in its own.
 *
 * `$adminPageTitle` accepts `string|Html` (P59 correction) -- see
 * {@see AdminPageResult::$pageTitle}'s own docblock, the field this one
 * mirrors.
 */
final readonly class AdminContentPageContext implements TemplatePageContext
{
    public function __construct(
        public ?Html $adminContent = null,
        public string|Html|null $adminPageTitle = null,
        public ?string $helpUrl = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function toArray(): array
    {
        $result = [];

        if ($this->adminContent !== null) {
            $result['ADMIN_CONTENT'] = $this->adminContent;
        }

        if ($this->adminPageTitle !== null) {
            $result['ADMIN_PAGE_TITLE'] = $this->adminPageTitle;
        }

        if ($this->helpUrl !== null) {
            $result['U_HELP'] = $this->helpUrl;
        }

        return $result;
    }
}
