<?php

declare(strict_types=1);

namespace Piwigo\Tools\PhpStan;

use PhpParser\Node;
use PhpParser\Node\Expr\New_;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Flags `new $variable()` patterns where a dynamic string is used as a class name.
 *
 * After the Phase 3 PSR-4 migration, short class names no longer resolve in the
 * global namespace. Every dispatch table should use ::class FQN strings instead of
 * bare 'ClassName' strings. The three known-intentional sites in Phase 3 are:
 *   - include/functions_calendar.inc.php — uses ::class FQN (safe)
 *   - include/functions_plugins.inc.php  — plugin dispatch (baselined)
 *   - include/functions_search.inc.php   — language inflector (baselined)
 *
 * @implements Rule<New_>
 */
final class NoDynamicNewRule implements Rule
{
    public function getNodeType(): string
    {
        return New_::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        if (!($node->class instanceof Node\Expr\Variable)) {
            return [];
        }

        $varName = $node->class->name;
        if (!is_string($varName)) {
            return [
                RuleErrorBuilder::message(
                    "Dynamic 'new \$var()' with computed variable name — require class_exists() guard."
                )->identifier('piwigo.noDynamicNew')->build(),
            ];
        }

        return [
            RuleErrorBuilder::message(
                "Dynamic 'new \$$varName()' detected — use a ::class FQN string dispatch table or a typed factory."
            )->identifier('piwigo.noDynamicNew')->tip(
                'For dispatch tables, prefer MyClass::class strings over bare \'ClassName\' strings.'
            )->build(),
        ];
    }
}
