<?php

declare(strict_types=1);

namespace Piwigo\Menu\Projection;

/**
 * One entry of the menubar's "Links" block, built by {@see
 * \Piwigo\Menu\MenubarRenderer::render()} from `CurrentConfig::$links`.
 *
 * `$windowName`/`$windowFeatures` are set together or not at all --
 * `MenuLink::$newWindow` is what decides, and the template used to test
 * for the presence of a nested `new_window` key to say the same thing.
 */
final readonly class MenubarLinkRow
{
    public function __construct(
        public string $url,
        public string $label,
        public ?string $windowName,
        public ?string $windowFeatures,
    ) {}
}
