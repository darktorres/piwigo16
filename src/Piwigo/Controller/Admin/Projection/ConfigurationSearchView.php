<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin\Projection;

use Piwigo\Core\View;
use Piwigo\Template\Latte\Attribute\Template;

/**
 * `configuration_search.latte`'s own typed view, constructed by {@see
 * \Piwigo\Controller\Admin\ConfigurationSubController::handle()}'s own
 * `'search'` case, merged with the whole-page shared fields every
 * `configuration_*.latte` tab needs -- see {@see ConfigurationMainView}.
 */
#[Template('configuration_search.latte')]
final readonly class ConfigurationSearchView implements View
{
    /**
     * @param array<string, mixed> $search
     */
    public function __construct(
        public array $search,
        public bool $showFilterRatings,
        public string $fAction,
        public ?string $saveSuccess,
        public int $isWebmaster,
        public string $csrfToken,
    ) {}
}
