<?php

declare(strict_types=1);

namespace Piwigo\Rate;

use Piwigo\Auth\CookieService;
use Piwigo\Config\Config;
use Piwigo\Core\AccessLevel;
use Piwigo\Db\Dml;
use Piwigo\Db\Tables;
use Piwigo\Event\Picture\UpdateRatingScore;
use Piwigo\Image\ImageRepository;
use Piwigo\Users\CurrentUser;
use Piwigo\Users\PermissionService;
use Psr\EventDispatcher\EventDispatcherInterface;

final readonly class RateService
{
    public function __construct(
        private RateRepository $rateRepo,
        private ImageRepository $imageRepo,
        private CookieService $cookies,
        private PermissionService $permissionService,
        private EventDispatcherInterface $dispatcher,
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

        $userAnonymous = $this->permissionService->isAutorizeStatus(AccessLevel::Classic) ? false : true;

        if ($userAnonymous and !Config::rateAnonymous()) {
            return false;
        }

        /** @var string $remoteAddrRaw */
        $remoteAddrRaw = $_SERVER['REMOTE_ADDR'] ?? '';
        $ipComponents = explode('.', $remoteAddrRaw);
        if (count($ipComponents) > 3) {
            array_pop($ipComponents);
        }
        $anonymousId = implode('.', $ipComponents);

        if ($userAnonymous) {
            $saveAnonId = $this->cookies->getCookieVar('anonymous_rater', $anonymousId);

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
    public function updateRatingScore(?int $elementId = null): array
    {
        $this->dispatcher->dispatch(new UpdateRatingScore(false, $elementId ?? 0));

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
            $allRatesAvg      /= (float) $allRatesCount;
            $itemRatecountAvg  = (float) $allRatesCount / (float) count($byItem);
        }

        /** @var array<mixed>|null $return */
        $return  = null;
        $updates = [];
        foreach ($byItem as $id => $rateSummary) {
            $rsum   = is_numeric($rateSummary['rsum']) ? (float) $rateSummary['rsum'] : 0.0;
            $rcount = is_numeric($rateSummary['rcount']) ? (int)   $rateSummary['rcount'] : 0;
            $score  = ($itemRatecountAvg * $allRatesAvg + $rsum) / ($itemRatecountAvg + (float) $rcount);
            $score  = round($score, 2);
            if ($id == $elementId) {
                $return = [
                    'score'   => $score,
                    'average' => $rcount > 0 ? round($rsum / (float) $rcount, 2) : 0.0,
                    'count'   => $rcount,
                ];
            }
            $updates[] = ['id' => $id, 'rating_score' => $score];
        }
        Dml::massUpdates(
            Tables::images(),
            [
                'primary' => ['id'],
                'update'  => ['rating_score'],
            ],
            $updates
        );

        if ($elementId === null || !isset($byItem[$elementId])) {
            $toUpdate = $this->rateRepo->findImageIdsWithNoRates();
            if (!empty($toUpdate)) {
                $this->imageRepo->clearRatingScoreByIds($toUpdate);
            }
        }

        return $return ?? ['score' => null, 'average' => null, 'count' => 0];
    }
}
