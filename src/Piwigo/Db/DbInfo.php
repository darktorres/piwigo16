<?php

declare(strict_types=1);

namespace Piwigo\Db;

final class DbInfo
{
    public static function version(): string
    {
        $v = DbConnection::get()->executeQuery('SELECT VERSION()')->fetchOne();
        return is_string($v) ? $v : '';
    }
}
