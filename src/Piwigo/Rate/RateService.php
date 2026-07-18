<?php

declare(strict_types=1);

namespace Piwigo\Rate;

use Piwigo\Auth\CookieService;
use Piwigo\Core\AccessLevel;

/**
 * Rate domain business logic: submitting a rate, recomputing the bayesian
 * `images.rating_score` (http://en.wikipedia.org/wiki/Bayesian_average).
 * Constructor-injects RateRepository + Piwigo\Auth\CookieService (the
 * anonymous-rater cookie) -- Auth lives in L2aCoreDomain, Rate in
 * L2bExtendedDomain, so a real class-to-class dependency there is allowed,
 * same shape as CommentService's EphemeralKeyService dependency.
 *
 * is_autorize_status()/AccessLevel and the `$conf`/`$user` globals they and
 * this class read are called exactly as the original functions_rate.inc.php
 * did -- the entire access-level-check family is explicitly out of scope
 * for this phase (see task #343).
 */
final class RateService
{
    public function __construct(
        private readonly RateRepository $repo,
        private readonly CookieService $cookies,
    ) {}

    /**
     * Rates a picture by the current user. Returns updateRatingScore()'s
     * result, or false if the rate is invalid or forbidden.
     *
     * @param int|string|null $rate raw $_POST value (string) from
     *   picture.php, an (int)-cast value from the WS layer, or null when
     *   absent
     * @return array<string, mixed>|false
     */
    public function rate(int $imageId, int|string|null $rate): array|false
    {
        /** @var array<string, mixed> $conf */
        global $conf;

        $rateItems = \Piwigo\Config\Config::rateItems();

        if (
            $rate === null
            || ! \Piwigo\Config\Config::rateEnabled()
            || ! (bool) preg_match('/^[0-9]+$/', (string) $rate)
        ) {
            return false;
        }

        // The regex above already guarantees $rate is a string of digits
        // (or was already a non-negative int), so this round trip is safe
        // -- normalize before the membership check so it's a strict, not
        // loose, comparison against $rateItems.
        $rateInt = (int) $rate;
        if (! in_array($rateInt, $rateItems, true)) {
            return false;
        }

        $userAnonymous = ! \Piwigo\Auth\AccessControl::isAuthorizeStatus(AccessLevel::Classic);

        if ($userAnonymous && ! \Piwigo\Config\Config::rateAnonymous()) {
            return false;
        }

        $userId = \Piwigo\Users\CurrentUser::get()->id;

        $remoteAddr = $_SERVER['REMOTE_ADDR'] ?? '';
        $remoteAddr = is_string($remoteAddr) ? $remoteAddr : '';
        $ipComponents = explode('.', $remoteAddr);
        if (count($ipComponents) > 3) {
            array_pop($ipComponents);
        }

        $anonymousId = implode('.', $ipComponents);

        if ($userAnonymous) {
            $savedAnonymousId = $this->cookies->getCookieVar('anonymous_rater', $anonymousId);
            $savedAnonymousId = is_string($savedAnonymousId) ? $savedAnonymousId : $anonymousId;

            if ($anonymousId !== $savedAnonymousId) { // client has changed IP address, or is trying to fool us
                $existingElementIds = $this->repo->findElementIdsForUserAndAnonymousId($userId, $anonymousId);
                if ($existingElementIds !== []) {
                    $this->repo->deleteByUserAnonymousAndElements($userId, $savedAnonymousId, $existingElementIds);
                }

                $this->repo->reassignAnonymousId($userId, $savedAnonymousId, $anonymousId);
            }

            $this->cookies->setCookieVar('anonymous_rater', $anonymousId);
        }

        $this->repo->deleteExistingRate($imageId, $userId, $userAnonymous ? $anonymousId : null);
        $this->repo->insertRate($imageId, $userId, $anonymousId, $rateInt);

        return $this->updateRatingScore($imageId);
    }

    /**
     * Recomputes images.rating_score for every rated image (a bayesian
     * average -- C = average number of rates per item, m = global average
     * rate), and clears rating_score for images that no longer have any
     * rate at all.
     *
     * @return array<string, mixed> (score, average, count); values are
     *   null/0 if $elementId is false or has no rates of its own
     */
    public function updateRatingScore(int|false $elementId = false): array
    {
        $altResult = trigger_change('update_rating_score', false, $elementId);
        if ($altResult !== false && is_array($altResult)) {
            /** @var array<string, mixed> $altResult */
            return $altResult;
        }

        $byItem = $this->repo->findRateSummaries();

        $allRatesCount = 0;
        $allRatesAvg = 0.0;
        $itemRatecountAvg = 0.0;
        foreach ($byItem as $summary) {
            $allRatesCount += $summary['rcount'];
            $allRatesAvg += $summary['rsum'];
        }

        if ($allRatesCount > 0) {
            $allRatesAvg /= $allRatesCount;
            $itemRatecountAvg = $allRatesCount / count($byItem);
        }

        $return = null;
        $updates = [];
        foreach ($byItem as $id => $summary) {
            $score = ($itemRatecountAvg * $allRatesAvg + $summary['rsum']) / ($itemRatecountAvg + $summary['rcount']);
            $score = round($score, 2);
            if ($id === $elementId) {
                $return = [
                    'score' => $score,
                    'average' => round($summary['rsum'] / $summary['rcount'], 2),
                    'count' => $summary['rcount'],
                ];
            }

            $updates[] = [
                'id' => $id,
                'ratingScore' => $score,
            ];
        }

        $this->repo->updateRatingScores($updates);

        // set to null every image with no rate at all
        $elementIdKey = $elementId === false ? 0 : $elementId;
        if (! isset($byItem[$elementIdKey])) {
            $this->repo->clearRatingScores($this->repo->findImageIdsWithStaleRatingScore());
        }

        return $return ?? [
            'score' => null,
            'average' => null,
            'count' => 0,
        ];
    }
}
