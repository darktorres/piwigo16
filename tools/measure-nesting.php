<?php

declare(strict_types=1);

/**
 * AST-based nesting-depth measurement.
 *
 * Walks every src/Piwigo/**\/*.php, parses with nikic/php-parser, and for
 * each method/function records the maximum number of nested control-flow
 * frames (if/elseif/else/for/foreach/while/do/switch/case/match/try/catch/
 * finally + closures + arrow fns). Prints:
 *   - histogram of methods by depth,
 *   - top 50 methods by depth,
 *   - reference-method sanity-check list,
 *   - files containing at least one method at depth >= 5.
 *
 * Run with: php tools/measure-nesting.php
 *
 * Replaces the older leading-spaces grep proxy (which counted wrapped
 * function args + fluent chains + closures-inside-arrays as "deep").
 */

require __DIR__ . '/../vendor/autoload.php';

use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;

final class NestingVisitor extends NodeVisitorAbstract
{
    /** @var list<int> stack of current depth, one per function/method/closure */
    private array $depthStack = [];
    /** @var list<int> stack of max depth observed, one per function/method/closure */
    private array $maxStack = [];
    /** @var list<string> stack of names */
    private array $nameStack = [];
    /** @var list<int> stack of starting line numbers */
    private array $lineStack = [];

    /** @var list<array{name: string, line: int, depth: int}> */
    public array $results = [];

    private ?string $currentClass = null;

    public function enterNode(Node $node)
    {
        if ($node instanceof Node\Stmt\Class_ || $node instanceof Node\Stmt\Interface_ || $node instanceof Node\Stmt\Trait_ || $node instanceof Node\Stmt\Enum_) {
            $this->currentClass = $node->name?->toString() ?? '<anonymous>';
            return null;
        }

        if (self::opensFunctionScope($node)) {
            $this->depthStack[] = 0;
            $this->maxStack[]   = 0;
            $this->nameStack[]  = self::scopeName($node, $this->currentClass);
            $this->lineStack[]  = $node->getStartLine();
            return null;
        }

        if ($this->depthStack === []) {
            return null; // top-level code outside any function — skip
        }

        if (self::isNestingFrame($node)) {
            $top = array_key_last($this->depthStack);
            $this->depthStack[$top]++;
            if ($this->depthStack[$top] > $this->maxStack[$top]) {
                $this->maxStack[$top] = $this->depthStack[$top];
            }
        }
        return null;
    }

    public function leaveNode(Node $node)
    {
        if (self::opensFunctionScope($node)) {
            $this->results[] = [
                'name'  => array_pop($this->nameStack),
                'line'  => array_pop($this->lineStack),
                'depth' => array_pop($this->maxStack),
            ];
            array_pop($this->depthStack);
            return null;
        }

        if ($this->depthStack === []) {
            return null;
        }

        if (self::isNestingFrame($node)) {
            $top = array_key_last($this->depthStack);
            $this->depthStack[$top]--;
        }
        return null;
    }

    private static function opensFunctionScope(Node $node): bool
    {
        return $node instanceof Node\Stmt\ClassMethod
            || $node instanceof Node\Stmt\Function_
            || $node instanceof Node\Expr\Closure
            || $node instanceof Node\Expr\ArrowFunction;
    }

    /**
     * Count anything that introduces a nested control-flow frame readers
     * have to track in their head: branches, loops, switch arms, match
     * expressions, try/catch/finally.
     */
    private static function isNestingFrame(Node $node): bool
    {
        return $node instanceof Node\Stmt\If_
            || $node instanceof Node\Stmt\ElseIf_
            || $node instanceof Node\Stmt\Else_
            || $node instanceof Node\Stmt\For_
            || $node instanceof Node\Stmt\Foreach_
            || $node instanceof Node\Stmt\While_
            || $node instanceof Node\Stmt\Do_
            || $node instanceof Node\Stmt\Switch_
            || $node instanceof Node\Stmt\Case_
            || $node instanceof Node\Stmt\Match_
            || $node instanceof Node\Expr\Match_
            || $node instanceof Node\Stmt\TryCatch
            || $node instanceof Node\Stmt\Catch_
            || $node instanceof Node\Stmt\Finally_;
    }

