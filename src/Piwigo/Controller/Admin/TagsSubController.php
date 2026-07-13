<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Replaces admin/tags.php (page slug "tags") -- pure delegate. Its orphan-
 * tag lookup/delete (get_orphan_tags()/delete_orphan_tags()) and its
 * per-tag usage counters/alt-names rendering already live in the shared
 * admin/include/functions.php bridge, reused unchanged by 3 other admin
 * pages and the tags web service (include/ws_functions/pwg.tags.php) --
 * migrating that shared surface into Piwigo\Tag\TagRepository/TagService is
 * real future work but out of this one page's scope (TagRepository's own
 * docblock already documents this class of cross-domain query staying
 * procedural for now, P19).
 */
final class TagsSubController implements AdminSubControllerInterface
{
    #[\Override]
    public function handle(ServerRequestInterface $request): void
    {
        include PHPWG_ROOT_PATH . 'admin/tags.php';
    }
}
