<?php

declare(strict_types=1);

namespace Piwigo\Tools\PhpStan;

use PhpParser\Node;
use PhpParser\Node\Expr\ErrorSuppress;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Bans the `@` error-suppression operator across the codebase. Task #9 of
 * the PHP modernization roadmap eliminated all 263 `@` sites; this rule
 * prevents regressions.
 *
 * Replacement guidance:
 *   - missing array key  → `$arr['k'] ?? default`
 *   - file ops           → `\Piwigo\Core\Filesystem::tryUnlink/Rmdir/...`
 *                          or `is_file($p) ? filemtime($p) : false`
 *   - unserialize        → `safe_unserialize($s)`
 *   - getimagesize/exif  → `pwg_safe_getimagesize`/`pwg_safe_exif_read_data`
 *   - network/curl       → wrap in `set_error_handler(fn():bool=>true) /
 *                          restore_error_handler` inside try/finally
 *   - optional include   → `if (is_readable($p)) { include $p; }`
 *   - header             → `if (!headers_sent()) { header(...); }`
 *
 * @implements Rule<ErrorSuppress>
 */
final class NoErrorSuppressionRule implements Rule
{
    public function getNodeType(): string
    {
        return ErrorSuppress::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        return [
            RuleErrorBuilder::message(
                "Do not use the '@' error-suppression operator. "
                . 'Replace with explicit handling — see '
                . 'tools/phpstan/NoErrorSuppressionRule.php for the migration map.'
            )
                ->identifier('piwigo.noErrorSuppression')
                ->build(),
        ];
    }
}
