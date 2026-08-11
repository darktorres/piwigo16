<?php

declare(strict_types=1);

use PhpCsFixer\Fixer\Alias\RandomApiMigrationFixer;
use PhpCsFixer\Fixer\Basic\SingleLineEmptyBodyFixer;
use PhpCsFixer\Fixer\Strict\DeclareStrictTypesFixer;
use PhpCsFixer\Fixer\StringNotation\NoTrailingWhitespaceInStringFixer;
use PhpCsFixer\Fixer\Whitespace\LineEndingFixer;
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
        __DIR__ . '/phpstan-stubs',
        // Gitignored runtime plugin-drop-in mount (only index.php is
        // tracked) -- any real plugin code placed here in a local checkout
        // isn't part of this project's own codebase.
        __DIR__ . '/plugins',
        // public/ symlinks back to already-scanned themes/, dist/,
        // _data/combined/ (Part II web-root isolation) -- without these,
        // the exact same files get scanned (and --fix'd) twice under two
        // different path strings.
        __DIR__ . '/public/_data/combined',
        __DIR__ . '/public/dist',
        __DIR__ . '/public/themes',
        // Gitignored runtime user-uploaded content, never source code.
        __DIR__ . '/upload',
        __DIR__ . '/vendor',
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
