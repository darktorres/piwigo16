<?php

declare(strict_types=1);

namespace Piwigo\Admin\Projection;

use Override;
use Piwigo\Core\TemplatePageContext;

/**
 * The 'tabsheet'/'tabsheet_selected' template variables assigned by
 * {@see \Piwigo\Admin\Tabsheet::assign()}. `$titlenameKey` is a real,
 * per-instance mutable property (`Tabsheet::$titlename`, changeable via
 * `set_titlename()`) -- no real caller anywhere in the codebase ever
 * constructs a `Tabsheet` with a non-default titlename (confirmed via a
 * full-repo grep of every `new Tabsheet(...)` site), but this class's own
 * Unit tests exercise the general per-instance case directly, so the
 * dynamic key is preserved here (inside this one documented `toArray()`)
 * rather than dropped -- the campaign goal is zero raw
 * `Template::assign()`/`append()` calls scattered through business logic,
 * not the elimination of every dynamic Smarty key regardless of caller
 * behavior.
 */
final readonly class TabsheetPageContext implements TemplatePageContext
{
    /**
     * @param array<string, array{caption: string, url: string}> $sheets
     */
    public function __construct(
        public array $sheets,
        public string $selected,
        public ?string $titlenameKey,
        public ?string $titlenameValue,
    ) {}

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function toArray(): array
    {
        $result = [
            'tabsheet' => $this->sheets,
            'tabsheet_selected' => $this->selected,
        ];

        if ($this->titlenameKey !== null) {
            $result[$this->titlenameKey] = $this->titlenameValue;
        }

        return $result;
    }
}
