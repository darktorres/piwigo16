<?php

declare(strict_types=1);

namespace Piwigo\Db;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Events;
use Doctrine\ORM\ORMSetup;

/**
 * Factory for a Doctrine ORM EntityManager -- the ORM counterpart to
 * DbConnection::build(). Extracted from config/container.php's own
 * EntityManagerInterface factory (which now delegates here) so that
 * callers structurally unable to receive it via constructor injection
 * (a static L1Infrastructure method, a self-managed singleton's fallback
 * branch, a test helper deliberately bypassing full app bootstrap) have a
 * direct path to a working EntityManager, same as DbConnection::build()
 * already gives every layer a direct path to a Connection. Lazy, like
 * DbConnection::build() itself -- constructing an EntityManager/resolving
 * an EntityRepository doesn't touch the DB until a real query runs.
 */
final class EntityManagerFactory
{
    public static function build(?Connection $conn = null): EntityManagerInterface
    {
        $config = ORMSetup::createAttributeMetadataConfig(
            paths: [dirname(__DIR__)],
            isDevMode: true,
        );
        $config->enableNativeLazyObjects(true);

        $em = new EntityManager($conn ?? DbConnection::build(), $config);
        $em->getEventManager()
            ->addEventListener(Events::loadClassMetadata, new TablePrefixListener());

        return $em;
    }
}