    private static function scopeName(Node $node, ?string $class): string
    {
        if ($node instanceof Node\Stmt\ClassMethod) {
            return ($class ?? '?') . '::' . $node->name->toString();
        }
        if ($node instanceof Node\Stmt\Function_) {
            return $node->name->toString();
        }
        if ($node instanceof Node\Expr\Closure) {
            return ($class ?? '<global>') . '::{closure@' . $node->getStartLine() . '}';
        }
        if ($node instanceof Node\Expr\ArrowFunction) {
            return ($class ?? '<global>') . '::{fn@' . $node->getStartLine() . '}';
        }
        return '?';
    }
}

function walkDir(string $dir): \Generator
{
    $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        /** @var \SplFileInfo $f */
        if ($f->isFile() && $f->getExtension() === 'php') {
            yield $f->getPathname();
        }
    }
}

$parser = (new ParserFactory())->createForHostVersion();
$root   = dirname(__DIR__) . '/src/Piwigo';

$allResults = [];
$histogram  = [0 => 0, 1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0, 6 => 0];

foreach (walkDir($root) as $path) {
    $code = file_get_contents($path);
    if ($code === false) {
        continue;
    }
    try {
        $ast = $parser->parse($code);
    } catch (\Throwable $e) {
        fwrite(STDERR, "parse error in $path: " . $e->getMessage() . "\n");
        continue;
    }
    if ($ast === null) {
        continue;
    }

    $visitor   = new NestingVisitor();
    $traverser = new NodeTraverser();
    $traverser->addVisitor($visitor);
    $traverser->traverse($ast);

    $relative = substr($path, strlen(dirname(__DIR__)) + 1);
    foreach ($visitor->results as $r) {
        $r['file'] = $relative;
        $allResults[] = $r;
        $bucket = min($r['depth'], 6);
        $histogram[$bucket]++;
    }
}

usort($allResults, static fn (array $a, array $b): int => $b['depth'] <=> $a['depth']);

echo "=== Histogram (max nesting depth per function/method) ===\n";
$total = array_sum($histogram);
foreach ($histogram as $d => $count) {
    $label = $d === 6 ? '6+' : (string) $d;
    printf("  depth %s : %5d methods (%5.1f%%)\n", str_pad($label, 2, ' ', STR_PAD_LEFT), $count, $total > 0 ? 100.0 * $count / $total : 0.0);
}
echo "  -----\n";
echo '  total : ' . $total . " methods\n\n";

echo "=== Top 50 methods by depth ===\n";
foreach (array_slice($allResults, 0, 50) as $r) {
    printf("  depth=%d  %s  (%s:%d)\n", $r['depth'], $r['name'], $r['file'], $r['line']);
}

echo "\n=== Reference-method sanity check (N1/N3/N4 targets) ===\n";
$refs = ['SearchFilterRenderer::render', 'QMultiToken::parseExpression', 'MaintenanceController::siteUpdate', 'Updates::getPiwigoNewVersions', 'MiscController::doActionSendMailNotification', 'BatchManagerController::batchManager'];
foreach ($refs as $ref) {
    foreach ($allResults as $r) {
        if ($r['name'] === $ref) {
            printf("  depth=%d  %s  (%s:%d)\n", $r['depth'], $r['name'], $r['file'], $r['line']);
            continue 2;
        }
    }
    echo "  <not found>  $ref\n";
}

echo "\n=== Files containing at least one method at depth >= 5 ===\n";
$byFile = [];
foreach ($allResults as $r) {
    if ($r['depth'] >= 5) {
        $byFile[$r['file']] = ($byFile[$r['file']] ?? 0) + 1;
    }
}
arsort($byFile);
foreach ($byFile as $file => $count) {
    printf("  %3d  %s\n", $count, $file);
}
