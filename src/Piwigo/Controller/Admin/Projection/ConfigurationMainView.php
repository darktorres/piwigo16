<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin\Projection;

use Piwigo\Core\View;
use Piwigo\Template\Latte\Attribute\Template;

/**
 * `configuration_main.latte`'s own typed view, constructed by {@see
 * \Piwigo\Controller\Admin\ConfigurationSubController::handle()}'s own
 * `'main'` case, merged with the whole-page shared fields every
 * `configuration_*.latte` tab needs (`$fAction`/`$saveSuccess`/
 * `$isWebmaster`/`$csrfToken` -- see the sibling `Configuration*View`
 * classes for this same shared set).
 */
#[Template('configuration_main.latte')]
final readonly class ConfigurationMainView implements View
{
    /**
     * @param array<string, mixed> $main
     * @param array<int, string> $groupOptions
     */
    public function __construct(
        public array $main,
        public array $groupOptions,
        public string $fAction,
        public ?string $saveSuccess,
        public int $isWebmaster,
        public string $csrfToken,
    ) {}
}
