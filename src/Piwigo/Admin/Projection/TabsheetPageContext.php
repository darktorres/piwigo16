<?php

declare(strict_types=1);

namespace Piwigo\Admin\Projection;

use Override;
use Piwigo\Core\TemplatePageContext;

/**
 * `{@see \Piwigo\Admin\Tabsheet::$titlename}` alone (default
 * `'TABSHEET_TITLE'`) -- assigned by {@see \Piwigo\Admin\Tabsheet::assign()}.
 * A real, per-instance mutable property (changeable via `setTitlename()`)
 * -- no real caller anywhere in the codebase ever constructs a
 * `Tabsheet` with a non-default titlename, but this class's own Unit
 * tests exercise the general per-instance case directly, so the dynamic
 * key is preserved here rather than dropped -- the campaign goal is
 * zero raw `Template::assign()`/`append()` calls scattered through
 * business logic, not the elimination of every dynamic template key
 * regardless of caller behavior. `tabsheet`/`tabsheet_selected` moved
 * to {@see TabsheetView} -- this class no longer carries them.
 */
final readonly class TabsheetPageContext implements TemplatePageContext
{
    public function __construct(
        public ?string $titlenameKey,
        public ?string $titlenameValue,
    ) {}

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function toArray(): array
    {
        if ($this->titlenameKey === null) {
            return [];
        }

        return [
            $this->titlenameKey => $this->titlenameValue,
        ];
    }
}
