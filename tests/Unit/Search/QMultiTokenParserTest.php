<?php

declare(strict_types=1);

namespace Piwigo\Tests\Unit\Search;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Piwigo\Search\QExpression;
use Piwigo\Search\QMultiToken;
use Piwigo\Search\QSearchScope;
use Piwigo\Search\QSingleToken;

use const Piwigo\Search\QST_BREAK;
use const Piwigo\Search\QST_NOT;
use const Piwigo\Search\QST_OR;
use const Piwigo\Search\QST_QUOTED;
use const Piwigo\Search\QST_WILDCARD_BEGIN;
use const Piwigo\Search\QST_WILDCARD_END;

/**
 * Golden-master test for the hand-written QMultiToken lexer.
 *
 * Each sample query is parsed under the current implementation and its
 * resulting tree is rendered to a deterministic dump (tree shape, stoken
 * count + modifiers, recursive descent into sub-expressions). The
 * dump is asserted against a frozen snapshot so any change to the lexer
 * — including the planned state-pattern refactor (N4b) — must produce
 * an identical parse output.
 *
 * If the lexer is intentionally changed, regenerate snapshots by
 * temporarily replacing the assertion with `var_export($actual, true)`
 * and inspect the diff carefully.
 */
final class QMultiTokenParserTest extends TestCase
{
    /** @return array<string, array{0: string}> */
    public static function querySamples(): array
    {
        return [
            'single word'              => ['foo'],
            'two words implicit and'   => ['foo bar'],
            'three words'              => ['alpha beta gamma'],
            'or operator'              => ['foo OR bar'],
            'and operator'             => ['foo AND bar'],
            'mixed and/or'             => ['a OR b c d'],
            'not prefix'               => ['-foo'],
            'not after word'           => ['foo -bar'],
            'quoted phrase'            => ['"foo bar"'],
            'quoted with wildcard end' => ['"foo bar"*'],
            'wildcard end'             => ['foo*'],
            'wildcard begin'           => ['*foo'],
            'wildcard both'            => ['*foo*'],
            'decimal in number'        => ['F2.8'],
            'paren group'              => ['(a OR b) c'],
            'nested paren'             => ['(a (b OR c))'],
            'whitespace punctuation'   => ['foo, bar; baz!'],
            'tag scope single'         => ['tag:John'],
            'tag scope phrase'         => ['tag:(John Bill)'],
            'tag scope with or'        => ['tag:John OR Bill'],
        ];
    }

    #[DataProvider('querySamples')]
    public function testParserSnapshot(string $query): void
    {
        $expr = new QExpression($query, $this->scopes());
        $actual = $this->dumpTree($expr);
        self::assertSame(self::SNAPSHOTS[$query], $actual, 'parser output drift for query: ' . $query);
    }

    /** @return list<QSearchScope> */
    private function scopes(): array
    {
        return [new QSearchScope('tag', ['tags'], true, true)];
    }

    private function dumpTree(QMultiToken $node, int $depth = 0): string
    {
        $indent = str_repeat('  ', $depth);
        $lines = [$indent . 'Multi(modifier=' . $node->modifier . ', tokens=' . count($node->tokens) . ')'];
        foreach ($node->tokens as $token) {
            if ($token instanceof QSingleToken) {
                $lines[] = $indent . '  Single(term=' . var_export($token->term, true)
                    . ', mod=' . $token->modifier
                    . self::decodeMods($token->modifier)
                    . ', scope=' . ($token->scope !== null ? $token->scope->id : 'null') . ')';
            } else {
                $lines[] = $this->dumpTree($token, $depth + 1);
            }
        }
        return implode("\n", $lines);
    }

    private static function decodeMods(int $modifier): string
    {
        $flags = [];
        if (($modifier & QST_OR) !== 0) {
            $flags[] = 'OR';
        }
        if (($modifier & QST_NOT) !== 0) {
            $flags[] = 'NOT';
        }
        if (($modifier & QST_QUOTED) !== 0) {
            $flags[] = 'QUOTED';
        }
        if (($modifier & QST_WILDCARD_BEGIN) !== 0) {
            $flags[] = 'WB';
        }
        if (($modifier & QST_WILDCARD_END) !== 0) {
            $flags[] = 'WE';
        }
        if (($modifier & QST_BREAK) !== 0) {
            $flags[] = 'BREAK';
        }
        return $flags === [] ? '' : ' [' . implode('|', $flags) . ']';
    }

