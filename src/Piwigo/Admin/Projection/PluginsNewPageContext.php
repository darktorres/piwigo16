<?php

declare(strict_types=1);

namespace Piwigo\Admin\Projection;

use Override;
use Piwigo\Core\TemplatePageContext;

/**
 * The template variable set assigned by
 * {@see \Piwigo\Admin\PluginsNewPageRenderer::render()}. `$orderSelected`
 * and `$betaUrl` are genuinely optional -- the original code only ever
 * assigns those 2 template keys under their own runtime condition
 * (a successful PEM catalog fetch, a non-beta stable version), omitted
 * here (not present as a null value) to match that exact original
 * behavior.
 */
final readonly class PluginsNewPageContext implements TemplatePageContext
{
    /**
     * @param array<string, string> $orderOptions
     */
    public function __construct(
        public array $orderOptions,
        public ?string $orderSelected,
        public ?string $betaUrl,
        public string $adminPageTitle,
        public bool $betaTest,
    ) {}

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function toArray(): array
    {
        $result = [
            'order_options' => $this->orderOptions,
            'ADMIN_PAGE_TITLE' => $this->adminPageTitle,
            'BETA_TEST' => $this->betaTest,
        ];

        if ($this->orderSelected !== null) {
            $result['order_selected'] = $this->orderSelected;
        }

        if ($this->betaUrl !== null) {
            $result['BETA_URL'] = $this->betaUrl;
        }

        return $result;
    }
}
