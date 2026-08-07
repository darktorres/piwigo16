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
 * Custom DQL function: "DATE_FORMAT_YEAR_MONTH" "(" StringPrimary ")" --
 * a 6-digit `YYYYMM` grouping key, matching MySQL's own
 * `DATE_FORMAT(date, '%Y%m')` shape. Calendar is its only real caller,
 * for its own year+month navigation grouping key.
 *
 * A dedicated single-purpose function rather than a generic 2-arg
 * `DATE_FORMAT(date, pattern)`: MySQL's/SQLite's `%Y%m` token syntax and
 * PostgreSQL's `TO_CHAR` `YYYYMM` token syntax genuinely differ, so a
 * caller-supplied pattern string isn't safely portable across platforms;
 * hardcoding this one real pattern per platform here matches
 * {@see GroupConcatFunction}'s own per-platform-branch precedent. The
 * MySQL/MariaDB branch is verified against real data via this project's
 * own Integration tests. The PostgreSQL/SQLite branches are unverified
 * against a real installation -- built from each platform's own
 * documented syntax, not empirically confirmed. Any other platform
 * throws `NotSupported` rather than guessing.
 */
final class DateFormatYearMonthFunction extends FunctionNode
{
    private Node $date;

    #[Override]
    public function getSql(SqlWalker $sqlWalker): string
    {
        $platform = $sqlWalker->getConnection()
            ->getDatabasePlatform();
        $date = $sqlWalker->walkStringPrimary($this->date);

        if ($platform instanceof AbstractMySQLPlatform) {
            return "DATE_FORMAT({$date}, '%Y%m')";
        }

        if ($platform instanceof SQLitePlatform) {
            return "strftime('%Y%m', {$date})";
        }

        if ($platform instanceof PostgreSQLPlatform) {
            return "TO_CHAR({$date}, 'YYYYMM')";
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
