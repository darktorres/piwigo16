<?php

declare(strict_types=1);

namespace Piwigo\Tools\PhpStan\Latte;

use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\NodeVisitorAbstract;

/**
 * Collects every method call inside a class body along with the name of the
 * class it was found in -- TemplateCallSiteScanner::scan()'s own visitor,
 * extracted to a named class (was an anonymous class) after PHPStan's
 * multi-process/full-project analysis lost the `$calls` array-shape type
 * (0 errors analyzing this file alone; 8 errors -- both properties'
 * `missingType.iterableValue` plus every downstream `$call[...]` read typed
 * as `mixed` -- analyzing it as part of the full project, confirmed
 * reproducible after clearing PHPStan's result cache, so a real anonymous-
 * class-under-parallel-workers gap, not stale caching). Named classes have
 * a stable identity across PHPStan's worker processes; anonymous ones,
 * empirically, do not reliably keep their property docblock type.
 */
final class TemplateCallSiteVisitor extends NodeVisitorAbstract
{
    /**
     * @var list<array{class: string, method: string, node: MethodCall}>
     */
    public array $calls = [];

    /**
     * @var list<string>
     */
    private array $classStack = [];

    public function enterNode(Node $node): ?Node
    {
        if ($node instanceof ClassLike) {
            $this->classStack[] = isset($node->namespacedName)
                ? $node->namespacedName->toString()
                : ($node->name?->toString() ?? '(anonymous)');
        }
        if ($node instanceof MethodCall && $node->name instanceof Identifier && $this->classStack !== []) {
            $this->calls[] = [
                'class' => $this->classStack[count($this->classStack) - 1],
                'method' => $node->name->name,
                'node' => $node,
            ];
        }

        return null;
    }

    public function leaveNode(Node $node): ?Node
    {
        if ($node instanceof ClassLike) {
            array_pop($this->classStack);
        }

        return null;
    }
}
