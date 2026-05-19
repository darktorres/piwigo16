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
        $userId   = CurrentUser::get()->id;
        $rawIds   = is_array($params['image_id']) ? $params['image_id'] : [];
        $imageIds = array_values(array_map(static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0, $rawIds));
        $newIds   = $this->caddieRepository->findImagesNotInCaddie($imageIds, $userId);
        $this->caddieRepository->insertImageIdsBatch($userId, $newIds);
        return count($newIds);
    }
}
