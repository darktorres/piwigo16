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
 * 2 real positions: `$isGd`/`$sizes` immediately (`$sizes`'s own
 * checkbox values are merged into the plain PHP `$sizes` array before
 * this first construction), then
 * `$derivatives`/`$resizeQuality`/`$customDerivatives` in a second call
 * once those are computed -- the two calls' own `toArray()` keys never
 * overlap, so the second one's `assignContext()` never wipes the
 * first's (verified live against Template's own `assign()` merge
 * semantics, not assumed).
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
