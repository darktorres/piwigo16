<?php

declare(strict_types=1);

namespace Piwigo\Controller\Projection;

use Piwigo\Core\View;
use Piwigo\Template\Latte\Attribute\Template;

/**
 * `tags.latte`'s own typed view, constructed by {@see
 * \Piwigo\Controller\TagsController::__invoke()} in place of its former
 * `TagsDisplayModePageContext`. `$letters`/`$tags` are mutually
 * exclusive (populated by the controller's own `$display_mode ===
 * 'letters'` if/else) and both genuinely optional -- `tags.latte` reads
 * each with its own `isset($letters)`/`isset($tags)` guard, which treats
 * an explicit `null` identically to "never assigned".
 */
#[Template('tags.latte')]
final readonly class TagsView implements View
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
}
