<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

namespace Piwigo\Ws\Rates;

use Doctrine\ORM\EntityManagerInterface;
use Piwigo\Common\ValueObject\ImageId;
use Piwigo\Common\ValueObject\UserId;
use Piwigo\Rate\RateService;
use Piwigo\Ws\Server;
use Piwigo\Ws\WsAction;

/**
 * `pwg.rates.delete` -- admin only. Deletes rates of a user.
 */
final readonly class DeleteHandler implements WsAction
{
    public function __construct(
        private RateService $rateService,
        private EntityManagerInterface $entityManager,
    ) {}

    /**
     * @param array<mixed> $params
     */
    public function __invoke(array $params, Server $server): int
    {
        $input = DeleteParams::fromArray($params);

        // Real bug fix, not just a MysqliDb retarget: the original (both here
        // and in the pre-rewrite legacy include/ws_functions/pwg.php) built
        // its own raw query and then NEVER executed it -- MysqliDb::changes()
        // just reads $mysqli->affected_rows from whatever OTHER query last
        // ran on the connection, completely disconnected from this DELETE.
        // executeStatement() both runs the query for real and returns its
        // actual affected-row count.
        $changes = $this->rateService->deleteByOptionalConditions(
            UserId::from($input->userId),
            $input->anonymousId,
            $input->imageId === null ? null : ImageId::from($input->imageId),
        );
        $this->entityManager->clear();
        if ($changes > 0) {
            $this->rateService->updateRatingScore();
        }
        return $changes;
    }
}
