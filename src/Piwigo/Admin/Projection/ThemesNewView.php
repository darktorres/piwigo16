<?php

declare(strict_types=1);

namespace Piwigo\Admin\Projection;

use Piwigo\Core\View;
use Piwigo\Template\Latte\Attribute\Template;

/**
 * `themes_new.latte`'s own typed view, constructed by {@see
 * \Piwigo\Admin\ThemesNewPageRenderer::render()}. `$newThemes` is
 * always included (even empty) since the template reads it with
 * `{if !empty($new_themes)}`, not `isset()`.
 */
#[Template('themes_new.latte')]
final readonly class ThemesNewView implements View
{
    /**
     * @param list<array<string, mixed>> $newThemes
     */
    public function __construct(
        public string $defaultScreenshot,
        public array $newThemes,
    ) {}
}
