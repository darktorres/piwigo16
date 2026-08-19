<?php

declare(strict_types=1);

namespace Piwigo\Controller\Projection;

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
 * identically to "never assigned".
 */
#[Template('password.latte')]
final readonly class PasswordView implements View
{
    /**
     * @param array<string, string> $languageOptions
     */
    public function __construct(
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
    ) {}
}
