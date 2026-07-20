<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin;

use Piwigo\Admin\GroupListPageRenderer;
use Piwigo\Core\RedirectServiceInterface;
use Piwigo\Core\UrlServiceInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Replaces admin/group_list.php (page slug "group_list") -- a flat page,
 * pure delegate. Already used GroupRepository (P18) directly for its own
 * reads before this batch; no write logic of its own to extract (group
 * create/delete/rename go through the WS API, not this page -- confirmed
 * via direct read, its own check_pwg_token() gate for $_GET['delete']/
 * ['toggle_is_default'] is unused dead validation, nothing in the file
 * ever acts on those params -- preserved exactly, not "fixed", since it
 * predates this batch and is out of its scope).
 */
final class GroupListSubController implements AdminSubControllerInterface
{
    public function __construct(
        private readonly RedirectServiceInterface $redirectService,
        private readonly UrlServiceInterface $urlService,
    ) {}

    #[\Override]
    public function handle(ServerRequestInterface $request): void
    {
        new GroupListPageRenderer($this->redirectService, $this->urlService)
            ->render();
    }
}
