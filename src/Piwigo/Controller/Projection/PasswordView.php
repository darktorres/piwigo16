<?php

declare(strict_types=1);

namespace Piwigo\Controller\Projection;

use Override;
use Piwigo\Asset\AssetContribution;
use Piwigo\Asset\HasPageAssets;
use Piwigo\Asset\LoadMode;
use Piwigo\Core\ExposesPageData;
use Piwigo\Core\View;
use Piwigo\Template\Latte\Attribute\Template;

/**
 * `password.latte`'s own typed view, constructed by {@see
 * \Piwigo\Controller\PasswordController::__invoke()}. Shared by two real
 * `.latte` files (`themes/default/template/password.latte` and
 * `themes/standard_pages/template/password.latte` -- `Template::
 * setTheme()` substitutes `standard_pages` for this page), same
 * asymmetry `RegisterView`'s own docblock explains.
 * `$key`/`$usernameOrEmail`/`$isFirstLogin` stay nullable: both
 * templates guard them with `isset()`, which treats an explicit `null`
 * identically to "never assigned". `$isStandardPagesTheme`/
 * `$standardPagesSelectedSkin` disambiguate `pageAssets()` the same way
 * `IdentificationView`'s own do.
 */
#[Template('password.latte')]
final readonly class PasswordView implements View, HasPageAssets, ExposesPageData
{
    /**
     * @param array<string, string> $languageOptions
     */
    public function __construct(
        public string $homeUrl,
        public ?string $key,
        public ?string $usernameOrEmail,
        public ?bool $isFirstLogin,
        public string $title,
        public string $formAction,
        public ?string $action,
        public ?string $username,
        public string $pwgToken,
        public array $languageOptions,
        public string $currentLanguage,
        public string $helpLink,
        public bool $isStandardPagesTheme,
        public string $standardPagesSelectedSkin,
    ) {}

    /**
     * `password.latte`'s own unconditional `{do combineScript(...)}`
     * plus a 3-way `{if $action == 'lost'}{elseif ...}{elseif ...}{/if}`
     * `{do footerScript(...)}` (default theme) or
     * `{do combineCss(...)}`x3/`{do combineScript(...)}`
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
                AssetContribution::css('themes/standard_pages/css/pages/password.css', id: 'password'),
                AssetContribution::script('standard_pages_js', 'themes/standard_pages/js/standard_pages.ts', loadMode: LoadMode::Async, dependsOn: ['jquery']),
            ];
        }

        $assets = [
            // Real shared per-page bundle entry (docs/PLAN.md's P48) --
            // folds scripts.ts's own code in via a direct import
            // instead of the separate `core.scripts` script tag this
            // method used to register directly; shared with the other
            // real pages that need scripts.ts for nothing but its own
            // side effects (see that bundle file's own leading comment).
            AssetContribution::script('core_scripts_page', 'themes/default/js/pages/core_scripts.ts', loadMode: LoadMode::Footer),
        ];

        $focusScript = match ($this->action) {
            'lost' => "pwg_tryFocus('username_or_email');",
            'reset' => "pwg_tryFocus('use_new_pwd');",
            'lost_code' => "pwg_tryFocus('user_code');",
            default => null,
        };

        if ($focusScript !== null) {
            $assets[] = AssetContribution::inlineScript($focusScript);
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
     * The same translated string {@see \Piwigo\Controller\PasswordController}'s
     * own server-side password-match check uses -- mirrored client-side
     * in both themes' own JS.
     *
     * @return list<string>
     */
    #[Override]
    public function exposedStrings(): array
    {
        return [
            'The passwords do not match',
        ];
    }
}
