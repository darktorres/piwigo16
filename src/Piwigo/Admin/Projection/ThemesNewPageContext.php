<?php

declare(strict_types=1);

namespace Piwigo\Admin\Projection;

use Override;
use Piwigo\Core\TemplatePageContext;

/**
 * The template variable set assigned by
 * {@see \Piwigo\Admin\ThemesNewPageRenderer::render()}. `$newThemes` is
 * always included (even empty) since `themes_new.latte` reads it with
 * `{if not empty($new_themes)}`, not `isset()`.
 */
final readonly class ThemesNewPageContext implements TemplatePageContext
{
    /**
     * @param list<array<string, mixed>> $newThemes
     */
    public function __construct(
        public string $defaultScreenshot,
        public string $adminPageTitle,
        public array $newThemes,
    ) {}

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function toArray(): array
    {
        return [
            'default_screenshot' => $this->defaultScreenshot,
            'ADMIN_PAGE_TITLE' => $this->adminPageTitle,
            'new_themes' => $this->newThemes,
        ];
    }
}
