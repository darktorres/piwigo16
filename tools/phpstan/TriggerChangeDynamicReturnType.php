<?php

declare(strict_types=1);

namespace Piwigo\Tools\PhpStan;

use PhpParser\Node\Expr\FuncCall;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\FunctionReflection;
use PHPStan\Type\DynamicFunctionReturnTypeExtension;
use PHPStan\Type\GeneralizePrecision;
use PHPStan\Type\MixedType;
use PHPStan\Type\Type;

/**
 * Teaches PHPStan that trigger_change() returns the same type as its second argument (the data
 * being filtered). The plugin filter contract requires handlers to return the same type they
 * receive; returning a different type is a plugin bug, not a framework type-system issue.
 */
class TriggerChangeDynamicReturnType implements DynamicFunctionReturnTypeExtension
{
    public function isFunctionSupported(FunctionReflection $functionReflection): bool
    {
        return $functionReflection->getName() === 'trigger_change';
    }

    public function getTypeFromFunctionCall(
        FunctionReflection $functionReflection,
        FuncCall $functionCall,
        Scope $scope
    ): Type {
        $args = $functionCall->getArgs();
        if (count($args) >= 2) {
            return $scope->getType($args[1]->value)->generalize(GeneralizePrecision::lessSpecific());
        }
        return new MixedType();
    }
}
