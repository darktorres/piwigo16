<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin\Projection;

use Override;
use Piwigo\Core\TemplatePageContext;

/**
 * The template variable set assigned by the `'sizes'` case of
 * {@see \Piwigo\Controller\Admin\ConfigurationSubController::handle()}'s
 * own render-time `switch` -- distinct from {@see
 * ConfigurationSizesPageContext}, which is `processSizes()`'s own POST
 * handler context. Constructed via 2 separate calls at that case's own
 * 2 real positions: `$isGd`/`$sizes` immediately (a later
 * `Template::append()` loop needs `sizes` already live, same reason
 * documented on {@see ConfigurationMainPageContext}), then
 * `$derivatives`/`$resizeQuality`/`$customDerivatives` after that loop
 * (no such constraint applies to them).
 */
final readonly class ConfigurationSizesTabPageContext implements TemplatePageContext
{
    /**
     * @param array<string, mixed>|null $sizes
     * @param array<string, array<string, mixed>>|null $derivatives
     * @param array<string, string>|null $customDerivatives
     */
    public function __construct(
        public ?bool $isGd,
        public ?array $sizes,
        public ?array $derivatives,
        public ?int $resizeQuality,
        public ?array $customDerivatives,
    ) {}

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function toArray(): array
    {
        $result = [];

        if ($this->isGd !== null) {
            $result['is_gd'] = $this->isGd;
            $result['sizes'] = $this->sizes;
        }

        if ($this->derivatives !== null) {
            $result['derivatives'] = $this->derivatives;
            $result['resize_quality'] = $this->resizeQuality;
            $result['custom_derivatives'] = $this->customDerivatives;
        }

        return $result;
    }
}
