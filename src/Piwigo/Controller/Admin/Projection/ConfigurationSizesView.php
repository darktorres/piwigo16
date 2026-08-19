<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin\Projection;

use Piwigo\Core\View;
use Piwigo\Template\Latte\Attribute\Template;

/**
 * `configuration_sizes.latte`'s own typed view, constructed by {@see
 * \Piwigo\Controller\Admin\ConfigurationSubController::handle()}'s own
 * `'sizes'` case, merged with `processSizes()`'s own POST-handler result
 * (see that method's own return type) and the whole-page shared fields
 * every `configuration_*.latte` tab needs -- see {@see
 * ConfigurationMainView}. `$isGd`/`$sizes` are only ever both non-null
 * together (a fresh render), same for `$derivatives`/`$resizeQuality`;
 * `$ferrors` is only ever non-null on `processSizes()`'s own
 * validation-failure branch. No `$customDerivatives` field -- the
 * template's own body never references it.
 */
#[Template('configuration_sizes.latte')]
final readonly class ConfigurationSizesView implements View
{
    /**
     * @param array<string, mixed>|null $sizes
     * @param array<string, array<string, mixed>>|null $derivatives
     * @param array<string, mixed>|null $ferrors
     */
    public function __construct(
        public ?bool $isGd,
        public ?array $sizes,
        public ?array $derivatives,
        public string|int|null $resizeQuality,
        public ?array $ferrors,
        public string $fAction,
        public ?string $saveSuccess,
        public int $isWebmaster,
        public string $csrfToken,
    ) {}
}
