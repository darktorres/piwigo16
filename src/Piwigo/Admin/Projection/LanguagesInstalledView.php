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
 * `languages_installed.latte`'s own typed view, constructed by {@see
 * \Piwigo\Admin\LanguagesInstalledPageRenderer::render()}. No
 * `$languageStates` field -- the two states it iterates
 * (`active`/`inactive`) never vary per request, so the template now
 * declares that literal array itself instead of carrying it through
 * this class.
 */
#[Template('languages_installed.latte')]
final readonly class LanguagesInstalledView implements View, HasPageAssets, ExposesPageData
{
    /**
     * @param list<array<string, mixed>> $languages
     */
    public function __construct(
        public array $languages,
        public int $isWebmaster,
        public bool $enableExtensionsInstall,
    ) {}

    /**
     * `languages_installed.latte`'s own unconditional `{do combineScript(...)}`x3/
     * `{do combineCss(...)}` (docs/PLAN.md's P42-B).
     */
    #[Override]
    public function pageAssets(): array
    {
        return [
            AssetContribution::script('common', 'themes/admin/default/js/common.js', loadMode: LoadMode::Footer),
            AssetContribution::script('jquery.confirm', 'https://cdn.jsdelivr.net/npm/jquery-confirm@3.3.4/dist/jquery-confirm.min.js', loadMode: LoadMode::Footer, dependsOn: ['jquery']),
            AssetContribution::css('https://cdn.jsdelivr.net/npm/jquery-confirm@3.3.4/dist/jquery-confirm.min.css'),
            AssetContribution::script('languages_installed', 'themes/admin/default/js/languages_installed.js', loadMode: LoadMode::Footer, dependsOn: ['common', 'jquery.confirm', 'page-data']),
        ];
    }

    /**
     * `languages_installed.latte`'s own unconditional `{do exposeString(...)}` --
     * `'Yes, I am sure'`/`'No, I have changed my mind'` are dropped
     * outright, not ported here (docs/PLAN.md's P42-B theme-base
     * section): they duplicate the confirm-dialog triplet
     * `ThemeBaseAssets` already registers unconditionally for every
     * page, and `exposeString()`'s own dedup-by-key semantics make the
     * duplicate registration redundant.
     */
    #[Override]
    public function exposedPageData(): array
    {
        return [];
    }

    #[Override]
    public function exposedStrings(): array
    {
        return [
            'Are you sure you want to delete the language "%s"?',
        ];
    }
}
