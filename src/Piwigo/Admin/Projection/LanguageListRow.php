<?php

declare(strict_types=1);

namespace Piwigo\Admin\Projection;

/**
 * {@see \Piwigo\Admin\LanguagesInstalledPageRenderer::render()}'s own
 * per-language row for `languages_installed.latte`. Narrowed to the 6
 * fields the template actually reads (`name`/`u_action`/`state`/
 * `deactivable`/`deactivate_tooltip`/`is_default`) -- the original array
 * also carried `code`/`version`/`uri`/`author` straight from
 * `LanguageScanRow`, but grepping the template confirmed none of the 4 are
 * ever read.
 */
final readonly class LanguageListRow
{
    public function __construct(
        public string $name,
        public string $uAction,
        public string $state,
        public bool $deactivable,
        public ?string $deactivateTooltip,
        public bool $isDefault,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'u_action' => $this->uAction,
            'state' => $this->state,
            'deactivable' => $this->deactivable,
            'deactivate_tooltip' => $this->deactivateTooltip,
            'is_default' => $this->isDefault,
        ];
    }
}
