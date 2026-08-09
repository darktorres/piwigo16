<?php

declare(strict_types=1);

namespace Piwigo\Db\DqlFunction;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\Exception\NotSupported;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\ORM\Query\AST\Functions\FunctionNode;
use Doctrine\ORM\Query\AST\Node;
use Doctrine\ORM\Query\Parser;
use Doctrine\ORM\Query\SqlWalker;
use Doctrine\ORM\Query\TokenType;
use Override;

/**
 * Custom DQL function: "SUBSTRING_INDEX" "(" StringPrimary "," StringPrimary ","
 * SimpleArithmeticExpression ")", matching MySQL's own
 * `SUBSTRING_INDEX(str, delim, count)`. This codebase's only real caller
 * (SearchFilterRenderer's own filetypes filter block) always calls it as
 * `(path, '.', -1)` -- everything after the final delimiter, i.e. a file
 * extension.
 *
 * MySQL/MariaDB branch verified against real data via this project's own
 * Integration tests. PostgreSQL's `split_part(str, delim, n)` only
 * supports a NEGATIVE $n (counting from the end, this function's own
 * only real call shape) since PostgreSQL 14, so a non-negative $count
 * throws NotSupported on PostgreSQL rather than guessing at a
 * version-dependent behavior. SQLite has no equivalent primitive at
 * all -- always NotSupported.
 */
final class SubstringIndexFunction extends FunctionNode
{
    private Node $string;

    private Node $delimiter;

    private Node|string $count;

    #[Override]
    public function getSql(SqlWalker $sqlWalker): string
    {
        $platform = $sqlWalker->getConnection()
            ->getDatabasePlatform();
        $string = $sqlWalker->walkStringPrimary($this->string);
        $delimiter = $sqlWalker->walkStringPrimary($this->delimiter);
        $count = $sqlWalker->walkSimpleArithmeticExpression($this->count);

        if ($platform instanceof AbstractMySQLPlatform) {
            return "SUBSTRING_INDEX({$string}, {$delimiter}, {$count})";
        }

        if ($platform instanceof PostgreSQLPlatform) {
            if (! str_starts_with($count, '-')) {
                throw NotSupported::new(self::class . '::getSql() with a non-negative count for ' . $platform::class);
            }

            return "split_part({$string}, {$delimiter}, {$count})";
        }

        throw NotSupported::new(self::class . '::getSql() for ' . $platform::class);
    }

    #[Override]
    public function parse(Parser $parser): void
    {
        $parser->match(TokenType::T_IDENTIFIER);
        $parser->match(TokenType::T_OPEN_PARENTHESIS);

        $this->string = $parser->StringPrimary();
        $parser->match(TokenType::T_COMMA);
        $this->delimiter = $parser->StringPrimary();
        $parser->match(TokenType::T_COMMA);
        $this->count = $parser->SimpleArithmeticExpression();

        $parser->match(TokenType::T_CLOSE_PARENTHESIS);
    }
}
