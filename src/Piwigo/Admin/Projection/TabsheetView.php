<?php

declare(strict_types=1);

namespace Piwigo\Admin\Projection;

use Piwigo\Core\View;
use Piwigo\Template\Latte\Attribute\Template;

/**
 * `tabsheet.latte`'s own typed view -- rendered by {@see
 * \Piwigo\Admin\Tabsheet::assign()}. `Piwigo\Admin\*` is L4Integration
 * (may depend on `Renderer`/`View`, L3Presentation, directly), same
 * shape as `Piwigo\Menu\BlockManager`.
 */
#[Template('tabsheet.latte')]
final readonly class TabsheetView implements View
{
    /**
     * @param array<string, array{caption: string, url: string}> $sheets
     */
    public function __construct(
        public array $sheets,
        public string $selected,
    ) {}
}
