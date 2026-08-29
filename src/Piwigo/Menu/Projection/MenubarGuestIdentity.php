<?php

declare(strict_types=1);

namespace Piwigo\Menu\Projection;

/**
 * The identification block's guest half: what `MenubarRenderer` computes
 * when `AccessLevelChecker::isAGuest()`. `$loginUrl`/`$lostPasswordUrl`/
 * `$authorizeRemembering` are non-nullable because that branch assigns
 * all three together, unconditionally -- as eight correlated `?string`s
 * on a flat context that was a docblock note ("mutually exclusive with
 * ...") rather than something the type said.
 *
 * `$registerUrl` is the one genuinely optional value: registration can be
 * disabled while the login form still renders.
 */
final readonly class MenubarGuestIdentity
{
    public function __construct(
        public string $loginUrl,
        public string $lostPasswordUrl,
        public bool $authorizeRemembering,
        public ?string $registerUrl,
    ) {}
}
