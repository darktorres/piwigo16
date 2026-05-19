<?php

declare(strict_types=1);

namespace Piwigo\Ws\Action\Pwg\Rates;

use Piwigo\Rate\RateRepository;
use Piwigo\Rate\RateService;
use Piwigo\Ws\PwgServer;
use Piwigo\Ws\WsAction;

/**
 * `pwg.rates.delete` — drop all rate rows matching the
 * (user_id, optional anonymous_id, optional image_id) tuple. When
 * anything is deleted, refresh the average-rating cache on images.
 */
final readonly class DeleteHandler implements WsAction
{
    public function __construct(
        private RateRepository $rateRepository,
        private RateService $rateService,
    ) {
    }

    /** @param array<mixed> $params */
    public function __invoke(array $params, PwgServer $server): int
    {
        $userId  = is_numeric($params['user_id']) ? (int) $params['user_id'] : 0;
        $anonId  = !empty($params['anonymous_id']) && is_string($params['anonymous_id']) ? $params['anonymous_id'] : null;
        $imageId = !empty($params['image_id']) && is_numeric($params['image_id']) ? (int) $params['image_id'] : null;
        $changes = $this->rateRepository->deleteByUserOptionalAnonAndElement($userId, $anonId, $imageId);
        if ($changes > 0) {
            $this->rateService->updateRatingScore();
        }
        return $changes;
    }
}
