<?php

declare(strict_types=1);

namespace Piwigo\Admin\Projection;

use Override;
use Piwigo\Core\TemplatePageContext;

/**
 * The template variable set assigned by
 * {@see \Piwigo\Admin\UpdatesExtPageRenderer::render()}. `ADMIN_PAGE_TITLE`
 * is included here even though the original assigned it *after*
 * `updates_ext.latte` was already parsed -- `updates_ext.latte`
 * itself never reads `$ADMIN_PAGE_TITLE` (confirmed by direct read), so
 * moving its assignment earlier doesn't change that parse's output, and
 * it's still set before the page header (the only real reader) parses
 * later in the request.
 */
final readonly class UpdatesExtPageContext implements TemplatePageContext
{
    /**
     * @param array<string, list<array<string, mixed>>> $updatesExtension
     */
    public function __construct(
        public array $updatesExtension,
        public bool $showReset,
        public string $pwgToken,
        public string $extType,
        public int $isWebmaster,
        public string $adminPageTitle,
    ) {}

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function toArray(): array
    {
        return [
            'UPDATES_EXTENSION' => $this->updatesExtension,
            'SHOW_RESET' => $this->showReset,
            'CSRF_TOKEN' => $this->pwgToken,
            'EXT_TYPE' => $this->extType,
            'isWebmaster' => $this->isWebmaster,
            'ADMIN_PAGE_TITLE' => $this->adminPageTitle,
        ];
    }
}
