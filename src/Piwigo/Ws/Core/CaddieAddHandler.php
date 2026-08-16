<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Ws\Core;

use Doctrine\ORM\EntityManagerInterface;
use Override;
use Piwigo\Caddie\CaddieEntity;
use Piwigo\Users\CurrentUser;
use Piwigo\Ws\WsAction;

/**
 * `pwg.caddie.add` -- admin only. Adds images to the caddie.
 */
final readonly class CaddieAddHandler implements WsAction
{
    public function __construct(
        private CurrentUser $currentUser,
        private EntityManagerInterface $entityManager,
    ) {}

    /**
     * @param array<mixed> $params
     */
    #[Override]
    public function __invoke(array $params): int
    {
        $input = CaddieAddParams::fromArray($params);

        $user_id = $this->currentUser->get()
            ->id->value;

        return $this->entityManager->getRepository(CaddieEntity::class)
            ->addElements($user_id, $input->imageIds);
    }
}
