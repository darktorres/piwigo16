<?php

declare(strict_types=1);

namespace Piwigo\Admin\Projection;

use Override;
use Piwigo\Asset\AssetContribution;
use Piwigo\Asset\HasPageAssets;
use Piwigo\Asset\LoadMode;
use Piwigo\Core\ExposesPageData;
use Piwigo\Core\View;
use Piwigo\Template\Latte\Attribute\Template;

/**
 * `tags.latte`'s own typed view, constructed by {@see
 * \Piwigo\Admin\TagsPageRenderer::render()}. No `$formAction` field --
 * `F_ACTION` has zero real references in `tags.latte`'s own body (tag
 * management is entirely client-side).
 *
 * `$firstTags` is the first page of `$data`, not a different collection --
 * the template renders that slice server-side and hands the whole list to
 * `tags.ts` as JSON for the client-side pager.
 *
 * `$tagsPerPageSelected` decides which page-size link the server paints
 * as selected. Null means none of them: the cookie holds a value that is
 * not one of the four the links offer. See
 * {@see \Piwigo\Admin\TagsPageRenderer::tagsPerPageSelected()}.
 */
#[Template('tags.latte')]
final readonly class TagsView implements View, HasPageAssets, ExposesPageData
{
    /**
     * @param list<TagRow> $firstTags
     * @param list<TagRow> $data
     */
    public function __construct(
        public string $pwgToken,
        public string $orphanTagNamesArray,
        public string $warningTags,
        public string $messageTags,
        public array $firstTags,
        public array $data,
        public int $total,
        public int $perPage,
        public ?int $tagsPerPageSelected,
    ) {}

    /**
     * `tags.latte`'s own unconditional `{do combineScript(...)}`x5/
     * `{do combineCss(...)}`x3 (docs/PLAN.md's P42-B).
     */
    #[Override]
    public function pageAssets(): array
    {
        return [
            AssetContribution::script('jquery.confirm', 'https://cdn.jsdelivr.net/npm/jquery-confirm@3.3.4/dist/jquery-confirm.min.js', loadMode: LoadMode::Footer, dependsOn: ['jquery']),
            AssetContribution::css('https://cdn.jsdelivr.net/npm/jquery-confirm@3.3.4/dist/jquery-confirm.min.css'),
            // order: 10 is required, see issue 1080.
            AssetContribution::css('themes/admin/default/fontello/css/animation.css', order: 10),
            AssetContribution::css('themes/admin/default/css/pages/tags.css', id: 'tags'),
            AssetContribution::script('tiptip', 'https://cdn.jsdelivr.net/gh/drewwilson/TipTip@277e33629e/jquery.tipTip.minified.js'),
            AssetContribution::script('tags', 'themes/admin/default/js/tags.ts', loadMode: LoadMode::Footer),
        ];
    }

    #[Override]
    public function exposedPageData(): array
    {
        return [
            'csrf_token' => $this->pwgToken,
            'orphan_tag_names_array' => $this->orphanTagNamesArray,
            'total' => $this->total,
        ];
    }

    /**
     * `tags.latte`'s own unconditional `{do exposeString(...)}`x28
     * (docs/PLAN.md's P42-B) -- `'No, I have changed my mind'` is
     * dropped outright, not ported here: 1 of the 3 theme-base
     * confirm-dialog strings `ThemeBaseAssets` already registers
     * unconditionally for every page.
     */
    #[Override]
    public function exposedStrings(): array
    {
        return [
            'Delete tag "%s"?',
            'Delete tags {%s}?',
            'Yes, delete',
            'Yes, rename',
            'Tag "%s" succesfully deleted',
            'Tags {%s} succesfully deleted',
            'Tag "%s" already exists',
            'Tag "%s" created',
            'Tag "%s1" renamed in "%s2"',
            'Rename "%s"',
            'Delete orphan tags ?',
            'You have %s1 orphan : %s2',
            'Delete them',
            'Keep them',
            ' (copy)',
            ' (copy %s)',
            'Tag(s) {%s1} succesfully merged into "%s2"',
            'and %s others',
            '%s other tags available...',
            '%d photos',
            'no photo',
            'Select all %d tags',
            'Clear Selection',
            'The %d tags on this page are selected',
            '<b>%d</b> tag selected',
            '<b>%d</b> tags found',
            '<b>%d</b> tag found',
        ];
    }
}
