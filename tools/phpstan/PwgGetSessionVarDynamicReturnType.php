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
use Piwigo\Session\SessionService;

/**
 * Teaches PHPStan that Kernel::service(SessionService::class)->getSessionVar() returns the same type as its $default argument.
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
            // Widen constant types (e.g. literal '' -> string, literal 0 -> int)
            // so comparisons like $result === '' don't always evaluate to true.
            return $scope->getType($args[1]->value)->generalize(GeneralizePrecision::lessSpecific());
        }
        // No default given — session value type is unknown
        return new MixedType();
    }
}
