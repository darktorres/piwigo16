<?php

declare(strict_types=1);

namespace Piwigo\Controller;

use Piwigo\Admin\UpgradeService;
use Piwigo\Config\Config;
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
final class UpgradeFeedController implements ControllerInterface
{
    public function __invoke(ServerRequestInterface $request, array $args = []): ResponseInterface
    {
        if (!Config::checkUpgradeFeed()) {
            die('upgrade feed is not active');
        }

        UpgradeService::prepareConfUpgrade();

        define('PREFIX_TABLE', Config::dbPrefix());
        define('UPGRADES_PATH', PHPWG_ROOT_PATH . 'install/db');

        $applied  = array_map(static fn (mixed $v): string => is_scalar($v) ? (string) $v : '', array_column(get_dbal_connection()->executeQuery('SELECT id FROM ' . PREFIX_TABLE . 'upgrade')->fetchAllAssociative(), 'id'));
        $existing = UpgradeService::getAvailableUpgradeIds();
        $to_apply = array_diff($existing, $applied);

        echo '<pre>';
        echo count($to_apply) . ' upgrades to apply';

        foreach ($to_apply as $upgrade_id) {
            unset($upgrade_description);
            echo "\n\n";
            echo '=== upgrade ' . $upgrade_id . "\n";

            require(UPGRADES_PATH . '/' . $upgrade_id . '-database.php');

            single_insert(PREFIX_TABLE . 'upgrade', [
                'id'          => $upgrade_id,
                'applied'     => new \DateTimeImmutable()->format('Y-m-d H:i:s'),
                'description' => is_string($upgrade_description ?? null) ? $upgrade_description : '',
            ]);
        }

        echo '</pre>';

        return ResponseFactory::create(200);
    }
}
