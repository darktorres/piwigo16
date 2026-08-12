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
 * small, fully-enumerable set, not genuinely dynamic. `$letters`/`$tags`
 * are mutually exclusive (populated by the controller's own
 * `$display_mode === 'letters'` if/else) and both genuinely optional --
 * `tags.latte` reads each with its own `isset($letters)`/`isset($tags)`
 * guard, and either loop can legitimately produce zero real rows even
 * on its own active branch (an empty tag list), matching the original
 * code's own "never appended, stays unset" behavior.
 */
final readonly class TagsDisplayModePageContext implements TemplatePageContext
{
    /**
     * @param list<array{TITLE: string|null, tags: list<array<string, mixed>>, CHANGE_COLUMN?: bool}>|null $letters
     * @param list<array<string, mixed>>|null $tags
     */
    public function __construct(
        public string $cloudUrl,
        public string $lettersUrl,
        public string $displayMode,
        public ?array $letters,
        public ?array $tags,
    ) {}

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function toArray(): array
    {
        $result = [
            'U_CLOUD' => $this->cloudUrl,
            'U_LETTERS' => $this->lettersUrl,
            'display_mode' => $this->displayMode,
        ];

        if ($this->letters !== null) {
            $result['letters'] = $this->letters;
        }

        if ($this->tags !== null) {
            $result['tags'] = $this->tags;
        }

        return $result;
    }
}
