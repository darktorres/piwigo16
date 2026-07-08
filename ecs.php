<?php

declare(strict_types=1);

use PhpCsFixer\Fixer\Alias\RandomApiMigrationFixer;
use PhpCsFixer\Fixer\Basic\SingleLineEmptyBodyFixer;
use PhpCsFixer\Fixer\Phpdoc\GeneralPhpdocAnnotationRemoveFixer;
use PhpCsFixer\Fixer\Strict\DeclareStrictTypesFixer;
use PhpCsFixer\Fixer\StringNotation\NoTrailingWhitespaceInStringFixer;
use PhpCsFixer\Fixer\Whitespace\LineEndingFixer;
use Symplify\CodingStandard\Fixer\Commenting\ParamReturnAndVarTagMalformsFixer;
use Symplify\CodingStandard\Fixer\LineLength\LineLengthFixer;
use Symplify\EasyCodingStandard\Config\ECSConfig;
use Symplify\EasyCodingStandard\ValueObject\Option;

return ECSConfig::configure()
    ->withPaths([
        __DIR__,
    ])
    ->withSkip([
        __DIR__ . '/_data',
        __DIR__ . '/galleries',
        __DIR__ . '/language',
        __DIR__ . '/local',
        __DIR__ . '/node_modules',
        __DIR__ . '/tests',
        __DIR__ . '/vendor',
        // Too aggressive on ~500 files of untouched legacy docblocks this phase.
        GeneralPhpdocAnnotationRemoveFixer::class,
        LineLengthFixer::class,
        ParamReturnAndVarTagMalformsFixer::class,
    ])
    ->withRootFiles()
    ->withPreparedSets(
        cleanCode: true,
        common: true,
        psr12: true,
    )
    ->withRules([
        DeclareStrictTypesFixer::class,
        LineEndingFixer::class,
        NoTrailingWhitespaceInStringFixer::class,
        RandomApiMigrationFixer::class,
        SingleLineEmptyBodyFixer::class,
    ])
    ->withSpacing(indentation: Option::INDENTATION_SPACES, lineEnding: "\n")
;
