<?php

declare(strict_types=1);

namespace Piwigo\Admin\Projection;

use Override;
use Piwigo\Asset\AssetContribution;
use Piwigo\Asset\HasPageAssets;
use Piwigo\Asset\LoadMode;
use Piwigo\Core\View;
use Piwigo\Template\Latte\Attribute\Template;

/**
 * `languages_new.latte`'s own typed view, constructed by {@see
 * \Piwigo\Admin\LanguagesNewPageRenderer::render()}. `$languages` is
 * always included (even empty) since the template reads it with
 * `{if !empty($languages)}`, not `isset()`.
 */
#[Template('languages_new.latte')]
final readonly class LanguagesNewView implements View, HasPageAssets
{
    /**
     * @param list<CatalogLanguageRow> $languages
     */
    public function __construct(
        public int $isWebmaster,
        public array $languages,
    ) {}

    /**
     * `languages_new.latte`'s own unconditional `{do combineScript(...)}`x2/
     * `{do combineCss(...)}` (docs/PLAN.md's P42-B).
     */
    #[Override]
    public function pageAssets(): array
    {
        return [
            AssetContribution::script('languages_new', 'themes/admin/default/js/languages_new.ts', loadMode: LoadMode::Footer),
            AssetContribution::css('themes/admin/default/css/pages/languages_new.css', id: 'languages_new'),
        ];
    }
}
