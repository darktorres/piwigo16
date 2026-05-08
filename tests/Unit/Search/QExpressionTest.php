<?php

declare(strict_types=1);

namespace Piwigo\Tests\Unit\Search;

use PHPUnit\Framework\TestCase;
use Piwigo\Search\QExpression;
use Piwigo\Search\QSearchScope;
use Piwigo\Search\QSingleToken;

final class QExpressionTest extends TestCase
{
    public function testSingleWord(): void
    {
        $expr = new QExpression('foo', []);
        self::assertCount(1, $expr->stokens);
        self::assertSame('foo', $expr->stokens[0]->term);
    }

    public function testTwoWordsImplicitAnd(): void
    {
        $expr = new QExpression('foo bar', []);
        self::assertCount(2, $expr->stokens);
        self::assertSame('foo', $expr->stokens[0]->term);
        self::assertSame('bar', $expr->stokens[1]->term);
    }

    public function testOrOperator(): void
    {
        $expr = new QExpression('foo OR bar', []);
        self::assertCount(2, $expr->stokens);
        $orToken = $expr->stokens[1];
        self::assertTrue(($orToken->modifier & QST_OR) !== 0, 'second token should have OR modifier');
    }

    public function testNotModifier(): void
    {
        $expr = new QExpression('-foo', []);
        self::assertCount(1, $expr->stokens);
        self::assertSame('foo', $expr->stokens[0]->term);
        self::assertTrue(($expr->stoken_modifiers[0] & QST_NOT) !== 0, 'token should have NOT modifier');
    }

    public function testQuotedPhrase(): void
    {
        $expr = new QExpression('"foo bar"', []);
        self::assertCount(1, $expr->stokens);
        self::assertSame('foo bar', $expr->stokens[0]->term);
        self::assertTrue(($expr->stokens[0]->modifier & QST_QUOTED) !== 0);
    }

    public function testWildcardEnd(): void
    {
        $expr = new QExpression('foo*', []);
        self::assertCount(1, $expr->stokens);
        self::assertSame('foo', $expr->stokens[0]->term);
        self::assertTrue(($expr->stokens[0]->modifier & QST_WILDCARD_END) !== 0);
    }

    public function testWildcardBegin(): void
    {
        $expr = new QExpression('*foo', []);
        self::assertCount(1, $expr->stokens);
        self::assertTrue(($expr->stokens[0]->modifier & QST_WILDCARD_BEGIN) !== 0);
    }

    public function testEmptyQueryYieldsNoTokens(): void
    {
        $expr = new QExpression('', []);
        self::assertCount(0, $expr->stokens);
        self::assertCount(0, $expr->tokens);
    }

    public function testScopeApplied(): void
    {
        $scope = new QSearchScope('tag', ['tags']);
        $expr = new QExpression('tag:nature', [$scope]);
        self::assertCount(1, $expr->stokens);
        self::assertSame('nature', $expr->stokens[0]->term);
        self::assertNotNull($expr->stokens[0]->scope);
        self::assertSame('tag', $expr->stokens[0]->scope->id);
    }

    public function testParenthesisGroup(): void
    {
        $expr = new QExpression('(foo OR bar) baz', []);
        self::assertCount(3, $expr->stokens, 'should flatten to 3 single tokens');
    }

    public function testAndKeywordIsIgnored(): void
    {
        $expr = new QExpression('foo AND bar', []);
        self::assertCount(2, $expr->stokens, 'AND keyword itself should not become a token');
    }

    public function testNotKeyword(): void
    {
        $expr = new QExpression('foo NOT bar', []);
        // 'bar' gets the NOT modifier; 'NOT' itself is removed
        $terms = array_map(fn (QSingleToken $t): string => $t->term, $expr->stokens);
        self::assertContains('foo', $terms);
        self::assertContains('bar', $terms);
        self::assertNotContains('NOT', $terms);
    }
}
