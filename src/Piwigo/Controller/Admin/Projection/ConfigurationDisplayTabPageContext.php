<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin\Projection;

use Override;
use Piwigo\Core\TemplatePageContext;

/**
 * The template variable set assigned by the `'display'` case of
 * {@see \Piwigo\Controller\Admin\ConfigurationSubController::handle()}'s
 * own render-time `switch`. `$display` is always included (never
 * optional) -- `configuration_display.latte` reads `$display.*` fields
 * unguarded, and the original code's own 2 unconditional
 * `Template::append($tpl_var, ..., merge: true)` calls (a checkbox loop
 * plus 2 extra keys) both ran unconditionally whenever this case fires,
 * same as {@see ConfigurationCommentsPageContext}'s single-array shape.
 */
final readonly class ConfigurationDisplayTabPageContext implements TemplatePageContext
{
    /**
     * @param array<string, mixed> $display
     */
    public function __construct(
        public array $display,
    ) {}

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function toArray(): array
    {
        return [
            'display' => $this->display,
        ];
    }
}
