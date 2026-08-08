<?php

declare(strict_types=1);

namespace Piwigo\Controller\Projection;

use Override;
use Piwigo\Core\TemplatePageContext;

/**
 * The template variable set assigned by
 * {@see \Piwigo\Controller\TagsController}'s own display-mode-switcher
 * setup -- the source `foreach (['cloud', 'letters'] as $mode)` loop
 * only ever produces these 2 fixed keys (`U_CLOUD`/`U_LETTERS`), a
 * small, fully-enumerable set, not genuinely dynamic.
 */
final readonly class TagsDisplayModePageContext implements TemplatePageContext
{
    public function __construct(
        public string $cloudUrl,
        public string $lettersUrl,
        public string $displayMode,
    ) {}

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function toArray(): array
    {
        return [
            'U_CLOUD' => $this->cloudUrl,
            'U_LETTERS' => $this->lettersUrl,
            'display_mode' => $this->displayMode,
        ];
    }
}