    /** @var array<string, string> */
    private const array SNAPSHOTS = [
        'foo' => <<<'DUMP'
            Multi(modifier=0, tokens=1)
              Single(term='foo', mod=0, scope=null)
            DUMP,
        'foo bar' => <<<'DUMP'
            Multi(modifier=0, tokens=2)
              Single(term='foo', mod=0, scope=null)
              Single(term='bar', mod=0, scope=null)
            DUMP,
        'alpha beta gamma' => <<<'DUMP'
            Multi(modifier=0, tokens=3)
              Single(term='alpha', mod=0, scope=null)
              Single(term='beta', mod=0, scope=null)
              Single(term='gamma', mod=0, scope=null)
            DUMP,
        'foo OR bar' => <<<'DUMP'
            Multi(modifier=0, tokens=2)
              Single(term='foo', mod=0, scope=null)
              Single(term='bar', mod=36 [OR|BREAK], scope=null)
            DUMP,
        'foo AND bar' => <<<'DUMP'
            Multi(modifier=0, tokens=2)
              Single(term='foo', mod=0, scope=null)
              Single(term='bar', mod=32 [BREAK], scope=null)
            DUMP,
        'a OR b c d' => <<<'DUMP'
            Multi(modifier=0, tokens=2)
              Single(term='a', mod=0, scope=null)
              Multi(modifier=4, tokens=3)
                Single(term='b', mod=32 [BREAK], scope=null)
                Single(term='c', mod=0, scope=null)
                Single(term='d', mod=0, scope=null)
            DUMP,
        '-foo' => <<<'DUMP'
            Multi(modifier=0, tokens=1)
              Single(term='foo', mod=2 [NOT], scope=null)
            DUMP,
        'foo -bar' => <<<'DUMP'
            Multi(modifier=0, tokens=2)
              Single(term='foo', mod=0, scope=null)
              Single(term='bar', mod=2 [NOT], scope=null)
            DUMP,
        '"foo bar"' => <<<'DUMP'
            Multi(modifier=0, tokens=1)
              Single(term='foo bar', mod=1 [QUOTED], scope=null)
            DUMP,
        '"foo bar"*' => <<<'DUMP'
            Multi(modifier=0, tokens=1)
              Single(term='foo bar', mod=17 [QUOTED|WE], scope=null)
            DUMP,
        'foo*' => <<<'DUMP'
            Multi(modifier=0, tokens=1)
              Single(term='foo', mod=16 [WE], scope=null)
            DUMP,
        '*foo' => <<<'DUMP'
            Multi(modifier=0, tokens=1)
              Single(term='foo', mod=8 [WB], scope=null)
            DUMP,
        '*foo*' => <<<'DUMP'
            Multi(modifier=0, tokens=1)
              Single(term='foo', mod=24 [WB|WE], scope=null)
            DUMP,
        'F2.8' => <<<'DUMP'
            Multi(modifier=0, tokens=1)
              Single(term='F2.8', mod=0, scope=null)
            DUMP,
        '(a OR b) c' => <<<'DUMP'
            Multi(modifier=0, tokens=2)
              Multi(modifier=0, tokens=2)
                Single(term='a', mod=32 [BREAK], scope=null)
                Single(term='b', mod=36 [OR|BREAK], scope=null)
              Single(term='c', mod=0, scope=null)
            DUMP,
        '(a (b OR c))' => <<<'DUMP'
            Multi(modifier=0, tokens=1)
              Multi(modifier=0, tokens=2)
                Single(term='a', mod=32 [BREAK], scope=null)
                Multi(modifier=0, tokens=2)
                  Single(term='b', mod=32 [BREAK], scope=null)
                  Single(term='c', mod=36 [OR|BREAK], scope=null)
            DUMP,
        'foo, bar; baz!' => <<<'DUMP'
            Multi(modifier=0, tokens=3)
              Single(term='foo', mod=0, scope=null)
              Single(term='bar', mod=0, scope=null)
              Single(term='baz', mod=0, scope=null)
            DUMP,
        'tag:John' => <<<'DUMP'
            Multi(modifier=0, tokens=1)
              Single(term='John', mod=32 [BREAK], scope=tag)
            DUMP,
        'tag:(John Bill)' => <<<'DUMP'
            Multi(modifier=0, tokens=1)
              Multi(modifier=0, tokens=2)
                Single(term='John', mod=32 [BREAK], scope=tag)
                Single(term='Bill', mod=0, scope=tag)
            DUMP,
        'tag:John OR Bill' => <<<'DUMP'
            Multi(modifier=0, tokens=2)
              Single(term='John', mod=32 [BREAK], scope=tag)
              Single(term='Bill', mod=36 [OR|BREAK], scope=null)
            DUMP,
    ];
}
