<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin;

use Override;
use Piwigo\Admin\TagsPageRenderer;
use Piwigo\Controller\Admin\Projection\AdminPageResult;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Replaces admin/tags.php (page slug "tags") -- pure delegate. Its orphan-
 * tag lookup/delete (TagService::getOrphanTags()/deleteOrphanTags())
 * and its per-tag usage counters/alt-names
 * rendering live in TagsPageRenderer itself.
 */
final readonly class TagsSubController implements AdminSubControllerInterface
{
    public function __construct(
        private TagsPageRenderer $tagsPageRenderer,
    ) {}

    #[Override]
    public function handle(ServerRequestInterface $request): AdminPageResult
    {
        return $this->tagsPageRenderer
            ->render();
    }
}
