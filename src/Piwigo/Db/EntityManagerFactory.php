<?php

declare(strict_types=1);

namespace Piwigo\Db;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Events;
use Doctrine\ORM\ORMSetup;
use Piwigo\Db\DqlFunction\RegexpFunction;
use Piwigo\Db\Type\CategoryIdType;
use Piwigo\Db\Type\CommentIdType;
use Piwigo\Db\Type\GroupIdType;
use Piwigo\Db\Type\IpAddressType;
use Piwigo\Db\Type\TagIdType;
use Piwigo\Db\Type\UserIdType;

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
        // Guarded by hasType() since this factory is deliberately not
        // memoized (called fresh per-request/per-test) and addType()
        // throws on double-registration.
        foreach ([
            'group_id' => GroupIdType::class,
            'user_id' => UserIdType::class,
            'category_id' => CategoryIdType::class,
            'ip_address' => IpAddressType::class,
            'comment_id' => CommentIdType::class,
            'tag_id' => TagIdType::class,
        ] as $name => $class) {
            if (! Type::hasType($name)) {
                Type::addType($name, $class);
            }
        }

        $config = ORMSetup::createAttributeMetadataConfig(
            paths: [dirname(__DIR__)],
            isDevMode: true,
        );
        $config->enableNativeLazyObjects(true);
        $config->addCustomStringFunction('REGEXP', RegexpFunction::class);

        $em = new EntityManager($conn ?? DbConnection::build(), $config);
        $em->getEventManager()
            ->addEventListener(Events::loadClassMetadata, new TablePrefixListener(DbCredentials::current()));

        return $em;
    }
}
