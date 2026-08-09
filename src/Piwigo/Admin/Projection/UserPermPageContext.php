<?php

declare(strict_types=1);

namespace Piwigo\Admin\Projection;

use Override;
use Piwigo\Core\TemplatePageContext;

/**
 * The template variable set assigned by
 * {@see \Piwigo\Admin\UserPermPageRenderer::render()}. `$categoriesBecauseOfGroups`
 * is genuinely optional -- the original code only ever assigns the
 * `categories_because_of_groups` template key when the user has at
 * least one group-granted category, omitted here (not present as a
 * null value) to match that exact original behavior --
 * `user_perm.tpl` reads it via `{if isset($categories_because_of_groups)}`.
 */
final readonly class UserPermPageContext implements TemplatePageContext
{
    /**
     * @param list<string>|null $categoriesBecauseOfGroups
     */
    public function __construct(
        public string $title,
        public string $catOptionsTrueLabel,
        public string $catOptionsFalseLabel,
        public string $formAction,
        public string $pwgToken,
        public ?array $categoriesBecauseOfGroups,
    ) {}

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function toArray(): array
    {
        $result = [
            'TITLE' => $this->title,
            'L_CAT_OPTIONS_TRUE' => $this->catOptionsTrueLabel,
            'L_CAT_OPTIONS_FALSE' => $this->catOptionsFalseLabel,
            'F_ACTION' => $this->formAction,
            'PWG_TOKEN' => $this->pwgToken,
        ];

        if ($this->categoriesBecauseOfGroups !== null) {
            $result['categories_because_of_groups'] = $this->categoriesBecauseOfGroups;
        }

        return $result;
    }
}
