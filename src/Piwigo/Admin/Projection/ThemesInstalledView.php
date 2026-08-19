<?php

declare(strict_types=1);

namespace Piwigo\Admin\Projection;

use Piwigo\Core\View;
use Piwigo\Template\Latte\Attribute\Template;

/**
 * `themes_installed.latte`'s own typed view, constructed by {@see
 * \Piwigo\Admin\ThemesInstalledPageRenderer::render()}.
 */
#[Template('themes_installed.latte')]
final readonly class ThemesInstalledView implements View
{
    /**
     * @param list<array<string, mixed>> $tplThemes
     */
    public function __construct(
        public string $activateBaseUrl,
        public string $deactivateBaseUrl,
        public string $setDefaultBaseUrl,
        public string $deleteBaseUrl,
        public array $tplThemes,
        public int $isWebmaster,
        public bool $enableExtensionsInstall,
    ) {}
}
