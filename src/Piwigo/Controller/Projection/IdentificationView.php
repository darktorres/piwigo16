<?php

declare(strict_types=1);

namespace Piwigo\Controller\Projection;

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
 * assigned".
 */
#[Template('identification.latte')]
final readonly class IdentificationView implements View
{
    /**
     * @param array<string, string> $languageOptions
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
    ) {}
}
