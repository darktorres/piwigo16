<?php

declare(strict_types=1);

namespace Piwigo\Rate;

use Piwigo\Auth\CookieService;
use Piwigo\Config\Config;
use Piwigo\Image\ImageRepository;
use Piwigo\Users\CurrentUser;

final readonly class RateService
{
    public function __construct(
        private RateRepository $rateRepo,
        private ImageRepository $imageRepo,
        private CookieService $cookies,
    ) {
    }

    /** @return array<mixed>|false */
    public function ratePicture(int $imageId, float|int|null $rate): array|false
    {
        $userId = CurrentUser::get()->id;

        if (!isset($rate)
            or !Config::rateEnabled()
            or !preg_match('/^[0-9]+$/', (string) $rate)
            or !in_array($rate, Config::rateItems())) {
            return false;
        }

        $userAnonymous = is_autorize_status(ACCESS_CLASSIC) ? false : true;

        if ($userAnonymous and !Config::rateAnonymous()) {
            return false;
        }

        $ipComponents = explode('.', is_scalar($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '');
        if (count($ipComponents) > 3) {
            array_pop($ipComponents);
        }
        $anonymousId = implode('.', $ipComponents);

        if ($userAnonymous) {
            $saveAnonIdRaw = $this->cookies->getCookieVar('anonymous_rater', $anonymousId);
            $saveAnonId    = is_scalar($saveAnonIdRaw) ? (string) $saveAnonIdRaw : $anonymousId;

            if ($anonymousId != $saveAnonId) { // client changed IP or is trying to fool us
                $alreadyThere = $this->rateRepo->findElementIdsByUserAndAnonId($userId, $anonymousId);

                if (count($alreadyThere) > 0) {
                    $this->rateRepo->deleteByUserAnonElements($userId, $saveAnonId, $alreadyThere);
                }

                $this->rateRepo->updateAnonId($userId, $saveAnonId, $anonymousId);
            }

            $this->cookies->setCookieVar('anonymous_rater', $anonymousId);
        }

        $this->rateRepo->deleteByElementAndUser($imageId, $userId, $userAnonymous ? $anonymousId : null);
        $this->rateRepo->insert($userId, $anonymousId, $imageId, (float) $rate);

        return $this->updateRatingScore($imageId);
    }

    /**
     * Update images.rating_score using a Bayesian average.
     * C = average number of rates per item, m = global average rate.
     *
     * @return array<mixed>
     */
    public function updateRatingScore(int|false $elementId = false): array
    {
        $_ = trigger_change('update_rating_score', false, $elementId);

        $allRatesCount    = 0;
        $allRatesAvg      = 0.0;
        $itemRatecountAvg = 0.0;
        $byItem           = [];

        foreach ($this->rateRepo->getSumsByElement() as $row) {
            $allRatesCount += is_numeric($row['rcount']) ? (int) $row['rcount'] : 0;
            $allRatesAvg   += is_numeric($row['rsum']) ? (float) $row['rsum'] : 0.0;
            $elementIdKey   = is_numeric($row['element_id']) ? (int) $row['element_id'] : (is_scalar($row['element_id']) ? (string) $row['element_id'] : 0);
            $byItem[$elementIdKey] = $row;
        }

        if ($allRatesCount > 0) {
            $allRatesAvg      /= $allRatesCount;
            $itemRatecountAvg  = $allRatesCount / count($byItem);
        }

        /** @var array<mixed>|null $return */
        $return  = null;
        $updates = [];
        foreach ($byItem as $id => $rateSummary) {
            $rsum   = is_numeric($rateSummary['rsum']) ? (float) $rateSummary['rsum'] : 0.0;
            $rcount = is_numeric($rateSummary['rcount']) ? (int)   $rateSummary['rcount'] : 0;
            $score  = ($itemRatecountAvg * $allRatesAvg + $rsum) / ($itemRatecountAvg + $rcount);
            $score  = round($score, 2);
            if ($id == $elementId) {
                $return = [
                    'score'   => $score,
                    'average' => $rcount > 0 ? round($rsum / $rcount, 2) : 0.0,
                    'count'   => $rcount,
                ];
            }
            $updates[] = ['id' => $id, 'rating_score' => $score];
        }
        mass_updates(
            IMAGES_TABLE,
            [
                'primary' => ['id'],
                'update'  => ['rating_score'],
            ],
            $updates
        );

        if (!isset($byItem[$elementId])) {
            $toUpdate = $this->rateRepo->findImageIdsWithNoRates();
            if (!empty($toUpdate)) {
                $this->imageRepo->clearRatingScoreByIds($toUpdate);
            }
        }

        return $return ?? ['score' => null, 'average' => null, 'count' => 0];
    }
}
