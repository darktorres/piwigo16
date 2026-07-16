<?php

declare(strict_types=1);

namespace Utils\Rector;

use PhpParser\Node;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Stmt\Expression;
use PhpParser\ParserFactory;
use Rector\Rector\AbstractRector;
use Symplify\RuleDocGenerator\ValueObject\CodeSample\CodeSample;
use Symplify\RuleDocGenerator\ValueObject\RuleDefinition;

/**
 * One-shot P23 batch 8d codemod: turns a bare free-function call into
 * `(new Target(ctorArgs...))->method(sameArgs)`, preserving the original
 * call's arguments unchanged (a pure rename, never a signature reshape --
 * every mapped function already has an identical-signature real class
 * method). Discarded after this migration lands; see
 * tools/rector-user-migration.php.
 */
final class FuncCallToNewMethodCallRector extends AbstractRector
{
    /**
     * @var array<string, array{0: string, 1: string}> old func name => [constructor expr PHP code, method name]
     */
    private array $map;

    /**
     * @var array<string, New_> old func name => parsed+cached constructor AST (cloned per use)
     */
    private array $parsedCtor = [];

    public function __construct()
    {
        $this->map = require __DIR__ . '/func-call-to-new-method-call-map.php';
    }

    public function getRuleDefinition(): RuleDefinition
    {
        return new RuleDefinition('Turn a bare free-function call into (new Target(...))->method(...)', [
            new CodeSample('get_default_theme();', '(new \Piwigo\Users\UserService(...))->getDefaultTheme();'),
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
        foreach ($this->map as $oldFuncName => [$ctorCode, $methodName]) {
            if (! $this->isName($node, $oldFuncName)) {
                continue;
            }

            $ctor = $this->getParsedCtor($oldFuncName, $ctorCode);

            return new MethodCall(clone $ctor, $methodName, $node->args);
        }

        return null;
    }

    private function getParsedCtor(string $oldFuncName, string $ctorCode): New_
    {
        if (isset($this->parsedCtor[$oldFuncName])) {
            return $this->parsedCtor[$oldFuncName];
        }

        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $stmts = $parser->parse('<?php ' . $ctorCode . ';');
        if ($stmts === null || ! isset($stmts[0]) || ! $stmts[0] instanceof Expression || ! $stmts[0]->expr instanceof New_) {
            throw new \RuntimeException('FuncCallToNewMethodCallRector: failed to parse constructor snippet for ' . $oldFuncName . ': ' . $ctorCode);
        }

        return $this->parsedCtor[$oldFuncName] = $stmts[0]->expr;
    }
}
