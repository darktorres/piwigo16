<?php

declare(strict_types=1);

namespace Piwigo\Controller\Projection;

use Override;
use Piwigo\Asset\AssetContribution;
use Piwigo\Asset\HasPageAssets;
use Piwigo\Asset\LoadMode;
use Piwigo\Contribution\AuthButton;
use Piwigo\Core\View;
use Piwigo\Template\Latte\Attribute\Template;

/**
 * `identification.latte`'s own typed view, constructed by {@see
 * \Piwigo\Controller\IdentificationController::__invoke()}. Shared by
 * two real `.latte` files (`themes/default/template/identification.latte`
 * and `themes/standard_pages/template/identification.latte` --
 * `Template::setTheme()` substitutes `standard_pages` for this page) --
 * neither theme's own body references every property here, matching the
 * same asymmetry `RegisterView`'s own docblock explains. `$register`/
 * `$lostPassword` stay nullable: both templates guard them with
 * `isset()`, which treats an explicit `null` identically to "never
 * assigned". `$isStandardPagesTheme` disambiguates `pageAssets()` (a
 * single class-level method, unable to tell which physical file
 * actually resolved) between the two real, entirely different asset
 * lists -- the controller resolves it the same way `Template` itself
 * would, via `$template->themeConf('id') === 'standard_pages'`.
 * `$standardPagesSelectedSkin` is the ambient `$STD_PGS_SELECTED_SKIN`
 * the `standard_pages` file's own `combineCss()` call reads, only
 * meaningful when `$isStandardPagesTheme` is true. `$pluginAuthButtons`
 * is `$template->authButtons()`, the same call `RegisterView` itself
 * makes -- P43's typed replacement for a hand-written
 * `set_prefilter('identification', ...)` markup patch.
 */
#[Template('identification.latte')]
final readonly class IdentificationView implements View, HasPageAssets
{
    /**
     * @param array<string, string> $languageOptions
     * @param list<AuthButton> $pluginAuthButtons
     */
    public function __construct(
        public string $homeUrl,
        public string $redirect,
        public string $loginAction,
        public bool $authorizeRemembering,
        public ?string $register,
        public ?string $lostPassword,
        public array $languageOptions,
        public string $currentLanguage,
        public string $helpLink,
        public bool $isStandardPagesTheme,
        public string $standardPagesSelectedSkin,
        public array $pluginAuthButtons,
    ) {}

    /**
     * `identification.latte`'s own unconditional
     * `{do combineScript(...)}`/`{do footerScript(...)}` (default
     * theme) or `{do combineCss(...)}`x2/`{do combineScript(...)}`
     * (`standard_pages` theme) -- mutually exclusive, only one physical
     * file ever actually renders per request (docs/PLAN.md's P42-B).
     */
    #[Override]
    public function pageAssets(): array
    {
        if ($this->isStandardPagesTheme) {
            return [
                AssetContribution::css('themes/standard_pages/skins/' . $this->standardPagesSelectedSkin . '.css', id: 'standard_pages_css', order: 100),
                AssetContribution::css('themes/default/vendor/fontello/css/gallery-icon.css', order: -10),
                AssetContribution::script('standard_pages_js', 'themes/standard_pages/js/standard_pages.js', loadMode: LoadMode::Async, dependsOn: ['jquery']),
            ];
        }

        return [
            AssetContribution::script('core.scripts', 'themes/default/js/scripts.ts', loadMode: LoadMode::Footer),
            AssetContribution::inlineScript("pwg_tryFocus('username');"),
        ];
    }
}
