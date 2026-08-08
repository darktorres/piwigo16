<?php

declare(strict_types=1);

namespace Piwigo\Admin\Projection;

use Override;
use Piwigo\Core\TemplatePageContext;

/**
 * The 'tabsheet'/'tabsheet_selected' template variables assigned by
 * {@see \Piwigo\Admin\Tabsheet::assign()}. The 3rd variable that method
 * assigns (`$this->titlename => ...`) has a genuinely dynamic key (a
 * real, per-instance mutable property any caller can change via
 * `set_titlename()`) -- not included here, stays a documented exception
 * at that call site.
 */
final readonly class TabsheetPageContext implements TemplatePageContext
{
    /**
     * @param array<string, array{caption: string, url: string}> $sheets
     */
    public function __construct(
        public array $sheets,
        public string $selected,
    ) {}

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function toArray(): array
    {
        return [
            'tabsheet' => $this->sheets,
            'tabsheet_selected' => $this->selected,
        ];
    }
}
