<?php

declare(strict_types=1);

/**
 * One-shot tool to seed Piwigo\Config\Config::SCHEMA from the existing typed
 * accessors. Reads Config.php's AST, extracts every literal key + type +
 * default that the simple accessors pass to self::getString / getInt / getBool
 * / getFloat or to self::src()[…]. Emits a PHP array literal to stdout.
 *
 * Run once during #5 to bootstrap the SCHEMA constant. Delete after use.
 *
 * Usage: php tools/extract-schema.php > /tmp/schema.txt
 */

require __DIR__ . '/../vendor/autoload.php';

use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;

$source = file_get_contents(__DIR__ . '/../src/Piwigo/Config/Config.php');
if ($source === false) {
    fwrite(STDERR, "cannot read Config.php\n");
    exit(1);
}

$parser = (new ParserFactory())->createForHostVersion();
$ast    = $parser->parse($source);
if ($ast === null) {
    fwrite(STDERR, "parse error\n");
    exit(1);
}

$finder = new NodeFinder();

/** @var Node\Stmt\ClassMethod[] $methods */
$methods = $finder->find($ast, fn (Node $n) => $n instanceof Node\Stmt\ClassMethod);

$entries = [];

foreach ($methods as $method) {
    if (!$method->isStatic() || !$method->isPublic() || $method->name->toString() === 'instance') {
        continue;
    }

    $methodName = $method->name->toString();
    $stmts      = $method->getStmts() ?? [];

    $body = $stmts;

    // Pattern A: simple `return self::getXxx('key', $default);`
    if (count($body) === 1 && $body[0] instanceof Node\Stmt\Return_) {
        $expr = $body[0]->expr;
        if ($expr instanceof Node\Expr\StaticCall && $expr->name instanceof Node\Identifier) {
            $callee = $expr->name->toString();
            if (in_array($callee, ['getString', 'getInt', 'getBool'], true)) {
                $args = $expr->getArgs();
                $keyArg = $args[0]->value ?? null;
                if ($keyArg instanceof Node\Scalar\String_) {
                    $type = match ($callee) {
                        'getString' => 'string',
                        'getInt'    => 'int',
                        'getBool'   => 'bool',
                    };
                    $default = count($args) >= 2 ? renderConst($args[1]->value) : null;
                    $entries[$keyArg->value] = ['type' => $type, 'default' => $default, 'method' => $methodName];
                    continue;
                }
            }
        }
    }

    // Pattern B: nullable string accessor — looks for `self::src()['key'] ?? null` then a coerce.
    // Identify the key string used inside src()[…] anywhere in the body.
    $key = null;
    $finder2 = new NodeFinder();
    $arrayFetches = $finder2->find($body, fn (Node $n) => $n instanceof Node\Expr\ArrayDimFetch);
    foreach ($arrayFetches as $fetch) {
        if (
            $fetch->var instanceof Node\Expr\StaticCall
            && $fetch->var->name instanceof Node\Identifier
            && $fetch->var->name->toString() === 'src'
            && $fetch->dim instanceof Node\Scalar\String_
        ) {
            $key = $fetch->dim->value;
            break;
        }
    }
    if ($key !== null) {
        // Determine the return type from the method signature.
        $rt = $method->getReturnType();
        $type = 'mixed';
        $custom = true;
        if ($rt instanceof Node\NullableType && $rt->type instanceof Node\Identifier && $rt->type->toString() === 'string') {
            $type = 'string';
            $custom = false; // nullable string is a regular shape
            $entries[$key] = ['type' => $type, 'default' => 'null', 'method' => $methodName, 'nullable' => true];
            continue;
        }
        // Otherwise, mark as custom so the generator skips it; the entry still needs SCHEMA presence.
        $entries[$key] = ['type' => $rt instanceof Node\Identifier ? $rt->toString() : 'mixed', 'default' => null, 'method' => $methodName, 'custom' => true];
    }
}

// Pretty-print as a PHP array literal.
echo "// === extracted from Config.php typed accessors — " . count($entries) . " entries ===\n";
ksort($entries);
foreach ($entries as $key => $entry) {
    $defaultRepr = $entry['default'] ?? 'null';
    $extras = '';
    if (!empty($entry['nullable'])) {
        $extras .= ", 'nullable' => true";
    }
    if (!empty($entry['custom'])) {
        $extras .= ", 'custom' => true";
    }
    // Always emit method so SCHEMA is the unambiguous source of method-name truth;
    // generator never needs snake→camel heuristics.
    $methodRepr = "'{$entry['method']}'";
    printf("    %-46s => ['type' => %-9s, 'default' => %-22s, 'method' => %s%s],\n",
        var_export($key, true),
        "'{$entry['type']}'",
        $defaultRepr,
        $methodRepr,
        $extras
    );
}

function renderConst(Node\Expr $expr): ?string
{
    if ($expr instanceof Node\Scalar\String_)  { return var_export($expr->value, true); }
    if ($expr instanceof Node\Scalar\Int_)     { return (string) $expr->value; }
    if ($expr instanceof Node\Scalar\Float_)   { return (string) $expr->value; }
    if ($expr instanceof Node\Expr\ConstFetch) {
        return $expr->name->toString();
    }
    if ($expr instanceof Node\Expr\BinaryOp) {
        $left  = renderConst($expr->left);
        $right = renderConst($expr->right);
        if ($left !== null && $right !== null) {
            $op = match ($expr::class) {
                Node\Expr\BinaryOp\Mul::class => '*',
                Node\Expr\BinaryOp\Plus::class => '+',
                default => null,
            };
            if ($op !== null) {
                return "$left $op $right";
            }
        }
    }
    return null;
}
