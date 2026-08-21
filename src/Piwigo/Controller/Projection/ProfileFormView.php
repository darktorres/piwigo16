<?php

declare(strict_types=1);

namespace Piwigo\Controller\Projection;

use Piwigo\Contribution\ProfileField;
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
 * markup patch.
 */
#[Template('profile_content.latte')]
final readonly class ProfileFormView implements View
{
    /**
     * @param array<int|string, string> $templateOptions
     * @param array<int|string, string> $languageOptions
     * @param array<string, string> $radioOptions
     * @param list<ProfileField> $pluginProfileFields
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
    ) {}
}
