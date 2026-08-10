<?php

declare(strict_types=1);

namespace Piwigo\Controller\Projection;

use Override;
use Piwigo\Common\ValueObject\Email;
use Piwigo\Common\ValueObject\ThemeId;
use Piwigo\Core\TemplatePageContext;

/**
 * The template variable set assigned by
 * {@see \Piwigo\Controller\ProfileFormHandler::loadIntoTemplate()}.
 * `$templatePrefixe` is the ONE genuinely dynamic key segment the
 * original computed via string concatenation (`$template_prefixe .
 * 'USERNAME'` etc) -- fully enumerable from this method's 2 real
 * callers (`ProfileController` passes none, defaulting to `''`;
 * `ConfigurationSubController`'s own "guest" tab passes `'GUEST_'`),
 * kept as a real `string` field driving the prefix rather than
 * hardcoding either call site's literal. `$languageSelection` is
 * genuinely optional -- the original code only ever assigns that
 * template key under its own runtime condition, omitted here (not
 * present as a null value) to match that exact original behavior.
 */
final readonly class ProfileFormPageContext implements TemplatePageContext
{
    /**
     * @param array<string, string> $radioOptions
     * @param array<int|string, string> $templateOptions
     * @param array<int|string, string> $languageOptions
     * @param array<int|string, string> $apiExpiration
     */
    public function __construct(
        public string $templatePrefixe,
        public string $username,
        public ?Email $email,
        public bool $allowUserCustomization,
        public bool $activateComments,
        public int $nbImagePage,
        public int $recentPeriod,
        public string $expand,
        public string $nbComments,
        public string $nbHits,
        public string $redirect,
        public string $fAction,
        public array $radioOptions,
        public ThemeId $templateSelection,
        public array $templateOptions,
        public ?string $languageSelection,
        public array $languageOptions,
        public bool $specialUser,
        public bool $inAdmin,
        public string $apiCurrentDate,
        public array $apiExpiration,
        public int|string|null $apiSelectedExpiration,
        public bool $apiCanManage,
        public string $apiEmailInfos,
        public string $pwgToken,
    ) {}

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function toArray(): array
    {
        $result = [
            $this->templatePrefixe . 'USERNAME' => $this->username,
            $this->templatePrefixe . 'EMAIL' => $this->email?->value,
            $this->templatePrefixe . 'ALLOW_USER_CUSTOMIZATION' => $this->allowUserCustomization,
            $this->templatePrefixe . 'ACTIVATE_COMMENTS' => $this->activateComments,
            $this->templatePrefixe . 'NB_IMAGE_PAGE' => $this->nbImagePage,
            $this->templatePrefixe . 'RECENT_PERIOD' => $this->recentPeriod,
            $this->templatePrefixe . 'EXPAND' => $this->expand,
            $this->templatePrefixe . 'NB_COMMENTS' => $this->nbComments,
            $this->templatePrefixe . 'NB_HITS' => $this->nbHits,
            $this->templatePrefixe . 'REDIRECT' => $this->redirect,
            $this->templatePrefixe . 'F_ACTION' => $this->fAction,
            'radio_options' => $this->radioOptions,
            'template_selection' => $this->templateSelection->value,
            'template_options' => $this->templateOptions,
            'language_options' => $this->languageOptions,
            'SPECIAL_USER' => $this->specialUser,
            'IN_ADMIN' => $this->inAdmin,
            'API_CURRENT_DATE' => $this->apiCurrentDate,
            'API_EXPIRATION' => $this->apiExpiration,
            'API_SELECTED_EXPIRATION' => $this->apiSelectedExpiration,
            'API_CAN_MANAGE' => $this->apiCanManage,
            'API_EMAIL_INFOS' => $this->apiEmailInfos,
            'PWG_TOKEN' => $this->pwgToken,
        ];

        if ($this->languageSelection !== null) {
            $result['language_selection'] = $this->languageSelection;
        }

        return $result;
    }
}
