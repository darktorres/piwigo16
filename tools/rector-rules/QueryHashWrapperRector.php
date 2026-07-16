<?php

declare(strict_types=1);

namespace Utils\Rector;

use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Name;
use Rector\Rector\AbstractRector;
use Symplify\RuleDocGenerator\ValueObject\CodeSample\CodeSample;
use Symplify\RuleDocGenerator\ValueObject\RuleDefinition;

/**
 * One-shot P23 batch 8d codemod: retargets the 3 deprecated
 * include/functions.inc.php query2array() wrappers (simple_hash_from_query/
 * hash_from_query/array_from_query) onto query2array() directly (itself a
 * relocate-only free function, functions_mysqli.inc.php, batch 8f --
 * staying bare, not becoming a class method, finding 2). The first two are
 * pure argument-preserving renames; array_from_query()'s single-arg form
 * is also a pure rename, but its two-arg form needs `null` inserted as
 * query2array()'s 2nd positional argument (array_from_query($q, $field) ==
 * query2array($q, null, $field) -- see array_from_query()'s own body).
 * Discarded after this migration lands; see tools/rector-user-migration.php.
 */
final class QueryHashWrapperRector extends AbstractRector
{
    public function getRuleDefinition(): RuleDefinition
    {
        return new RuleDefinition('Retarget simple_hash_from_query()/hash_from_query()/array_from_query() onto query2array()', [
            new CodeSample('array_from_query($q, $field);', 'query2array($q, null, $field);'),
        ]);
    }

    /**
     * @return array<class-string<Node>>
     */
    public function getNodeTypes(): array
    {
        return [FuncCall::class];
    }

    /**
     * @param FuncCall $node
     */
    public function refactor(Node $node): ?Node
    {
        if ($this->isName($node, 'simple_hash_from_query') || $this->isName($node, 'hash_from_query')) {
            $node->name = new Name('query2array');
            return $node;
        }

        if ($this->isName($node, 'array_from_query')) {
            $args = $node->args;
            if (count($args) <= 1) {
                $node->name = new Name('query2array');
                return $node;
            }

            // array_from_query($query, $fieldname) -> query2array($query, null, $fieldname)
            $node->name = new Name('query2array');
            $nullArg = new Arg(new ConstFetch(new Name('null')));
            $node->args = [$args[0], $nullArg, $args[1]];
            return $node;
        }

        return null;
    }
}
