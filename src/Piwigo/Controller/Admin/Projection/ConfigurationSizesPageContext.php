<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin\Projection;

use Override;
use Piwigo\Core\TemplatePageContext;

/**
 * The template variable set assigned by
 * {@see \Piwigo\Controller\Admin\ConfigurationSubController::
 * processSizes()} -- see {@see ConfigurationPageContext} for
 * `handle()`'s own assigns. `$saveSuccess` (the form-submitted-
 * successfully branch) and `$derivatives`/`$ferrors`/`$resizeQuality`/
 * `$sizes` (the validation-failure branch, which also flips `handle()`'s
 * own `$this->sizesLoadedInTpl` guard so its own render-time `sizes`
 * assign is skipped, leaving only the POSTed-back values here) are
 * mutually exclusive. `$sizes` is independently nullable from the other
 * 3 (matching the original's own independent per-field `isset($post[...])`
 * loop) even though in practice `$original_fields` always includes the 3
 * hidden-input fields `configuration_sizes.latte` reads unguarded.
 *
 * `$resizeQuality` is the scalar counterpart of `$sizes`/`$derivatives`/
 * `$ferrors` above -- also a raw, unvalidated POST-back echo for the
 * validation-failure redisplay (the range check at `processSizes()`'s
 * own out-of-range check runs directly against the raw value with no
 * prior cast), but stays `?string` rather than a nested
 * `array<string, mixed>|null` shape since it's a single field, not a
 * record.
 */
final readonly class ConfigurationSizesPageContext implements TemplatePageContext
{
    /**
     * @param array<string, array<string, mixed>>|null $derivatives
     * @param array<string, mixed>|null $ferrors
     * @param array<string, mixed>|null $sizes
     */
    public function __construct(
        public ?string $saveSuccess,
        public ?array $derivatives,
        public ?array $ferrors,
        public ?string $resizeQuality,
        public ?array $sizes,
    ) {}

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function toArray(): array
    {
        $result = [];

        if ($this->saveSuccess !== null) {
            $result['save_success'] = $this->saveSuccess;
        }

        if ($this->derivatives !== null) {
            $result['derivatives'] = $this->derivatives;
            $result['ferrors'] = $this->ferrors;
            $result['resize_quality'] = $this->resizeQuality;
        }

        if ($this->sizes !== null) {
            $result['sizes'] = $this->sizes;
        }

        return $result;
    }
}
