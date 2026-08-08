<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin\Projection;

use Override;
use Piwigo\Core\TemplatePageContext;

/**
 * The template variable set assigned by the `'search'` case of
 * {@see \Piwigo\Controller\Admin\ConfigurationSubController::handle()}'s
 * own render-time `switch`.
 */
final readonly class ConfigurationSearchTabPageContext implements TemplatePageContext
{
    /**
     * @param array<string, mixed> $search
     */
    public function __construct(
        public array $search,
        public bool $showFilterRatings,
    ) {}

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function toArray(): array
    {
        return [
            'search' => $this->search,
            'SHOW_FILTER_RATINGS' => $this->showFilterRatings,
        ];
    }
}
