<?php

declare(strict_types=1);

namespace Piwigo\Controller\Projection;

use Override;
use Piwigo\Common\ValueObject\LangCode;
use Piwigo\Core\TemplatePageContext;

/**
 * The template variable set assigned by
 * {@see \Piwigo\Controller\ProfileController}. `$defaultUserValues`
 * stays a loose row shape -- default-user row data with 3 fields coerced
 * to JS-literal 'true'/'false' strings for profile.latte's own inline JS,
 * not a fixed structural shape worth
 * minting its own DTO for here.
 */
final readonly class ProfilePageContext implements TemplatePageContext
{
    /**
     * @param array<string, mixed> $defaultUserValues
     * @param array<string, string> $languageOptions
     */
    public function __construct(
        public array $defaultUserValues,
        public array $languageOptions,
        public LangCode $languageSelection,
        public string $helpLink,
    ) {}

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function toArray(): array
    {
        return [
            'DEFAULT_USER_VALUES' => $this->defaultUserValues,
            'language_options' => $this->languageOptions,
            'language_selection' => $this->languageSelection->value,
            'HELP_LINK' => $this->helpLink,
        ];
    }
}
