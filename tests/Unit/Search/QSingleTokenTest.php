<?php

declare(strict_types=1);

namespace Piwigo\Tests\Unit\Search;

use PHPUnit\Framework\TestCase;
use Piwigo\Search\QSearchScope;
use Piwigo\Search\QSingleToken;

use const Piwigo\Search\QST_NOT;
use const Piwigo\Search\QST_OR;
use const Piwigo\Search\QST_QUOTED;
use const Piwigo\Search\QST_WILDCARD_BEGIN;
use const Piwigo\Search\QST_WILDCARD_END;

final class QSingleTokenTest extends TestCase
{
    public function testToStringPlainTerm(): void
    {
        $t = new QSingleToken('hello', 0, null);
        self::assertSame('hello', (string) $t);
    }

    public function testToStringQuoted(): void
    {
        $t = new QSingleToken('foo bar', QST_QUOTED, null);
        self::assertSame('"foo bar"', (string) $t);
    }

    public function testToStringWildcardEnd(): void
    {
        $t = new QSingleToken('foo', QST_WILDCARD_END, null);
        self::assertSame('foo*', (string) $t);
    }

    public function testToStringWildcardBegin(): void
    {
        $t = new QSingleToken('foo', QST_WILDCARD_BEGIN, null);
        self::assertSame('*foo', (string) $t);
    }

    public function testToStringWithScope(): void
    {
        $scope = new QSearchScope('tag', ['tags']);
        $t = new QSingleToken('nature', 0, $scope);
        self::assertSame('tag:nature', (string) $t);
    }

    public function testToStringNotModifier(): void
    {
        // NOT is rendered by the parent QMultiToken, not by QSingleToken::__toString.
        $t = new QSingleToken('foo', QST_NOT, null);
        self::assertSame('foo', (string) $t);
    }

    public function testToStringQuotedWithWildcardEnd(): void
    {
        $t = new QSingleToken('foo bar', QST_QUOTED | QST_WILDCARD_END, null);
        self::assertSame('"foo bar"*', (string) $t);
    }

    public function testIsSingleIsTrue(): void
    {
        $t = new QSingleToken('x', 0, null);
        self::assertTrue($t->is_single);
    }

    public function testDefaultProperties(): void
    {
        $t = new QSingleToken('word', QST_OR, null);
        self::assertSame('word', $t->term);
        self::assertSame(QST_OR, $t->modifier);
        self::assertNull($t->scope);
        self::assertSame([], $t->variants);
        self::assertNull($t->scope_data);
        self::assertSame(0, $t->idx);
    }
}
