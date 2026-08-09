<?php

declare(strict_types=1);

namespace Piwigo\Admin\Projection;

use Override;
use Piwigo\Core\TemplatePageContext;

/**
 * The template variable set assigned by
 * {@see \Piwigo\Admin\LanguagesInstalledPageRenderer::render()}.
 * `language_states` is a fixed, compile-time-constant list (the original
 * code's own 2 unconditional `Template::append()` calls never varied
 * per-request) -- hardcoded directly in `toArray()` below instead of
 * being threaded through the constructor.
 */
final readonly class LanguagesInstalledPageContext implements TemplatePageContext
{
    /**
     * @param list<array<string, mixed>> $languages
     */
    public function __construct(
        public array $languages,
        public bool $isWebmaster,
        public string $adminPageTitle,
        public bool $enableExtensionsInstall,
    ) {}

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function toArray(): array
    {
        return [
            'languages' => $this->languages,
            'isWebmaster' => $this->isWebmaster ? 1 : 0,
            'ADMIN_PAGE_TITLE' => $this->adminPageTitle,
            'CONF_ENABLE_EXTENSIONS_INSTALL' => $this->enableExtensionsInstall,
            'language_states' => ['active', 'inactive'],
        ];
    }
}
