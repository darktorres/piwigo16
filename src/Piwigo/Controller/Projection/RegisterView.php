<?php

declare(strict_types=1);

namespace Piwigo\Controller\Projection;

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
 */
#[Template('register.latte')]
final readonly class RegisterView implements View
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
    ) {}
}
