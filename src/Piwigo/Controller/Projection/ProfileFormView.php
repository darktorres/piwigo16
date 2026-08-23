<?php

declare(strict_types=1);

namespace Piwigo\Controller\Projection;

use Override;
use Piwigo\Contribution\FieldOverride;
use Piwigo\Contribution\FormProvider;
use Piwigo\Contribution\ProfileField;
use Piwigo\Core\ExposesPageData;
use Piwigo\Core\View;
use Piwigo\Template\Latte\Attribute\Template;

/**
 * `profile_content.latte`'s own typed view, constructed by {@see
 * \Piwigo\Controller\ProfileController::__invoke()} and rendered into
 * that page's own {@see ProfileView::$profileContent}. Only the default
 * theme's `profile.latte` ever embeds this render -- `standard_pages`'s
 * own `profile.latte` renders every one of these same fields inline in
 * its own body instead (see `ProfileView`'s own docblock), so this
 * class exists purely for the default theme. `$pluginProfileFields` is
 * `$template->profileFields()`, the same call `ProfileView` itself
 * makes for `standard_pages`'s own inline form -- P43's typed
 * replacement for a hand-written `set_prefilter('profile_content', ...)`
 * markup patch. `$pluginFieldOverrides` is `$template->fieldOverrides()`.
 * `$pluginFormProviders` is `$template->formProviders()`.
 */
#[Template('profile_content.latte')]
final readonly class ProfileFormView implements View, ExposesPageData
{
    /**
     * @param array<int|string, string> $templateOptions
     * @param array<int|string, string> $languageOptions
     * @param array<string, string> $radioOptions
     * @param list<ProfileField> $pluginProfileFields
     * @param list<FieldOverride> $pluginFieldOverrides
     * @param list<FormProvider> $pluginFormProviders
     */
    public function __construct(
        public string $fAction,
        public string $redirect,
        public string $username,
        public bool $specialUser,
        public ?string $email,
        public bool $allowUserCustomization,
        public int $nbImagePage,
        public array $templateOptions,
        public string $templateSelection,
        public array $languageOptions,
        public ?string $languageSelection,
        public int $recentPeriod,
        public array $radioOptions,
        public string $expand,
        public bool $activateComments,
        public string $nbComments,
        public string $nbHits,
        public string $csrfToken,
        public array $pluginProfileFields,
        public array $pluginFieldOverrides,
        public array $pluginFormProviders,
    ) {}

    /**
     * @return array<string, string|int|float|bool|null|array<mixed>>
     */
    #[Override]
    public function exposedPageData(): array
    {
        return [];
    }

    /**
     * The same translated string {@see \Piwigo\Controller\ProfileFormHandler}'s
     * own server-side password-match check uses -- mirrored client-side
     * by `themes/default/js/scripts.js`'s own check on `#use_new_pwd`/
     * `#passwordConf` (the same field ids `password.latte` itself uses,
     * see this class's own docblock). Rendered via `Renderer::render()`
     * inside `ProfileController::__invoke()` before the outer
     * `ProfileView` renders, so this lands in the same
     * `<script id="page-data">` island that page's own `{=getPageDataScript()}`
     * call prints.
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
