<?php

declare(strict_types=1);

namespace Piwigo\Controller\Projection;

use Override;
use Piwigo\Asset\AssetContribution;
use Piwigo\Asset\HasPageAssets;
use Piwigo\Asset\LoadMode;
use Piwigo\Contribution\AuthButton;
use Piwigo\Contribution\ProfileField;
use Piwigo\Core\ExposesPageData;
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
 * `$pluginRegisterFields` is shared by both real templates too --
 * `$template->registerFields()`, P43's typed replacement for a
 * hand-written `set_prefilter('register', ...)` markup patch.
 * `$pluginAuthButtons` is `$template->authButtons()`, the same call
 * `IdentificationView` itself makes.
 */
#[Template('register.latte')]
final readonly class RegisterView implements View, HasPageAssets, ExposesPageData
{
    /**
     * @param array<string, string> $languageOptions
     * @param list<ProfileField> $pluginRegisterFields
     * @param list<AuthButton> $pluginAuthButtons
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
        public array $pluginRegisterFields,
        public array $pluginAuthButtons,
        public bool $formSendPasswordByMail,
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
                AssetContribution::script('standard_pages_js', 'themes/standard_pages/js/standard_pages.ts', loadMode: LoadMode::Async),
            ];
        }

        return [
            // Real shared per-page bundle entry (docs/PLAN.md's P48) --
            // folds scripts.ts's own code in via a direct import
            // instead of the separate `core.scripts` script tag this
            // method used to register directly; shared with the other
            // real pages that need scripts.ts for nothing but its own
            // side effects (see that bundle file's own leading comment).
            AssetContribution::script('core_scripts_page', 'themes/default/js/pages/core_scripts.ts', loadMode: LoadMode::Footer),
            AssetContribution::inlineScript("pwg_tryFocus('login');"),
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
     * The same 2 translated strings the server itself validates with
     * (`RegisterController::__invoke()`'s own password-match check,
     * `UserService::validateMailAddress()`'s own format check) --
     * mirrored client-side in both themes' own JS.
     *
     * @return list<string>
     */
    #[Override]
    public function exposedStrings(): array
    {
        return [
            'The passwords do not match',
            'mail address must be like xxx@yyy.eee (example : jack@altern.org)',
        ];
    }
}
