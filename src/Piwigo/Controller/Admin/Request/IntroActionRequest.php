<?php

declare(strict_types=1);

namespace Piwigo\Controller\Admin\Request;

/**
 * Validated `$_GET['action']` for IntroSubController::handle() (page slug
 * "intro") -- P27/SEC-40 Request DTO. The only real value this page acts
 * on is the exact literal `hide_newsletter_subscription` (an equality
 * check, not a pattern) -- see this controller's own docblock for why that
 * one write path needs no CSRF token (a per-admin-user UI preference
 * toggle, no cross-user effect).
 */
final readonly class IntroActionRequest
{
    private function __construct(
        public bool $isHideNewsletterSubscription,
    ) {}

    public static function fromGlobals(): self
    {
        return self::fromArray($_GET);
    }

    /**
     * @param array<string, mixed> $source
     */
    public static function fromArray(array $source): self
    {
        return new self(($source['action'] ?? null) === 'hide_newsletter_subscription');
    }
}
