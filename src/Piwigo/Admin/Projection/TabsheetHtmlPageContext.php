<?php

declare(strict_types=1);

namespace Piwigo\Admin\Projection;

use Latte\Runtime\Html;
use Override;
use Piwigo\Core\TemplatePageContext;

/**
 * `{@see \Piwigo\Admin\Tabsheet::$name}` (default `'TABSHEET'`) alone --
 * {@see \Piwigo\Admin\Tabsheet::assign()} renders {@see TabsheetView} via
 * `Renderer::render()` and writes the result into `Template::$vars`
 * under this ambient, per-instance-overridable key, same one-field
 * -wrapper shape as {@see \Piwigo\Menu\Projection\MenubarHtmlPageContext}.
 * Kept a real dynamic key (not hardcoded to `'TABSHEET'`) even though
 * every one of the 29 real `new Tabsheet(...)` call sites today uses
 * the bare, no-args constructor -- same "genuine per-instance
 * flexibility, not eliminated just because nothing currently exercises
 * it" judgment {@see TabsheetPageContext} already made for
 * `$titlename`.
 */
final readonly class TabsheetHtmlPageContext implements TemplatePageContext
{
    public function __construct(
        public string $var,
        public Html $html,
    ) {}

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function toArray(): array
    {
        return [
            $this->var => $this->html,
        ];
    }
}
