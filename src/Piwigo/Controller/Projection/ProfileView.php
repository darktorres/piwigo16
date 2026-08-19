<?php

declare(strict_types=1);

namespace Piwigo\Controller\Projection;

use Latte\Runtime\Html;
use Piwigo\Core\View;
use Piwigo\Template\Latte\Attribute\Template;

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
 */
#[Template('profile.latte')]
final readonly class ProfileView implements View
{
    /**
     * @param array<string, mixed> $defaultUserValues
     * @param array<int|string, string> $templateOptions
     * @param array<string, string> $languageOptions
     * @param array<int|string, string> $apiExpiration
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
        public string $apiEmailInfos,
    ) {}
}
