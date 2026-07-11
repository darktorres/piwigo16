<?php

declare(strict_types=1);

namespace Piwigo\Session;

/**
 * Typed, request-scoped session value object. Starts genuinely empty --
 * zero typed slots -- growing one slot at a time only when a later phase
 * migrates a piece of code off raw $_SESSION[...] access. Same growth
 * discipline as config/container.php (P8) and config/routes.php (P9),
 * applied to a VO instead of a config array.
 *
 * Nothing currently reads typed session state (no service layer exists
 * yet to want it) -- this is the skeleton future phases populate.
 */
final class Session
{
    /**
     * @param array<mixed> $raw
     */
    public static function fromSuperglobal(array $raw): self
    {
        return new self();
    }

    /**
     * @param array<mixed> $target
     */
    public function persistInto(array &$target): void
    {
        // No typed slots yet -- nothing to write back. Existing raw
        // $_SESSION writes by unmigrated code are untouched, matching the
        // reference's own "co-existence during rollout" design intent.
    }
}
