<?php

declare(strict_types=1);

namespace Piwigo\Db\DqlFunction;

use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\ORM\Query\AST\Functions\FunctionNode;
use Doctrine\ORM\Query\AST\Node;
use Doctrine\ORM\Query\Parser;
use Doctrine\ORM\Query\SqlWalker;
use Doctrine\ORM\Query\TokenType;
use Override;

/**
 * Custom DQL function: "REGEXP" "(" StringPrimary "," StringPrimary ")",
 * compiling to an infix `left <op> right` regex-match predicate.
 *
 * Further SQL-modernization audit, Item 14 Sub-phase B5 Tier 1: genuinely
 * portable, unlike a hardcoded `REGEXP` splice -- resolves to `RLIKE` on
 * MySQL/MariaDB ({@see \Doctrine\DBAL\Platforms\AbstractMySQLPlatform}).
 *
 * pgsql support pass: the previous version delegated to
 * `AbstractPlatform::getRegexpExpression()`, which resolves to `SIMILAR
 * TO` on PostgreSQL -- a genuinely different pattern-matching dialect
 * than POSIX `REGEXP`, not just a syntax rename (`SIMILAR TO` implicitly
 * anchors the *whole* string, so a substring-search POSIX pattern like
 * `(^|,)123(,|$)` never matches -- confirmed live: `'1,2' SIMILAR TO
 * '(^|,)2(,|$)'` is `false`). The class's own prior docblock flagged this
 * exact gap as unverified pending a real Postgres environment ("no
 * install/schema/pgsql.sql exists yet"); now that one does, the fix is
 * a real platform branch onto Postgres's own POSIX-regex operator (`~`,
 * case-sensitive match -- confirmed live the same `'1,2' ~ '(^|,)2(,|$)'`
 * pattern is `true`), not the framework's own generic
 * `getRegexpExpression()`.
 */
final class RegexpFunction extends FunctionNode
{
    private Node $column;

    private Node $pattern;

    #[Override]
    public function getSql(SqlWalker $sqlWalker): string
    {
        $platform = $sqlWalker->getConnection()
            ->getDatabasePlatform();
        $operator = $platform instanceof PostgreSQLPlatform ? '~' : $platform->getRegexpExpression();

        return $sqlWalker->walkStringPrimary($this->column) . ' ' . $operator . ' ' . $sqlWalker->walkStringPrimary($this->pattern);
    }

    #[Override]
    public function parse(Parser $parser): void
    {
        $parser->match(TokenType::T_IDENTIFIER);
        $parser->match(TokenType::T_OPEN_PARENTHESIS);

        $this->column = $parser->StringPrimary();
        $parser->match(TokenType::T_COMMA);
        $this->pattern = $parser->StringPrimary();

        $parser->match(TokenType::T_CLOSE_PARENTHESIS);
    }
}
