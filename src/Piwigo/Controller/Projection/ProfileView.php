<?php

declare(strict_types=1);

namespace Piwigo\Controller\Projection;

use Latte\Runtime\Html;
use Override;
use Piwigo\Asset\AssetContribution;
use Piwigo\Asset\HasPageAssets;
use Piwigo\Asset\LoadMode;
use Piwigo\Contribution\FieldOverride;
use Piwigo\Contribution\FormProvider;
use Piwigo\Contribution\ProfileField;
use Piwigo\Core\ExposesPageData;
use Piwigo\Core\View;
use Piwigo\Template\Latte\Attribute\Template;
use Piwigo\Template\Projection\ToasterView;

/**
 * `profile.latte`'s own typed view, constructed by {@see
 * \Piwigo\Controller\ProfileController::__invoke()}. Shared by two real
 * `.latte` files (`themes/default/template/profile.latte` and `themes/
 * standard_pages/template/profile.latte` -- `Template::setTheme()`
 * substitutes `standard_pages` for this page, same as `identification`/
 * `register`/`password`). The default theme's own template only ever
 * reads `$profileContent` (the rendered {@see ProfileFormView}); every
 * other field here exists only for `standard_pages`'s own template,
 * which renders the whole form inline instead of embedding
 * `profile_content.latte`. `$languageOptions`/`$languageSelection` are
 * this page's own current-session values, independent from {@see
 * ProfileFormView}'s own form-submission-scoped ones -- the two renders
 * genuinely use different data, matching what each template's own body
 * has always reflected at its own distinct point in the request.
 * `$isStandardPagesTheme`/`$standardPagesSelectedSkin` disambiguate
 * `pageAssets()` the same way `IdentificationView`'s own do -- the
 * default theme's own template has zero registration calls of its own.
 * `$pluginProfileFields` is `$template->profileFields()` -- the default
 * theme's own template never reads it (it embeds `$profileContent`
 * instead, which already carries `ProfileFormView`'s own copy of the
 * same call).
 */
#[Template('profile.latte')]
final readonly class ProfileView implements View, HasPageAssets, ExposesPageData
{
    /**
     * @param array<string, mixed> $defaultUserValues
     * @param array<int|string, string> $templateOptions
     * @param array<string, string> $languageOptions
     * @param array<int|string, string> $apiExpiration
     * @param list<ProfileField> $pluginProfileFields
     * @param list<FieldOverride> $pluginFieldOverrides
     * @param list<FormProvider> $pluginFormProviders
     */
    public function __construct(
        public Html $profileContent,
        public string $username,
        public ?string $email,
        public bool $allowUserCustomization,
        public array $defaultUserValues,
        public int|string|null $apiSelectedExpiration,
        public bool $apiCanManage,
        public string $helpLink,
        public string $csrfToken,
        public int $nbImagePage,
        public array $templateOptions,
        public string $templateSelection,
        public array $languageOptions,
        public string $languageSelection,
        public int $recentPeriod,
        public string $expand,
        public bool $activateComments,
        public string $nbComments,
        public string $nbHits,
        public bool $specialUser,
        public array $apiExpiration,
        public string $apiCurrentDate,
        public Html $apiEmailInfos,
        public bool $isStandardPagesTheme,
        public string $standardPagesSelectedSkin,
        public array $pluginProfileFields,
        public array $pluginFieldOverrides,
        public array $pluginFormProviders,
    ) {}

    /**
     * `standard_pages/profile.latte`'s own unconditional
     * `{do combineCss(...)}`x4/`{do combineScript(...)}`x3, plus
     * `{include 'toaster.latte'}`'s own contract-only `ToasterView`
     * merged in -- the default theme's own template has zero
     * registration calls of its own (docs/PLAN.md's P42-B).
     */
    #[Override]
    public function pageAssets(): array
    {
        if (! $this->isStandardPagesTheme) {
            // The default theme's own profile_content.latte (embedded via
            // $profileContent) needs this for its own password-match
            // check -- normally auto-registered by RegisterView/
            // PasswordView themselves, this page's own template has zero
            // registration calls of its own (see this class's own
            // docblock), so it's registered here instead.
            return [
                // Real shared per-page bundle entry (docs/PLAN.md's
                // P48) -- folds scripts.ts's own code in via a real
                // direct import instead of the separate `core.scripts`
                // script tag this branch used to register directly;
                // shared with the other real pages that need
                // scripts.ts for nothing but its own side effects (see
                // that bundle file's own leading comment).
                AssetContribution::script('core_scripts_page', 'themes/default/js/pages/core_scripts.ts', loadMode: LoadMode::Footer),
            ];
        }

        return [
            AssetContribution::css('themes/standard_pages/skins/' . $this->standardPagesSelectedSkin . '.css', id: 'standard_pages_css', order: 100),
            AssetContribution::css('themes/default/vendor/fontello/css/gallery-icon.css', order: -10),
            AssetContribution::css('themes/admin/default/fontello/css/fontello.css', order: -11),
            AssetContribution::css('themes/standard_pages/css/pages/profile.css', id: 'profile'),
            AssetContribution::script('standard_pages_js', 'themes/standard_pages/js/standard_pages.ts', loadMode: LoadMode::Async),
            AssetContribution::script('standard_profile_js', 'themes/standard_pages/js/profile.ts', loadMode: LoadMode::Footer),
            ...new ToasterView()
                ->pageAssets(),
        ];
    }

    #[Override]
    public function exposedPageData(): array
    {
        if (! $this->isStandardPagesTheme) {
            return [];
        }

        return [
            'username' => $this->username,
            'email' => $this->email,
            'allow_user_customization' => $this->allowUserCustomization,
            'can_update_password' => ! $this->specialUser,
            'default_user_values' => $this->defaultUserValues,
            'selected_date' => (string) $this->apiSelectedExpiration,
            'api_can_manage' => $this->apiCanManage,
        ];
    }

    /**
     * `standard_pages/profile.latte`'s own unconditional
     * `{do exposeString(...)}`x12 -- the default theme's own template
     * has zero registration calls of its own (docs/PLAN.md's P42-B).
     */
    #[Override]
    public function exposedStrings(): array
    {
        if (! $this->isStandardPagesTheme) {
            return [];
        }

        return [
            'ID copied.',
            'Secret copied. Keep it in a safe place.',
            'Impossible to copy automatically. Please copy manually.',
            'The api key has been successfully created.',
            'Show expired keys',
            'Hide expired keys',
            'An error has occured',
            'Your changes have been applied.',
            'Do you really want to revoke the "%s" API key?',
            'API Key has been successfully revoked.',
            'API Key has been successfully edited.',
            'right now',
            // Same translated string ProfileFormHandler's own server-side
            // password-match check uses -- mirrored client-side by
            // profile.js's own check on this theme's #password_new/
            // #password_conf fields.
            'The passwords do not match',
        ];
    }
}
