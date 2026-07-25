<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin;

use Piwigo\Admin\TagsPageRenderer;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Replaces admin/tags.php (page slug "tags") -- pure delegate. Its orphan-
 * tag lookup/delete (TagService::getOrphanTags()/deleteOrphanTags(), P23
 * batch 8d Tags sub-batch) and its per-tag usage counters/alt-names
 * rendering live in TagsPageRenderer itself.
 */
final class TagsSubController implements AdminSubControllerInterface
{
    #[\Override]
    public function handle(ServerRequestInterface $request): void
    {
        \Piwigo\Bootstrap\AdminAccessor::tagsPageRenderer()
            ->render();
    }
}
