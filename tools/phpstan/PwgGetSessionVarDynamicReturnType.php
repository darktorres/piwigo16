<?php

declare(strict_types=1);

namespace Piwigo\Tools\PhpStan;

use PhpParser\Node\Expr\FuncCall;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\FunctionReflection;
use PHPStan\Type\DynamicFunctionReturnTypeExtension;
use PHPStan\Type\MixedType;
use PHPStan\Type\NullType;
use PHPStan\Type\Type;
use PHPStan\Type\UnionType;

/**
 * Teaches PHPStan that pwg_get_session_var() returns the same type as its $default argument.
 * When the session key exists, it was stored with the same type (via pwg_set_session_var).
 * When absent, $default is returned exactly. Either way the return type matches $default.
 */
class PwgGetSessionVarDynamicReturnType implements DynamicFunctionReturnTypeExtension
{
    public function isFunctionSupported(FunctionReflection $functionReflection): bool
    {
        return $functionReflection->getName() === 'pwg_get_session_var';
    }

    public function getTypeFromFunctionCall(
        FunctionReflection $functionReflection,
        FuncCall $functionCall,
        Scope $scope
    ): Type {
        $args = $functionCall->getArgs();
        if (count($args) >= 2) {
            return $scope->getType($args[1]->value);
        }
        // No default given — default is null, return is null|mixed (session value unknown)
        return new NullType();
    }
}
