<?php

declare(strict_types=1);

namespace Piwigo\Ws\Action\Pwg;

use Piwigo\Caddie\CaddieRepository;
use Piwigo\Users\CurrentUser;
use Piwigo\Ws\PwgServer;
use Piwigo\Ws\WsAction;

/**
 * `pwg.caddie.add` — append image_ids to the current user's caddie,
 * skipping any already present. Returns the number actually inserted.
 */
final readonly class CaddieAddHandler implements WsAction
{
    public function __construct(
        private CaddieRepository $caddieRepository,
    ) {
    }

    /** @param array<mixed> $params */
    #[\Override]
    public function __invoke(array $params, PwgServer $server): int
    {
        $input  = CaddieAddParams::fromArray($params);
        $userId = CurrentUser::get()->id;
        $newIds = $this->caddieRepository->findImagesNotInCaddie($input->imageIds, $userId);
        $this->caddieRepository->insertImageIdsBatch($userId, $newIds);
        return count($newIds);
    }
}
