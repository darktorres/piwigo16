<?php

declare(strict_types=1);

namespace Piwigo\Menu\Projection;

use Override;
use Piwigo\Asset\AssetContribution;
use Piwigo\Asset\HasPageAssets;
use Piwigo\Core\View;
use Piwigo\Template\Latte\Attribute\Template;

/**
 * `menubar_identification.latte`'s own typed view, and the last of the
 * seven menubar sub-blocks to get one -- see {@see MenubarLinksView} for
 * why they are rendered into `raw_content` rather than `{include}`d.
 *
 * The constructor takes a union rather than the eight correlated
 * nullables this block used to reach the template as. The two halves are
 * genuinely exclusive (guest vs. identified user), which the template
 * asked about through `{if isset($U_LOGIN)}` -- a guard that only worked
 * because `$U_LOGIN` happened to be assigned on exactly one of the two
 * branches. The union states that exclusivity where the producer has to
 * satisfy it: neither half can be omitted and both cannot be passed.
 *
 * It is split into `$guest`/`$user` for the template, which needs two
 * plainly-typed nullable properties to narrow on -- a template cannot
 * carry an `instanceof` against a projection class without naming it in
 * full, and `{templateType}` exposes properties, not methods.
 *
 * `$loginRedirect` stays outside the union: the login form's hidden
 * `redirect` field is always rendered, so it is unconditional. It carries
 * the raw request URI and the template applies its own `|urlencode`, the
 * same way `HtmlService::accessDenied()` encodes at its own redirect
 * boundary.
 */
#[Template('menubar_identification.latte')]
final readonly class MenubarIdentificationView implements View, HasPageAssets
{
    public ?MenubarGuestIdentity $guest;

    public ?MenubarUserIdentity $user;

    public function __construct(
        MenubarGuestIdentity|MenubarUserIdentity $identity,
        public string $loginRedirect,
    ) {
        $this->guest = $identity instanceof MenubarGuestIdentity ? $identity : null;
        $this->user = $identity instanceof MenubarUserIdentity ? $identity : null;
    }

    /**
     * Moved off `MenubarView::pageAssets()`'s `match ($block->template)`,
     * the last arm that dispatch had. See {@see MenubarLinksView} for why
     * a view that owns its markup owns its assets.
     *
     * @return list<AssetContribution>
     */
    #[Override]
    public function pageAssets(): array
    {
        return [
            AssetContribution::css('themes/default/css/components/menubar_identification.css', id: 'menubar_identification'),
        ];
    }
}
