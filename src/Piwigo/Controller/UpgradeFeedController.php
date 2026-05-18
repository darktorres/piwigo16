<?php

declare(strict_types=1);

namespace Piwigo\Controller;

use Doctrine\DBAL\Connection;
use Piwigo\Admin\UpgradeService;
use Piwigo\Config\Config;
use Piwigo\Core\Paths;
use Piwigo\Db\Tables;
use Piwigo\Http\ResponseFactory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Applies pending DB upgrade scripts when the upgrade-feed mechanism is active.
 * Corresponds to the former upgrade_feed.php entry-point.
 *
 * Accessed via index.php?/upgrade_feed — bypasses common.inc.php (DB schema
 * may be mid-migration).
 */
final readonly class UpgradeFeedController implements ControllerInterface
{
    public function __construct(
        private Connection $conn,
        private Paths $paths,
    ) {
    }

    #[\Override]
    public function __invoke(ServerRequestInterface $request, array $args = []): ResponseInterface
    {
        if (!Config::checkUpgradeFeed()) {
            die('upgrade feed is not active');
        }

        $upgradesDir = $this->paths->root . 'install/db/';

        $applied  = array_map(static fn (mixed $v): string => is_scalar($v) ? (string) $v : '0', array_column($this->conn->executeQuery('SELECT id FROM ' . Tables::upgrade())->fetchAllAssociative(), 'id'));
        $existing = UpgradeService::getAvailableUpgradeIds();
        $to_apply = array_diff($existing, $applied);

        echo '<pre>';
        echo count($to_apply) . ' upgrades to apply';

        foreach ($to_apply as $upgrade_id) {
            $upgrade_description = null;
            echo "\n\n";
            echo '=== upgrade ' . $upgrade_id . "\n";

            // Upgrade script path computed from runtime $upgrade_id —
            // Psalm cannot follow the include statically.
            /** @psalm-suppress UnresolvableInclude */
            require($upgradesDir . $upgrade_id . '-database.php');
            /** @var string|null $upgrade_description -- may be set by the required migration file */

            $this->conn->insert(Tables::upgrade(), [
                'id'          => $upgrade_id,
                'applied'     => new \DateTimeImmutable()->format('Y-m-d H:i:s'),
                'description' => is_string($upgrade_description) ? $upgrade_description : '',
            ]);
        }

        echo '</pre>';

        return ResponseFactory::create(200);
    }
}
