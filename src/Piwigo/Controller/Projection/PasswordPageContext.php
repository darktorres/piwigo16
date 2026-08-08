<?php

declare(strict_types=1);

namespace Piwigo\Controller\Projection;

use Override;
use Piwigo\Core\TemplatePageContext;

/**
 * The template variable set assigned by
 * {@see \Piwigo\Controller\PasswordController::__invoke()}. `$key`,
 * `$usernameOrEmail`, and `$isFirstLogin` are genuinely optional --
 * `password.tpl` checks each with its own `{if isset(...)}`, and the
 * original code only ever assigns those 3 template keys under their own
 * runtime condition, omitted here (not present as a null value) to match
 * that exact original behavior.
 */
final readonly class PasswordPageContext implements TemplatePageContext
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

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function toArray(): array
    {
        $result = [
            'title' => $this->title,
            'form_action' => $this->formAction,
            'action' => $this->action,
            'username' => $this->username,
            'PWG_TOKEN' => $this->pwgToken,
        ];

        if ($this->key !== null) {
            $result['key'] = $this->key;
        }

        if ($this->usernameOrEmail !== null) {
            $result['username_or_email'] = $this->usernameOrEmail;
        }

        if ($this->isFirstLogin !== null) {
            $result['is_first_login'] = $this->isFirstLogin;
        }

        $result['language_options'] = $this->languageOptions;
        $result['current_language'] = $this->currentLanguage;
        $result['HELP_LINK'] = $this->helpLink;

        return $result;
    }
}
