<?php

declare(strict_types=1);

namespace Piwigo\Menu\Projection;

use Override;
use Piwigo\Asset\AssetContribution;
use Piwigo\Asset\HasPageAssets;
use Piwigo\Asset\LoadMode;
use Piwigo\Core\ExposesPageData;
use Piwigo\Core\View;
use Piwigo\Template\Latte\Attribute\Template;

/**
 * `menubar_menu.latte`'s own typed view. Rendered through
 * `Renderer::render()` into the block's `raw_content` -- see {@see
 * MenubarLinksView} for why the sub-templates are not `{include}`d.
 *
 * `$quickSearch` used to live in the same array as the rows, as
 * `data['qsearch'] = true`, which is why the template filtered the row
 * loop through `n:if="is_array($link)"` to skip it again. It is its own
 * field now and that filter is gone.
 */
#[Template('menubar_menu.latte')]
final readonly class MenubarMenuView implements View, HasPageAssets, ExposesPageData
{
    /**
     * @param list<MenubarMenuRow> $links
     */
    public function __construct(
        public bool $quickSearch,
        public array $links,
        public string $rootUrl,
        public ?string $querySearch,
    ) {}

    /**
     * Both are the quick-search form's, so both are gated on the same
     * flag the template gates the form on -- `$quickSearch` is
     * plugin-mutable through `Menu\Event\BlockManagerPrepareDisplay`,
     * which is why this is a real check and not an assumption.
     *
     * @return list<AssetContribution>
     */
    #[Override]
    public function pageAssets(): array
    {
        if (! $this->quickSearch) {
            return [];
        }

        return [
            AssetContribution::script('menubar-quicksearch', 'themes/default/js/menubar-quicksearch.ts', loadMode: LoadMode::Footer),
            AssetContribution::css('themes/default/css/components/menubar_menu.css', id: 'menubar_menu'),
        ];
    }

    /**
     * @return array<string, string|int|float|bool|null|array<mixed>>
     */
    #[Override]
    public function exposedPageData(): array
    {
        return [];
    }

    /**
     * @return list<string>
     */
    #[Override]
    public function exposedStrings(): array
    {
        return $this->quickSearch ? ['Quick search'] : [];
    }
}
