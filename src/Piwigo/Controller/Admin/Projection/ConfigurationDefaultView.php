<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin\Projection;

use Override;
use Piwigo\Asset\AssetContribution;
use Piwigo\Asset\HasPageAssets;
use Piwigo\Asset\LoadMode;
use Piwigo\Core\View;
use Piwigo\Template\Latte\Attribute\Template;

/**
 * `configuration_default.latte`'s own typed view, constructed by {@see
 * \Piwigo\Controller\Admin\ConfigurationSubController::handle()}'s own
 * `'default'` (Guest settings) case. The `GUEST_`-prefixed fields come
 * from {@see \Piwigo\Controller\Projection\ProfileFormData}, the same
 * data the front-end profile page's own View classes render from -- this
 * tab renders its own inline form directly in `configuration_default.latte`
 * instead, so the fields are threaded through here rather than a shared
 * View. No `$allowUserCustomization`/`$email` field -- the template's
 * own body never references either. No shared `$fAction`/`$saveSuccess`
 * field either (unlike every other `configuration_*.latte` tab) -- this
 * tab's own body reads `$GUEST_F_ACTION` for its form action instead of
 * the shared one, and it has no save-success banner of its own (its own
 * errors/success route through the standard admin page-messages banner
 * via `PageState`, not a per-tab template var).
 */
#[Template('configuration_default.latte')]
final readonly class ConfigurationDefaultView implements View, HasPageAssets
{
    /**
     * @param array<string, string> $radioOptions
     */
    public function __construct(
        public string $username,
        public bool $activateComments,
        public int $nbImagePage,
        public int $recentPeriod,
        public string $expand,
        public string $nbComments,
        public string $nbHits,
        public string $redirect,
        public string $guestFAction,
        public array $radioOptions,
        public string $csrfToken,
        public int $isWebmaster,
    ) {}

    /**
     * `configuration_default.latte`'s own unconditional
     * `{do combineScript(...)}` (docs/PLAN.md's P42-B).
     */
    #[Override]
    public function pageAssets(): array
    {
        return [
            // Real per-page bundle entry (docs/PLAN.md's P48) -- this
            // page's only first-party JS need is common.ts's own side
            // effects, folded in via a real `?dup` import instead of the
            // separate script tag common.ts used to register directly.
            AssetContribution::script('configuration_default_page', 'themes/admin/default/js/pages/configuration_default.ts', loadMode: LoadMode::Footer),
        ];
    }
}
