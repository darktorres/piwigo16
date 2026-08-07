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
 * Custom DQL function: "MONTH" "(" StringPrimary ")" -- matching MySQL's
 * own `MONTH(date)` shape. No built-in DQL `MONTH()` exists in this
 * Doctrine ORM version; see {@see YearFunction} for the same gap.
 *
 * The MySQL/MariaDB branch is verified against real data via this
 * project's Integration tests. The PostgreSQL/SQLite branches are built
 * from each platform's documented syntax but are not verified against a
 * real installation. Any other platform throws `NotSupported` rather
 * than guessing.
 */
final class MonthFunction extends FunctionNode
{
    private Node $date;

    #[Override]
    public function getSql(SqlWalker $sqlWalker): string
    {
        $platform = $sqlWalker->getConnection()
            ->getDatabasePlatform();
        $date = $sqlWalker->walkStringPrimary($this->date);

        if ($platform instanceof AbstractMySQLPlatform) {
            return "MONTH({$date})";
        }

        if ($platform instanceof PostgreSQLPlatform) {
            return "EXTRACT(MONTH FROM {$date})";
        }

        if ($platform instanceof SQLitePlatform) {
            return "CAST(strftime('%m', {$date}) AS INTEGER)";
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
