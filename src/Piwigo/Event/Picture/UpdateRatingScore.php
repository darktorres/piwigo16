<?php

declare(strict_types=1);

namespace Piwigo\Event\Picture;

/**
 * Typed event for the legacy `update_rating_score` filter. No handler
 * is registered for it anywhere today. `$result` diverges from the
 * reference's plain `bool $done`: this branch's own real dispatch site
 * (`RateService::updateRatingScore()`) starts from the literal `false`
 * and lets a handler override the WHOLE return value with an array, so
 * the property must carry both. Mutable on `$result`; `$elementId`
 * stays context.
 */
final class UpdateRatingScore
{
    /**
     * @param bool|array<string, mixed> $result
     */
    public function __construct(
        public bool|array $result,
        public readonly int|false $elementId,
    ) {}
}
