<?php

declare(strict_types=1);

namespace Piwigo\Admin;

use Piwigo\Admin\Projection\UserRatingRow;

/**
 * Gathers one user's rates while
 * {@see RatingUserPageRenderer::render()} walks the rate table, then
 * freezes them into a {@see UserRatingRow}.
 *
 * The row used to be an array built across three separate mutation
 * points -- a model copy, the per-score appends, and a by-reference
 * `$rating += [...]` pass adding the statistics -- which is why
 * everything downstream of it, the template and all five comparators
 * included, had to read it defensively. The mutable half lives here
 * now and the statistics are computed in one place, so what leaves
 * freeze() is complete.
 */
final class UserRatingAccumulator
{
    /**
     * @var array<int, list<int>> element ids keyed by the score given
     */
    private array $rates = [];

    private string $firstDate;

    /**
     * @param list<int> $rateItems every configured score, so an
     *   unrated one still gets its (empty) bucket
     */
    public function __construct(
        private readonly int $uid,
        private readonly string $aid,
        private readonly string $lastDate,
        array $rateItems,
    ) {
        foreach ($rateItems as $rate) {
            $this->rates[$rate] = [];
        }

        $this->firstDate = $lastDate;
    }

    /**
     * Rows arrive newest-first (findAllRatesOrderedByDateDesc()), so
     * the date of the row that happens to be last is the oldest one --
     * hence the unconditional overwrite, which for the very first row
     * simply rewrites what the constructor already set.
     */
    public function add(int $rate, int $elementId, string $date): void
    {
        $this->rates[$rate][] = $elementId;
        $this->firstDate = $date;
    }

    /**
     * @param array<int, float> $averageByElement every element's own
     *   mean rate, for the consensus deviation
     * @param array<int, mixed> $bestRated keyed by element id; only
     *   the keys are read
     */
    public function freeze(array $averageByElement, array $bestRated): UserRatingRow
    {
        $count = 0;
        $sum = 0;
        $sum_squares = 0;
        $consensus_dev = 0.0;
        $consensus_dev_top = 0.0;
        $consensus_dev_top_count = 0;

        foreach ($this->rates as $rate => $element_ids) {
            $rate_count = count($element_ids);
            $count += $rate_count;
            $sum += $rate_count * $rate;
            $sum_squares += $rate_count * $rate * $rate;

            foreach ($element_ids as $element_id) {
                // An element with no average of its own contributes
                // its own score as the deviation, which is what
                // reading a missing key used to yield -- minus the
                // warning it used to raise on the way.
                $dev = abs((float) $rate - ($averageByElement[$element_id] ?? 0.0));
                $consensus_dev += $dev;

                if (isset($bestRated[$element_id])) {
                    $consensus_dev_top += $dev;
                    $consensus_dev_top_count++;
                }
            }
        }

        $consensus_dev /= (float) $count;

        if ($consensus_dev_top_count > 0) {
            $consensus_dev_top /= (float) $consensus_dev_top_count;
        }

        $variance = ((float) $sum_squares - (float) $sum * (float) $sum / (float) $count) / (float) $count;

        return new UserRatingRow(
            uid: $this->uid,
            aid: $this->aid,
            firstDate: $this->firstDate,
            lastDate: $this->lastDate,
            count: $count,
            avg: $sum / $count,
            // http://en.wikipedia.org/wiki/Coefficient_of_variation
            cv: $sum === 0 ? -1.0 : sqrt($variance) / ((float) $sum / (float) $count),
            cd: $consensus_dev,
            cdTop: $consensus_dev_top_count > 0 ? $consensus_dev_top : null,
            rates: $this->rates,
        );
    }
}
