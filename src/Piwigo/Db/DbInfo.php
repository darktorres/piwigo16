<?php

declare(strict_types=1);

namespace Piwigo\Db;

use Doctrine\DBAL\Connection;
use Piwigo\Core\Kernel;

final class DbInfo
{
    public static function version(): string
    {
        $v = Kernel::service(Connection::class)->executeQuery('SELECT VERSION()')->fetchOne();
        return is_string($v) ? $v : '';
    }
}
