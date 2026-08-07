<?php

declare(strict_types=1);

namespace Piwigo\Db\DqlFunction;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\Exception\NotSupported;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\ORM\Query\AST\Functions\FunctionNode;
use Doctrine\ORM\Query\AST\Node;
use Doctrine\ORM\Query\Parser;
use Doctrine\ORM\Query\SqlWalker;
use Doctrine\ORM\Query\TokenType;
use Override;

/**
 * Custom DQL function: "YEAR" "(" StringPrimary ")" -- matching MySQL's
 * own `YEAR(date)` shape. `Doctrine\ORM\Query\Parser`'s own
 * `$datetimeFunctions` table (built-in datetime functions) only has
 * `CURRENT_DATE`/`CURRENT_TIME`/`CURRENT_TIMESTAMP`/`DATE_ADD`/`DATE_SUB`
 * -- no `YEAR`/`MONTH`/`DAY` at all in this Doctrine ORM version -- so
 * this is registered as a custom function, the same way as every other
 * date-part function in this directory.
 *
 * MySQL/MariaDB is verified against real data via this project's own
 * Integration tests; PostgreSQL/SQLite are built from each platform's
 * documented syntax, not empirically confirmed. Any other platform throws
 * `NotSupported` rather than guessing.
 */
final class YearFunction extends FunctionNode
{
    private Node $date;

    #[Override]
    public function getSql(SqlWalker $sqlWalker): string
    {
        $platform = $sqlWalker->getConnection()
            ->getDatabasePlatform();
        $date = $sqlWalker->walkStringPrimary($this->date);

        if ($platform instanceof AbstractMySQLPlatform) {
            return "YEAR({$date})";
        }

        if ($platform instanceof PostgreSQLPlatform) {
            return "EXTRACT(YEAR FROM {$date})";
        }

        if ($platform instanceof SQLitePlatform) {
            return "CAST(strftime('%Y', {$date}) AS INTEGER)";
        }

        throw NotSupported::new(self::class . '::getSql() for ' . $platform::class);
    }

    #[Override]
    public function parse(Parser $parser): void
    {
        $parser->match(TokenType::T_IDENTIFIER);
        $parser->match(TokenType::T_OPEN_PARENTHESIS);

        $this->date = $parser->StringPrimary();

        $parser->match(TokenType::T_CLOSE_PARENTHESIS);
    }
}
