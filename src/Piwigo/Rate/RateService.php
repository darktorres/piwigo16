<?php

declare(strict_types=1);

namespace Piwigo\Rate;

use Doctrine\ORM\EntityManagerInterface;
use Piwigo\Auth\AccessControl;
use Piwigo\Auth\CookieService;
use Piwigo\Common\ValueObject\ImageId;
use Piwigo\Common\ValueObject\IpAddress;
use Piwigo\Common\ValueObject\UserId;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\AccessLevel;
use Piwigo\Rate\Projection\RateSummaryForElement;
use Piwigo\Rate\Projection\RatingScoreSummary;
use Piwigo\Rate\Projection\RatingScoreUpdate;
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
        private CurrentUser $currentUser,
        private CurrentConfig $currentConfig,
    ) {}

    /**
     * Rates a picture by the current user. Returns updateRatingScore()'s
     * result, or false if the rate is invalid or forbidden.
     *
     * The anonymous-id migration (when the caller's IP changed) and the
     * delete-then-insert replace-rate pair below are real multi-write
     * sequences -- wrapped in one transaction via $entityManager so a
     * failure partway through can't silently lose the caller's rate (a
     * commit between deleteExistingRate() and insertRate() would) or
     * leave their rate history split across two anonymous ids.
     * updateRatingScore() nests its own transactional() call inside this
     * one via the same $entityManager.
     *
     * @param int|string|null $rate raw $_POST value (string) from
     *   picture.php, an int from `Controller\Api\Images\
     *   ImageRateController`'s JSON body, or null when absent
     */
    public function rate(int $imageId, int|string|null $rate, EntityManagerInterface $entityManager): RatingScoreSummary|false
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
            $this->cookies->setCookieVar('anonymous_rater', $anonymousId);
        } else {
            $savedAnonymousId = null;
        }

        $entityManager->getConnection()
            ->transactional(function () use ($userId, $anonymousId, $savedAnonymousId, $userAnonymous, $elementId, $rateInt): void {
                if ($userAnonymous && $anonymousId !== $savedAnonymousId) { // client has changed IP address, or is trying to fool us
                    $existingElementIds = $this->repo->findElementIdsForUserAndAnonymousId($userId, $anonymousId);
                    if ($existingElementIds !== []) {
                        $this->repo->deleteByUserAnonymousAndElements($userId, $savedAnonymousId, $existingElementIds);
                    }

                    $this->repo->reassignAnonymousId($userId, $savedAnonymousId, $anonymousId);
                }

                $this->repo->deleteExistingRate($elementId, $userId, $userAnonymous ? $anonymousId : null);
                $this->repo->insertRate($elementId, $userId, $anonymousId, $rateInt);
            });

        return $this->updateRatingScore($entityManager, $imageId);
    }

    /**
     * Recomputes images.rating_score for every rated image (a bayesian
     * average -- C = average number of rates per item, m = global average
     * rate), and clears rating_score for images that no longer have any
     * rate at all.
     *
     * updateRatingScores() and the conditional clearRatingScores() below
     * are two writes for one logical "recompute every score" operation --
     * wrapped in one transaction via $entityManager so a failure between
     * them can't leave some images with a freshly-recomputed score and
     * others still carrying a stale one from before this run.
     *
     * @return RatingScoreSummary values are null/0 if $elementId is false or
     *   has no rates of its own
     */
    public function updateRatingScore(EntityManagerInterface $entityManager, int|false $elementId = false): RatingScoreSummary
    {
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
                $return = new RatingScoreSummary(
                    score: $score,
                    average: round($summary->rsum / (float) $summary->rcount, 2),
                    count: $summary->rcount,
                );
            }

            $updates[] = new RatingScoreUpdate(id: $id, ratingScore: $score);
        }

        // set to null every image with no rate at all
        $elementIdKey = $elementId === false ? 0 : $elementId;

        $entityManager->getConnection()
            ->transactional(function () use ($updates, $byItem, $elementIdKey): void {
                $this->repo->updateRatingScores($updates);

                if (! isset($byItem[$elementIdKey])) {
                    $this->repo->clearRatingScores($this->repo->findImageIdsWithStaleRatingScore());
                }
            });

        return $return ?? new RatingScoreSummary(score: null, average: null, count: 0);
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
