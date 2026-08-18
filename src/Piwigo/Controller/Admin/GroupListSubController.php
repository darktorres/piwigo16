<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin;

use Override;
use Piwigo\Admin\GroupListPageRenderer;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Replaces admin/group_list.php (page slug "group_list") -- a flat page,
 * pure delegate. Already uses GroupRepository directly for its own
 * reads; no write logic of its own to extract (group
 * create/delete/rename go through `/api/v1/groups`, not this page -- confirmed
 * via direct read, its own check_pwg_token() gate for $_GET['delete']/
 * ['toggle_is_default'] is unused dead validation, nothing in the file
 * ever acts on those params -- preserved exactly, not "fixed").
 */
final readonly class GroupListSubController implements AdminSubControllerInterface
{
    public function __construct(
        private GroupListPageRenderer $groupListPageRenderer,
    ) {}

    #[Override]
    public function handle(ServerRequestInterface $request): void
    {
        $this->groupListPageRenderer
            ->render();
    }
}
