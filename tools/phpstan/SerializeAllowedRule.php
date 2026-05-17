<?php

declare(strict_types=1);

namespace Piwigo\Tools\PhpStan;

use PhpParser\Node;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Bans `serialize()` / `unserialize()` callers in src/. The B1–B10 storage
 * refactor moved every persisted blob to JSON, dedicated tables, or PSR-6;
 * the R1–R3 follow-up cleared the last three callers (CheckIntegrity hash
 * input, PreferencesService user_infos.preferences blob, StringUtil
 * safeUnserialize helper).
 *
 * The allow-list is intentionally empty. Re-populate it only when a new
 * site is genuinely justified and the rationale lives next to the call.
 *
 * @implements Rule<FuncCall>
 */
final class SerializeAllowedRule implements Rule
{
    private const GUARDED = ['serialize', 'unserialize'];

    private const ALLOWED_BASENAMES = [];

    public function getNodeType(): string
    {
        return FuncCall::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        if (!$node->name instanceof Name) {
            return [];
        }
        $funcName = strtolower($node->name->toString());
        if (!in_array($funcName, self::GUARDED, true)) {
            return [];
        }

        $file = $scope->getFile();
        if (!str_contains($file, '/src/Piwigo/')) {
            return [];
        }

        $basename = basename($file);
        // ALLOWED_BASENAMES is intentionally empty after R3 — the check is
        // structural so re-allowing a site only needs the constant changed.
        // @phpstan-ignore function.impossibleType
        if (in_array($basename, self::ALLOWED_BASENAMES, true)) {
            return [];
        }

        return [
            RuleErrorBuilder::message(
                sprintf(
                    'Do not introduce %s() calls in src/. The B1-B10 storage refactor + R1-R3 '
                    . 'cleanup moved every persisted blob to JSON, dedicated tables, or PSR-6, '
                    . 'and removed the last hash/helper sites. If you genuinely need a new site, '
                    . 'allow-list the file in tools/phpstan/SerializeAllowedRule.php with a '
                    . 'rationale next to the call.',
                    $funcName
                )
            )
                ->identifier('piwigo.serializeAllowed')
                ->build(),
        ];
    }
}
