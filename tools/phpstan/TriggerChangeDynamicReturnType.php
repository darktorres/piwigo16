<?php

declare(strict_types=1);

namespace Piwigo\Tools\PhpStan;

use PhpParser\Node\Expr\StaticCall;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Type\ArrayType;
use PHPStan\Type\DynamicStaticMethodReturnTypeExtension;
use PHPStan\Type\GeneralizePrecision;
use PHPStan\Type\MixedType;
use PHPStan\Type\NeverType;
use PHPStan\Type\Type;

/**
 * Teaches PHPStan that EventDispatcher::dispatch() returns the same type as its second argument.
 * The plugin filter contract requires handlers to return the same type they receive.
 */
class TriggerChangeDynamicReturnType implements DynamicStaticMethodReturnTypeExtension
{
    public function getClass(): string
    {
        return \Piwigo\Plugins\EventDispatcher::class;
    }

    public function isStaticMethodSupported(MethodReflection $methodReflection): bool
    {
        return $methodReflection->getName() === 'dispatch';
    }

    public function getTypeFromStaticMethodCall(
        MethodReflection $methodReflection,
        StaticCall $methodCall,
        Scope $scope
    ): Type {
        $args = $methodCall->getArgs();
        if (count($args) >= 2) {
            $type = $scope->getType($args[1]->value)->generalize(GeneralizePrecision::lessSpecific());
            if ($type->isArray()->yes() && $type->getIterableValueType() instanceof NeverType) {
                return new ArrayType(new MixedType(), new MixedType());
            }
            return $type;
        }
        return new MixedType();
    }
}
