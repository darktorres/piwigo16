<?php

declare(strict_types=1);

use PHP_CodeSniffer\Standards\PSR1\Sniffs\Methods\CamelCapsMethodNameSniff;
use PhpCsFixer\Fixer\Alias\RandomApiMigrationFixer;
use PhpCsFixer\Fixer\Basic\SingleLineEmptyBodyFixer;
use PhpCsFixer\Fixer\Strict\DeclareStrictTypesFixer;
use PhpCsFixer\Fixer\StringNotation\NoTrailingWhitespaceInStringFixer;
use PhpCsFixer\Fixer\Whitespace\LineEndingFixer;
use Symplify\EasyCodingStandard\Config\ECSConfig;
use Symplify\EasyCodingStandard\ValueObject\Option;

/**
 * @psalm-suppress UndefinedClass CamelCapsMethodNameSniff resolves fine at
 *   ECS's own runtime (verified: `vendor/bin/ecs check` runs clean with
 *   this exact config) via ECS's internal PHP-Scoper class-alias/scoping
 *   machinery, but the real, unprefixed class only lives under ECS's own
 *   nested vendor/ path (vendor/symplify/easy-coding-standard/vendor/
 *   squizlabs/php_codesniffer/...), which Psalm's own <ignoreFiles> vendor
 *   exclusion can't see into, and Composer's autoloader doesn't expose it
 *   either (confirmed: class_exists() is false even with the project's
 *   real autoloader loaded) -- a Psalm/Composer visibility gap in ECS's
 *   own boxing, not a broken config reference.
 * @psalm-suppress InvalidArgument Same root cause cascading into
 *   withRules()'s own array-shape check: it can't verify
 *   CamelCapsMethodNameSniff::class satisfies class-string<Sniff> without
 *   first resolving the class.
 */
return ECSConfig::configure()
    ->withPaths([
        __DIR__,
    ])
    ->withSkip([
        // Generated PHPStan Latte analysis files (bin/piwigo
        // phpstan-latte:compile) -- Latte's own compiled-output style,
        // regenerated wholesale; PHPStan analyses them, ECS must not
        // restyle them.
        __DIR__ . '/_analysis',
        __DIR__ . '/_data',
        // Generated analysis shims (bin/piwigo phpstan-latte:generate-shims)
        // -- auto-generated signatures, regenerated wholesale; style churn
        // here would just get overwritten (same reasoning as the standing
        // "don't hand-edit auto-generated files" rule).
        __DIR__ . '/tools/phpstan/Latte/Generated',
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
        // PHP's stream-wrapper engine calls these methods reflectively by
        // their exact snake_case name (php.net/manual/en/class.streamwrapper.php)
        // -- not renameable, so exempt from the PSR-1 camelCase check.
        // Each file is a single-purpose stream-wrapper test double, so a
        // whole-file skip doesn't hide real method-naming debt.
        CamelCapsMethodNameSniff::class => [
            __DIR__ . '/tests/Integration/ThemesStandardPagesLogoStreamWrapper.php',
            __DIR__ . '/tests/Unit/Image/ImageServiceTestFailedOpenStreamWrapper.php',
            __DIR__ . '/tests/Unit/Template/TemplateInstanceTestFakeStatStreamWrapper.php',
        ],
    ])
    ->withPreparedSets(
        cleanCode: true,
        common: true,
        psr12: true,
    )
    ->withRules([
        // PHP_CodeSniffer sniff (not a php-cs-fixer fixer): ECS's psr12
        // prepared set only covers PHP-CS-Fixer's formatting rules, which
        // never rename identifiers -- PSR-1 3.1's camelCase method-name
        // requirement needs this real PHPCS sniff instead. Report-only by
        // design: renaming could break overrides/interface implementations,
        // so this flags violations rather than auto-fixing them.
        CamelCapsMethodNameSniff::class,
        DeclareStrictTypesFixer::class,
        LineEndingFixer::class,
        NoTrailingWhitespaceInStringFixer::class,
        RandomApiMigrationFixer::class,
        SingleLineEmptyBodyFixer::class,
    ])
    ->withSpacing(indentation: Option::INDENTATION_SPACES, lineEnding: "\n")
;
