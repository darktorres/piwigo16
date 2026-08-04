<?php

declare(strict_types=1);

namespace Piwigo\Db;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Events;
use Doctrine\ORM\ORMSetup;
use Piwigo\Db\DqlFunction\DateFormatMonthDayFunction;
use Piwigo\Db\DqlFunction\DateFormatYearMonthFunction;
use Piwigo\Db\DqlFunction\DateSubFunction;
use Piwigo\Db\DqlFunction\DayOfMonthFunction;
use Piwigo\Db\DqlFunction\DayOfWeekFunction;
use Piwigo\Db\DqlFunction\GroupConcatFunction;
use Piwigo\Db\DqlFunction\MonthFunction;
use Piwigo\Db\DqlFunction\RandFunction;
use Piwigo\Db\DqlFunction\RegexpFunction;
use Piwigo\Db\DqlFunction\SubstringIndexFunction;
use Piwigo\Db\DqlFunction\WeekdayFunction;
use Piwigo\Db\DqlFunction\WeekFunction;
use Piwigo\Db\DqlFunction\YearFunction;
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
        $config->addCustomStringFunction('GROUP_CONCAT', GroupConcatFunction::class);
        $config->addCustomStringFunction('SUBSTRING_INDEX', SubstringIndexFunction::class);
        $config->addCustomNumericFunction('RAND', RandFunction::class);
        // Overrides Doctrine ORM's own built-in DATE_SUB -- see
        // DateSubFunction's own docblock for the real Postgres bug this
        // fixes (custom function lookup runs before the built-in one in
        // Doctrine's own parser, confirmed by reading its source).
        $config->addCustomDatetimeFunction('DATE_SUB', DateSubFunction::class);
        $config->addCustomStringFunction('DATE_FORMAT_YEAR_MONTH', DateFormatYearMonthFunction::class);
        $config->addCustomStringFunction('DATE_FORMAT_MONTH_DAY', DateFormatMonthDayFunction::class);
        $config->addCustomNumericFunction('DAYOFMONTH', DayOfMonthFunction::class);
        $config->addCustomNumericFunction('DAYOFWEEK', DayOfWeekFunction::class);
        $config->addCustomNumericFunction('WEEKDAY', WeekdayFunction::class);
        $config->addCustomNumericFunction('WEEK', WeekFunction::class);
        $config->addCustomNumericFunction('YEAR', YearFunction::class);
        $config->addCustomNumericFunction('MONTH', MonthFunction::class);

        $em = new EntityManager($conn ?? DbConnection::build(), $config);
        $em->getEventManager()
            ->addEventListener(Events::loadClassMetadata, new TablePrefixListener(DbCredentials::current()));

        return $em;
    }
}
