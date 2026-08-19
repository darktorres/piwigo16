<?php

declare(strict_types=1);

namespace Piwigo\Admin\Projection;

use Piwigo\Core\View;
use Piwigo\Template\Latte\Attribute\Template;

/**
 * `plugins_new.latte`'s own typed view, constructed by {@see
 * \Piwigo\Admin\PluginsNewPageRenderer::render()}. `$orderSelected`
 * feeds `htmlOptions()`'s own `selected` parameter, which already
 * defaults to and accepts `null` -- an always-present `null` behaves
 * identically to the original conditionally-omitted key. `$plugins` is
 * always included (even empty) since the template reads it with
 * `{if !empty($plugins)}`, not `isset()`.
 */
#[Template('plugins_new.latte')]
final readonly class PluginsNewView implements View
{
    /**
     * @param array<string, string> $orderOptions
     * @param list<array<string, mixed>> $plugins
     */
    public function __construct(
        public array $orderOptions,
        public ?string $orderSelected,
        public ?string $betaUrl,
        public bool $betaTest,
        public array $plugins,
    ) {}
}
