<?php

declare(strict_types=1);

namespace Piwigo\Db;

final class DbInfo
{
    public static function version(): string
    {
        $v = get_dbal_connection()->executeQuery('SELECT VERSION()')->fetchOne();
        return is_string($v) ? $v : '';
    }
}
