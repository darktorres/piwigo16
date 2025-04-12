<?php

declare(strict_types=1);

use Rector\CodeQuality\Rector\Ternary\UnnecessaryTernaryExpressionRector;
use Rector\CodingStyle\Rector\Encapsed\EncapsedStringsToSprintfRector;
use Rector\CodingStyle\Rector\String_\UseClassKeywordForClassNameResolutionRector;
use Rector\Config\RectorConfig;
use Rector\Php55\Rector\String_\StringClassNameToClassConstantRector;
use Rector\Php71\Rector\FuncCall\RemoveExtraParametersRector;
use Rector\Php73\Rector\String_\SensitiveHereNowDocRector;
use Rector\Php80\Rector\FuncCall\ClassOnObjectRector;
use Rector\Php81\Rector\FuncCall\NullToStrictStringFuncCallArgRector;
use Rector\Strict\Rector\Empty_\DisallowedEmptyRuleFixerRector;

return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/admin',
        __DIR__ . '/inc',
        __DIR__ . '/install',
        __DIR__ . '/language',
        __DIR__ . '/plugins',
        __DIR__ . '/themes',
    ])
    ->withSkip([
        __DIR__ . '/themes/bootstrap_darkroom/node_modules',
        ClassOnObjectRector::class,
        DisallowedEmptyRuleFixerRector::class,
        EncapsedStringsToSprintfRector::class,
        NullToStrictStringFuncCallArgRector::class,
        RemoveExtraParametersRector::class,
        SensitiveHereNowDocRector::class,
        StringClassNameToClassConstantRector::class,
        UnnecessaryTernaryExpressionRector::class,
        UseClassKeywordForClassNameResolutionRector::class,
    ])
    ->withRootFiles()
    ->withPhpSets()
    ->withPreparedSets(
        codeQuality: true,
        codingStyle: true,
        // deadCode: false,
        // earlyReturn: false,
        // instanceOf: false,
        // naming: false,
        // privatization: false,
        typeDeclarations: true
    );
