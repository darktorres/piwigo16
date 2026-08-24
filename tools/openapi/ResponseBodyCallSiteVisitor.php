<?php

declare(strict_types=1);

namespace Piwigo\Tools\OpenApi;

use Override;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\NodeVisitorAbstract;

/**
 * Collects every `ResponseFactory::json(...)` call site inside a class
 * body, resolving the response array's own field names where possible --
 * mirrors `tools/phpstan/Latte/TemplateCallSiteVisitor.php`'s own "collect
 * matching call nodes per class" shape, adapted from that visitor's
 * `Node\Expr\MethodCall` (instance calls) to `Node\Expr\StaticCall`, since
 * every real call site here is `ResponseFactory::json(...)`, a static
 * call.
 *
 * Field-name resolution has two tiers, confirmed against the real
 * codebase (not assumed): an inline array literal argument
 * (`ResponseFactory::json(['id' => ...])`) resolves directly; a local
 * variable argument (`$body = ['id' => ...]; return
 * ResponseFactory::json($body);`) resolves by tracking `$var = [...]`
 * assignments per method body (reset at each `ClassMethod` boundary) and
 * looking the variable up when the call references it. Confirmed live:
 * 53/66 relevant controllers use the first shape, 13/66 the second
 * (`ImageGetController` among them) -- a version that only inspects the
 * call's own argument silently returns zero keys for that second group,
 * not a rare edge case.
 *
 * A real third tier exists that neither resolves: the call argument is a
 * method-call result, a static-call result, an array-index expression, or
 * a null-coalesce (`ResponseFactory::json($this->
 * sessionStatusPresenter->present())`, `ResponseFactory::json
 * (GroupPresenter::toArray($rows[0]), 201)`, `ResponseFactory::json
 * ($rows[0] ?? [...])`). No amount of *local* AST scanning recovers these
 * -- the actual shape lives in a different method, sometimes a different
 * class entirely, which is full type-inference-engine territory,
 * explicitly not this tool's job. Those calls come back with `keys ===
 * null` (as opposed to `[]`, a genuinely field-less response) so the
 * bootstrap script can mark them for fully manual authoring instead of
 * emitting a silently-empty schema that looks the same as a real one.
 *
 * Only string-keyed items are collected as field names; a computed key
 * or an unpacked spread (`...$rest`) inside the literal is skipped
 * rather than guessed at, for the same "don't fabricate a fact this
 * class can't actually verify" reason as the two other tiers above.
 */
final class ResponseBodyCallSiteVisitor extends NodeVisitorAbstract
{
    /**
     * @var list<array{line: int, keys: ?list<string>}>
     */
    public array $calls = [];

    /**
     * @var array<string, Array_>
     */
    private array $varAssignments = [];

    #[Override]
    public function enterNode(Node $node): ?Node
    {
        if ($node instanceof ClassMethod) {
            $this->varAssignments = [];
        }

        if ($node instanceof Assign && $node->var instanceof Variable && is_string($node->var->name) && $node->expr instanceof Array_) {
            $this->varAssignments[$node->var->name] = $node->expr;
        }

        if (
            $node instanceof StaticCall
            && $node->class instanceof Name
            && $node->class->toString() === 'ResponseFactory'
            && $node->name instanceof Identifier
            && $node->name->name === 'json'
        ) {
            $this->calls[] = [
                'line' => $node->getStartLine(),
                'keys' => $this->resolveKeys($node),
            ];
        }

        return null;
    }

    /**
     * @return ?list<string>
     */
    private function resolveKeys(StaticCall $node): ?array
    {
        $arrayNode = null;
        foreach ($node->args as $arg) {
            if (! $arg instanceof Arg) {
                continue;
            }
            if ($arg->value instanceof Array_) {
                $arrayNode = $arg->value;
            } elseif ($arg->value instanceof Variable && is_string($arg->value->name) && isset($this->varAssignments[$arg->value->name])) {
                $arrayNode = $this->varAssignments[$arg->value->name];
            }
        }

        if ($arrayNode === null) {
            return null;
        }

        $keys = [];
        foreach ($arrayNode->items as $item) {
            // Array_::$items' own docblock types every element ArrayItem
            // (never null) -- PHPStan trusts that (proves an explicit null
            // guard here dead code, notIdentical.alwaysTrue); Psalm's own
            // array-shape inference doesn't pick up that certainty.
            /** @psalm-suppress PossiblyNullPropertyFetch see comment above */
            if ($item->key instanceof String_) {
                $keys[] = $item->key->value;
            }
        }

        return $keys;
    }
}
