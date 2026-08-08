<?php

declare(strict_types=1);

namespace Piwigo\Controller\Projection;

use Override;
use Piwigo\Common\ValueObject\LangCode;
use Piwigo\Core\TemplatePageContext;

/**
 * The template variable set assigned by
 * {@see \Piwigo\Controller\RegisterController}.
 */
final readonly class RegisterPageContext implements TemplatePageContext
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
        public LangCode $currentLanguage,
        public string $helpLink,
    ) {}

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function toArray(): array
    {
        return [
            'U_HOME' => $this->homeUrl,
            'F_KEY' => $this->formKey,
            'F_ACTION' => $this->formAction,
            'F_LOGIN' => $this->formLogin,
            'F_EMAIL' => $this->formEmail,
            'obligatory_user_mail_address' => $this->obligatoryUserMailAddress,
            'language_options' => $this->languageOptions,
            'current_language' => $this->currentLanguage->value,
            'HELP_LINK' => $this->helpLink,
        ];
    }
}
