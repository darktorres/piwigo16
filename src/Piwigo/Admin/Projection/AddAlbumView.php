<?php

declare(strict_types=1);

namespace Piwigo\Admin\Projection;

use Override;
use Piwigo\Asset\AssetContribution;
use Piwigo\Asset\HasPageAssets;
use Piwigo\Asset\LoadMode;
use Piwigo\Core\View;
use Piwigo\Template\Latte\Attribute\Template as TemplateAttr;

/**
 * `{templateType}` target for
 * `themes/admin/default/template/include/add_album.inc.latte`
 * (docs/PLAN.md's P42-A). Contract-only, same shape as
 * `Piwigo\Controller\Projection\NavigationBarView`'s own precedent --
 * never rendered via `Renderer::render()`, only `{include}`d (its own
 * real markup, the "add album" popin form, still needs a plain
 * `{include}` at each real call site -- only the `{do
 * combineCss}`/`{do combineScript}` calls move to `pageAssets()`,
 * docs/PLAN.md's P42-B).
 *
 * `$load_mode` is genuinely optional -- the template's own
 * `{if empty($load_mode)}{var $load_mode = 'footer'}{/if}` default
 * applies whenever a real call site omits it. `$colorscheme` replaces
 * the file's own `$themeconf['colorscheme']` ambient read -- every
 * real parent resolves and passes it explicitly, the same
 * `UserListView`-established pattern.
 */
#[TemplateAttr('include/add_album.inc.latte')]
final readonly class AddAlbumView implements View, HasPageAssets
{
    public function __construct(
        public ?string $load_mode = null,
        public string $colorscheme = '',
    ) {}

    /**
     * `include/colorbox.inc.latte`'s own contribution (still imperative
     * -- docs/PLAN.md's P42-B colorbox-family batch) is deliberately
     * NOT merged in here: this file's own `{include 'colorbox.inc.latte'}`
     * line stays live, so merging it too would just be a redundant
     * duplicate registration one level removed.
     *
     * @return list<AssetContribution>
     */
    #[Override]
    public function pageAssets(): array
    {
        $loadMode = match ($this->load_mode) {
            'header' => LoadMode::Header,
            'async' => LoadMode::Async,
            default => LoadMode::Footer,
        };

        return [
            AssetContribution::script('jquery.selectize', 'themes/default/js/plugins/selectize.min.js', loadMode: LoadMode::Footer),
            AssetContribution::css('themes/default/js/plugins/selectize.' . $this->colorscheme . '.css', id: 'jquery.selectize'),
            // order 10 is required, see issue 1080
            AssetContribution::css('themes/admin/default/fontello/css/animation.css', order: 10),
            AssetContribution::script('addAlbum', 'themes/admin/default/js/addAlbum.js', loadMode: $loadMode),
            AssetContribution::css('themes/admin/default/css/components/add_album.css', id: 'add_album'),
        ];
    }
}
