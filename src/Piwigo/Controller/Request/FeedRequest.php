<?php

declare(strict_types=1);

namespace Piwigo\Controller\Request;

use Piwigo\Validation\InputValidator;

/**
 * Validated request shape for FeedController::__invoke() (replaces
 * feed.php) -- P27/SEC-40 Request DTO. `feed` (a 50-char hex per-user feed
 * token) is pattern-validated, not mandatory: an absent value means the
 * generic (non-personalized) feed. `image_only` is presence-only.
 */
final readonly class FeedRequest
{
    private function __construct(
        public string $feedId,
        public bool $imageOnly,
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
        new InputValidator()->validate('feed', $source, false, '/^[0-9a-z]{50}$/i');

        $feed_id = $source['feed'] ?? '';
        $feed_id = is_string($feed_id) ? $feed_id : '';

        return new self($feed_id, isset($source['image_only']));
    }
}
