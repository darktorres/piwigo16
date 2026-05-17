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
 * Bans new `serialize()` / `unserialize()` callers in src/ outside the
 * narrow allow-list that survived the B1–B10 storage refactor.
 *
 * The allow-list (file basenames):
 *   - CheckIntegrity.php         in-memory hash input for anomaly dedup
 *   - PreferencesService.php     user_infos.preferences column (next
 *                                refactor pass — out of B1–B10 scope)
 *   - StringUtil.php             implementation of safeUnserialize()
 *                                (migration-only consumer)
 *   - Version2026*.php           data migrations decoding legacy
 *                                serialize() blobs from older installs
 *
 * Add a new path to ALLOWED only after writing the rationale next to
 * the use site so future readers know why it survived.
 *
 * @implements Rule<FuncCall>
 */
final class SerializeAllowedRule implements Rule
{
    private const GUARDED = ['serialize', 'unserialize'];

    private const ALLOWED_BASENAMES = [
        'CheckIntegrity.php',
        'PreferencesService.php',
        // StringUtil::safeUnserialize() is the helper migrations call to
        // ingest legacy blobs; the implementation has to call unserialize.
        'StringUtil.php',
    ];

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
        if (in_array($basename, self::ALLOWED_BASENAMES, true)) {
            return [];
        }
        // Data migrations are name-shaped (Version<date><seq>.php) under
        // src/Piwigo/Migrations/. They keep safeUnserialize() / serialize()
        // for legacy data ingest.
        if (str_contains($file, '/src/Piwigo/Migrations/') && preg_match('/^Version\d+\.php$/', $basename)) {
            return [];
        }

        return [
            RuleErrorBuilder::message(
                sprintf(
                    'Do not introduce new %s() calls in src/. The B1-B10 storage refactor '
                    . 'moved every persisted blob to JSON, dedicated tables, or PSR-6. '
                    . 'If you need to ingest a legacy serialize() blob, do it in a Doctrine '
                    . 'migration; if you genuinely need an in-memory hash input, allow-list '
                    . 'the file in tools/phpstan/SerializeAllowedRule.php with a rationale.',
                    $funcName
                )
            )
                ->identifier('piwigo.serializeAllowed')
                ->build(),
        ];
    }
}
