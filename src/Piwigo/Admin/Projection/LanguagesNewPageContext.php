<?php

declare(strict_types=1);

namespace Piwigo\Admin\Projection;

use Override;
use Piwigo\Core\TemplatePageContext;

/**
 * The template variable set assigned by
 * {@see \Piwigo\Admin\LanguagesNewPageRenderer::render()}. `$languages`
 * is always included (even empty) since `languages_new.tpl` reads it
 * with `{if !empty($languages)}`, not `isset()`.
 */
final readonly class LanguagesNewPageContext implements TemplatePageContext
{
    /**
     * @param list<array<string, mixed>> $languages
     */
    public function __construct(
        public string $adminPageTitle,
        public bool $isWebmaster,
        public array $languages,
    ) {}

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function toArray(): array
    {
        return [
            'ADMIN_PAGE_TITLE' => $this->adminPageTitle,
            'isWebmaster' => $this->isWebmaster ? 1 : 0,
            'languages' => $this->languages,
        ];
    }
}
