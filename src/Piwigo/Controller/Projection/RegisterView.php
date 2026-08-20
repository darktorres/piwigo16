<?php

declare(strict_types=1);

namespace Piwigo\Controller\Projection;

use Override;
use Piwigo\Asset\AssetContribution;
use Piwigo\Asset\HasPageAssets;
use Piwigo\Asset\LoadMode;
use Piwigo\Core\View;
use Piwigo\Template\Latte\Attribute\Template;

/**
 * `register.latte`'s own typed view, constructed by {@see
 * \Piwigo\Controller\RegisterController::__invoke()}. Shared by two real
 * `.latte` files (`themes/default/template/register.latte` and `themes/
 * standard_pages/template/register.latte` -- `Template::setTheme()`
 * substitutes `standard_pages` for this page, same as `identification`/
 * `password`/`profile`), which is why `$currentLanguage` is a plain
 * `string` rather than the `LangCode` the controller reads it from: the
 * default theme's own template never references it, only
 * `standard_pages`'s does, as an array key into `$languageOptions`.
 * `$isStandardPagesTheme`/`$standardPagesSelectedSkin` disambiguate
 * `pageAssets()` the same way `IdentificationView`'s own do.
 */
#[Template('register.latte')]
final readonly class RegisterView implements View, HasPageAssets
{
    /**
     * @param array<string, string> $languageOptions
     */
    public function __construct(
        public string $homeUrl,
        public string $formKey,
        public string $formAction,
        public string $formLogin,
        public string $formEmail,
        public bool $obligatoryUserMailAddress,
        public array $languageOptions,
        public string $currentLanguage,
        public string $helpLink,
        public bool $isStandardPagesTheme,
        public string $standardPagesSelectedSkin,
    ) {}

    /**
     * `register.latte`'s own unconditional `{do combineScript(...)}`/
     * `{do footerScript(...)}` (default theme) or
     * `{do combineCss(...)}`x2/`{do combineScript(...)}`
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
            AssetContribution::script('core.scripts', 'themes/default/js/scripts.js', loadMode: LoadMode::Footer),
            AssetContribution::inlineScript("pwg_tryFocus('login');"),
        ];
    }
}
