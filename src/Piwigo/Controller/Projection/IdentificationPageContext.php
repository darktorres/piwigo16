<?php

declare(strict_types=1);

namespace Piwigo\Controller\Projection;

use Override;
use Piwigo\Core\TemplatePageContext;

/**
 * The template variable set assigned by
 * {@see \Piwigo\Controller\IdentificationController::__invoke()}.
 * `$register` and `$lostPassword` are genuinely optional -- the original
 * code only ever assigns those 2 template keys under their own runtime
 * condition (`CurrentConfig::galleryLocked()`/`allowUserRegistration()`),
 * omitted here (not present as a null value) to match that exact
 * original behavior.
 */
final readonly class IdentificationPageContext implements TemplatePageContext
{
    /**
     * @param array<string, string> $languageOptions
     */
    public function __construct(
        public string $redirect,
        public string $loginAction,
        public bool $authorizeRemembering,
        public ?string $register,
        public ?string $lostPassword,
        public array $languageOptions,
        public string $currentLanguage,
        public string $helpLink,
    ) {}

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function toArray(): array
    {
        $result = [
            'U_REDIRECT' => $this->redirect,
            'F_LOGIN_ACTION' => $this->loginAction,
            'authorize_remembering' => $this->authorizeRemembering,
        ];

        if ($this->register !== null) {
            $result['U_REGISTER'] = $this->register;
        }

        if ($this->lostPassword !== null) {
            $result['U_LOST_PASSWORD'] = $this->lostPassword;
        }

        $result['language_options'] = $this->languageOptions;
        $result['current_language'] = $this->currentLanguage;
        $result['HELP_LINK'] = $this->helpLink;

        return $result;
    }
}
