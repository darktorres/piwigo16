<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin;

use Piwigo\Admin\ProfilePageRenderer;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Replaces admin/profile.php (page slug "profile") -- a flat page, pure
 * delegate. See ProfilePageRenderer's own docblock for the full detail on
 * this page's 2 known pre-existing bugs (one fixed by P23 batch 6c, one
 * re-diagnosed but deliberately left unfixed) and task #343's still-
 * deferred build_user()/getuserdata() scope.
 */
final class ProfileSubController implements AdminSubControllerInterface
{
    #[\Override]
    public function handle(ServerRequestInterface $request): void
    {
        new ProfilePageRenderer()
            ->render();
    }
}
