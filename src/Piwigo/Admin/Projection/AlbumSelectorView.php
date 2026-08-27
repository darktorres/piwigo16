<?php

declare(strict_types=1);

namespace Piwigo\Admin\Projection;

use Override;
use Piwigo\Asset\AssetContribution;
use Piwigo\Asset\HasPageAssets;
use Piwigo\Core\ExposesPageData;
use Piwigo\Core\View;
use Piwigo\Template\Latte\Attribute\Template as TemplateAttr;

/**
 * `{templateType}` target for
 * `themes/admin/default/template/include/album_selector.inc.latte`
 * (docs/PLAN.md's P42-A). Contract-only, same shape as
 * `Piwigo\Controller\Projection\NavigationBarView`'s own precedent --
 * never rendered via `Renderer::render()`, only `{include}`d, across 7
 * real parents (`photos_add_direct.latte`, `cat_modify.latte`,
 * `batch_manager_unit.latte`, `batch_manager_global.latte`,
 * `picture_modify.latte`, `search_filters.inc.latte`, and
 * `batch_manager_filter.inc.latte` itself -- the reason this file
 * converts before or with `batch_manager_filter.inc.latte`, not after).
 * Its own real markup (the linked-album popin) still needs a plain
 * `{include}` at each real call site, wrapped in the template's own
 * `{if once('inc_album_selector')}` guard so the shared popin renders
 * only once even when this partial is `{include}`d repeatedly on the
 * same page (docs/PLAN.md's P42-B) -- only the `{do combineCss}`/
 * `{do combineScript}`/`{do exposeString}` calls move to
 * `pageAssets()`/`exposedStrings()`; `once()`'s own dedup concern
 * doesn't apply to those, since `PageAssets::add()`/`Template::
 * exposeString()` are already dedup-safe regardless of call count.
 * `include/colorbox.inc.latte`'s own contribution is deliberately NOT
 * merged in here (docs/PLAN.md's P42-B), for the identical reason
 * `AddAlbumView` doesn't: every one of this file's 7 real parents
 * already merges `ColorboxView` directly, so this file's own former
 * `{include 'colorbox.inc.latte', load_mode: $load_mode}` line (and the
 * `$load_mode` default it fed) was removed once that coverage was
 * confirmed complete.
 *
 * No `$load_mode` constructor param any more (docs/PLAN.md's P48) --
 * it only ever fed `album_selector.ts`'s own standalone script
 * registration below, vestigial now that album_selector.ts has 8 real
 * consumer files, each with its own real direct import instead (see
 * that file's own leading comment).
 */
#[TemplateAttr('include/album_selector.inc.latte')]
final readonly class AlbumSelectorView implements View, HasPageAssets, ExposesPageData
{
    /**
     * @return list<AssetContribution>
     */
    #[Override]
    public function pageAssets(): array
    {
        return [
            AssetContribution::css('themes/admin/default/css/components/album_selector.css'),
            AssetContribution::css('themes/default/vendor/fontello/css/gallery-icon.css', order: -10),
            // album_selector.ts's own registration is no longer here
            // (docs/PLAN.md P48) -- it has 8 real consumer files, each
            // folding its code in via its own direct import instead of
            // one shared standalone script tag (the same reasoning as
            // AutosizeView/DatepickerView's own P48 batches).
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
        return [
            'Only the first %d albums are displayed, out of %d.',
            'Album already selected',
            'No search in progress',
            '<b>%d</b> albums found',
            '<b>1</b> album found',
            '<b>%d+</b> albums found, try to refine the search',
            'Add a sub-album to “%s”',
            'Create and select',
            'Root',
            'Name field must not be empty',
            'An error has occured',
            'Select an album',
            'Search',
        ];
    }
}
