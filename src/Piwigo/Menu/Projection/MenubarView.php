<?php

declare(strict_types=1);

namespace Piwigo\Menu\Projection;

use Override;
use Piwigo\Asset\AssetContribution;
use Piwigo\Asset\HasPageAssets;
use Piwigo\Asset\LoadMode;
use Piwigo\Core\ExposesPageData;
use Piwigo\Core\View;
use Piwigo\Menu\DisplayBlock;
use Piwigo\Template\Latte\Attribute\Template;

/**
 * `menubar.latte`'s own typed view -- rendered by
 * {@see \Piwigo\Menu\BlockManager::apply()}. `Piwigo\Menu\*` is
 * L3Presentation (same layer as `Piwigo\Template\Renderer`), unlike the
 * L2a/L2b renderers earlier P40 batches had to split into a
 * data-returning method + caller-renders shape -- `BlockManager` renders
 * this itself.
 *
 * `pageAssets()`/`exposedStrings()` (docs/PLAN.md's P42-B) close the
 * `MenubarBlockView`/`index.latte` design gap noted earlier: the 7 real
 * `menubar_*.latte` sub-block templates are reached only via
 * `menubar.latte`'s own native Latte `{include $block->template, ...}`
 * (a dynamic filename include, never individually
 * `Renderer::render()`'d), so `MenubarBlockView` itself stays
 * deliberately contract-only (no `View`, no `#[Template]`) -- but by the
 * time *this* View is constructed, every block's own `$template`/`$data`
 * is already fully resolved (`BlockManager::prepareDisplay()`/`apply()`
 * run first), so this class can pattern-match the 3 known in-tree
 * sub-block filenames that carry real registrations
 * (`menubar_identification.latte`/`menubar_links.latte`/
 * `menubar_menu.latte` -- `menubar_categories.latte`/
 * `menubar_related_categories.latte`/`menubar_tags.latte`/
 * `menubar_specials.latte` carry none) and replicate each one's own
 * registrations directly, gated the same way the template body itself
 * gates them. An unrecognized `$block->template` (a plugin-registered
 * block) falls through untouched -- its own `{do}` calls, if any, stay
 * live in its own file, same as every other plugin template not yet in
 * scope for this campaign.
 */
#[Template('menubar.latte')]
final readonly class MenubarView implements View, HasPageAssets, ExposesPageData
{
    /**
     * @param array<int|string, DisplayBlock> $blocks
     */
    public function __construct(
        public array $blocks,
    ) {}

    /**
     * @return list<AssetContribution>
     */
    #[Override]
    public function pageAssets(): array
    {
        $assets = [];

        foreach ($this->blocks as $block) {
            $assets = match ($block->template) {
                'menubar_identification.latte' => [
                    ...$assets,
                    AssetContribution::css('themes/default/css/components/menubar_identification.css', id: 'menubar_identification'),
                ],
                'menubar_links.latte' => [
                    ...$assets,
                    AssetContribution::script('menubar-links', 'themes/default/js/menubar-links.ts', loadMode: LoadMode::Footer),
                ],
                'menubar_menu.latte' => [
                    ...$assets,
                    ...(self::hasQuickSearch($block) ? [
                        AssetContribution::script('menubar-quicksearch', 'themes/default/js/menubar-quicksearch.ts', loadMode: LoadMode::Footer),
                        AssetContribution::css('themes/default/css/components/menubar_menu.css', id: 'menubar_menu'),
                    ] : []),
                ],
                default => $assets,
            };
        }

        return $assets;
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
     * `menubar_menu.latte`'s own `{if isset($block->data['qsearch']) and
     * $block->data['qsearch'] == true}` guard is a real branch (today
     * always true whenever `mbMenu` is active --
     * `MenubarRenderer::render()` sets it unconditionally -- but
     * `$block->data` is genuinely plugin-mutable via
     * `Menu\Event\BlockManagerPrepareDisplay`, so this replicates the
     * real check rather than assuming it -- `===` here, not the
     * template's own loose `==`, since this project's strict rules
     * disallow loose comparison in real PHP source and the real value
     * is always a genuine `bool`), covered by its own unit test.
     *
     * @return list<string>
     */
    #[Override]
    public function exposedStrings(): array
    {
        foreach ($this->blocks as $block) {
            if ($block->template === 'menubar_menu.latte' && self::hasQuickSearch($block)) {
                return ['Quick search'];
            }
        }

        return [];
    }

    private static function hasQuickSearch(DisplayBlock $block): bool
    {
        return is_array($block->data) && ($block->data['qsearch'] ?? null) === true;
    }
}
