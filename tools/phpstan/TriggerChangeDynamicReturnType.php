<?php

declare(strict_types=1);

namespace Piwigo\Tools\PhpStan;

use PhpParser\Node\Expr\FuncCall;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\FunctionReflection;
use PHPStan\Type\ArrayType;
use PHPStan\Type\DynamicFunctionReturnTypeExtension;
use PHPStan\Type\GeneralizePrecision;
use PHPStan\Type\MixedType;
use PHPStan\Type\NeverType;
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
            $type = $scope->getType($args[1]->value)->generalize(GeneralizePrecision::lessSpecific());
            // Widen empty-array literals (array{} → array<never>) to array<mixed> so that
            // conditions like count($result) > 0 are not folded to always-false by PHPStan.
            if ($type->isArray()->yes() && $type->getIterableValueType() instanceof NeverType) {
                return new ArrayType(new MixedType(), new MixedType());
            }
            return $type;
        }
        return new MixedType();
    }
}
