<?php

declare(strict_types=1);

namespace Piwigo\Menu\Projection;

/**
 * The identification block's identified-user half. Each of the three URLs
 * is its own permission answer, so each is nullable on its own terms:
 * `$profileUrl` needs at least Classic status, `$logoutUrl` is absent
 * under Apache authentication (where logging out is not possible), and
 * `$adminUrl` needs admin status.
 */
final readonly class MenubarUserIdentity
{
    public function __construct(
        public string $username,
        public ?string $profileUrl,
        public ?string $logoutUrl,
        public ?string $adminUrl,
    ) {}
}
