<?php

declare(strict_types=1);

namespace Piwigo\Rate;

use Piwigo\Auth\AccessControl;
use Piwigo\Auth\CookieService;
use Piwigo\Common\ValueObject\ImageId;
use Piwigo\Common\ValueObject\IpAddress;
use Piwigo\Common\ValueObject\UserId;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\AccessLevel;
use Piwigo\Event\Picture\UpdateRatingScore;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Rate\Projection\RateSummaryForElement;
use Piwigo\Users\CurrentUser;

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
final readonly class RateService
{
    public function __construct(
        private AccessControl $accessControl,
        private RateRepository $repo,
        private CookieService $cookies,
        private EventDispatcher $eventDispatcher,
        private CurrentUser $currentUser,
        private CurrentConfig $currentConfig,
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

        $rateItems = $this->currentConfig->rateItems;

        if (
            $rate === null
            || ! $this->currentConfig->rateEnabled
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

        $userAnonymous = ! $this->accessControl->isAuthorizeStatus(AccessLevel::Classic);

        if ($userAnonymous && ! $this->currentConfig->rateAnonymous) {
            return false;
        }

        $userId = $this->currentUser->get()
            ->id;
        $elementId = ImageId::from($imageId);

        $remoteAddr = IpAddress::fromRemoteAddr()->value ?? '';
        $ipComponents = explode('.', $remoteAddr);
        if (count($ipComponents) > 3) {
            array_pop($ipComponents);
        }

        $anonymousId = implode('.', $ipComponents);

        if ($userAnonymous) {
            $savedAnonymousId = $this->cookies->getAnonymousRaterId() ?? $anonymousId;

            if ($anonymousId !== $savedAnonymousId) { // client has changed IP address, or is trying to fool us
                $existingElementIds = $this->repo->findElementIdsForUserAndAnonymousId($userId, $anonymousId);
                if ($existingElementIds !== []) {
                    $this->repo->deleteByUserAnonymousAndElements($userId, $savedAnonymousId, $existingElementIds);
                }

                $this->repo->reassignAnonymousId($userId, $savedAnonymousId, $anonymousId);
            }

            $this->cookies->setCookieVar('anonymous_rater', $anonymousId);
        }

        $this->repo->deleteExistingRate($elementId, $userId, $userAnonymous ? $anonymousId : null);
        $this->repo->insertRate($elementId, $userId, $anonymousId, $rateInt);

        return $this->updateRatingScore($imageId);
    }

    /**
     * Recomputes images.rating_score for every rated image (a bayesian
     * average -- C = average number of rates per item, m = global average
     * rate), and clears rating_score for images that no longer have any
     * rate at all.
     *
     * Stays a generic bag by design: the update_rating_score plugin hook
     * can widen/replace the whole return value with arbitrary plugin data
     * (same "not narrowable further" rationale as
     * SearchService::getQuickSearchResultsNoCache()'s own qsearch_results
     * hook).
     *
     * @return array<string, mixed> (score, average, count); values are
     *   null/0 if $elementId is false or has no rates of its own
     */
    public function updateRatingScore(int|false $elementId = false): array
    {
        $altResult = $this->eventDispatcher->dispatch(new UpdateRatingScore(false, $elementId))
            ->result;
        if (is_array($altResult)) {
            return $altResult;
        }

        $byItem = $this->repo->findRateSummaries();

        $allRatesCount = 0;
        $allRatesAvg = 0.0;
        $itemRatecountAvg = 0.0;
        foreach ($byItem as $summary) {
            $allRatesCount += $summary->rcount;
            $allRatesAvg += $summary->rsum;
        }

        if ($allRatesCount > 0) {
            $allRatesAvg /= (float) $allRatesCount;
            $itemRatecountAvg = (float) $allRatesCount / (float) count($byItem);
        }

        $return = null;
        $updates = [];
        foreach ($byItem as $id => $summary) {
            $score = ($itemRatecountAvg * $allRatesAvg + $summary->rsum) / ($itemRatecountAvg + (float) $summary->rcount);
            $score = round($score, 2);
            if ($id === $elementId) {
                $return = [
                    'score' => $score,
                    'average' => round($summary->rsum / (float) $summary->rcount, 2),
                    'count' => $summary->rcount,
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

    /**
     * Number of rates for a single element -- Admin\PictureModifyPageRenderer's
     * own "how many times has this photo been rated" display.
     */
    public function countRatesForElement(ImageId $elementId): int
    {
        return $this->repo->countRatesForElement($elementId);
    }

    public function getRateSummaryForElement(ImageId $elementId): RateSummaryForElement
    {
        return $this->repo->findRateSummaryForElement($elementId);
    }

    public function countAll(): int
    {
        return $this->repo->countAllRates();
    }

    public function deleteByOptionalConditions(UserId $userId, ?string $anonymousId, ?ImageId $elementId): int
    {
        return $this->repo->deleteByOptionalConditions($userId, $anonymousId, $elementId);
    }
}
