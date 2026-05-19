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
    #[\Override]
    public function __invoke(array $params, PwgServer $server): int
    {
        $input   = DeleteParams::fromArray($params);
        $changes = $this->rateRepository->deleteByUserOptionalAnonAndElement($input->userId, $input->anonymousId, $input->imageId);
        if ($changes > 0) {
            $this->rateService->updateRatingScore();
        }
        return $changes;
    }
}
