<?php

declare(strict_types=1);

namespace Piwigo\Admin\Request;

/**
 * Validated `$_GET` filter/sort shape for RatingUserPageRenderer::render()
 * (page slug "rating_user") -- P27/SEC-40 Request DTO. `orderBy` is left
 * as a raw nullable int here (not clamped to a valid index) since the
 * valid range depends on the renderer's own `$available_order_by` array
 * size, a concern local to that method, not this DTO.
 *
 * Real bug found while auditing this site (not previously known): the
 * original code cast `order_by` straight into an unbounded array index
 * (`$available_order_by[$order_by_index]`) with no range check, so
 * `?order_by=99` on this admin page threw a fatal TypeError out of
 * `uasort()` (a non-callable `null` from the undefined offset). Fixed at
 * the renderer's own call site, where the valid range is known, not here.
 */
final readonly class RatingUserFilterRequest
{
    private function __construct(
        public int $minRates,
        public int $consensusTopNumber,
        public ?int $orderBy,
    ) {}

    public static function fromGlobals(int $defaultConsensusTopNumber): self
    {
        return self::fromArray($_GET, $defaultConsensusTopNumber);
    }

    /**
     * @param array<int|string, mixed> $source
     */
    public static function fromArray(array $source, int $defaultConsensusTopNumber): self
    {
        $f_min_rates = $source['f_min_rates'] ?? null;
        $consensus_top_number = $source['consensus_top_number'] ?? null;
        $order_by = $source['order_by'] ?? null;

        return new self(
            is_numeric($f_min_rates) ? (int) $f_min_rates : 2,
            is_numeric($consensus_top_number) ? (int) $consensus_top_number : $defaultConsensusTopNumber,
            is_numeric($order_by) ? (int) $order_by : null,
        );
    }
}
