<?php

declare(strict_types=1);

namespace Piwigo\Tools\PhpStan;

use PhpParser\Node;
use PhpParser\Node\Scalar\Int_;
use PhpParser\Node\Stmt\Declare_;
use PHPStan\Analyser\Scope;
use PHPStan\Node\FileNode;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Requires every PHP file under src/, include/, or admin/ to declare
 * strict_types=1. The global sweep already landed; this rule keeps new
 * files from regressing the invariant.
 *
 * @implements Rule<FileNode>
 */
final class StrictTypesRequiredRule implements Rule
{
    private const SCOPED_DIRS = ['src', 'include', 'admin'];

    public function getNodeType(): string
    {
        return FileNode::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        $file = $scope->getFile();
        if (!$this->isInScope($file)) {
            return [];
        }
        // phpstan-latte compiles `.latte` templates into PHP under
        // sys_get_temp_dir()/phpstan-latte/ for analysis, then remaps error
        // reports back onto the original .latte path. Skip both shapes:
        // either we see the .latte source name (no PHP `declare()` ever
        // belongs there) or the compiled file in the analyser tmp dir.
        if (str_ends_with($file, '.latte')) {
            return [];
        }
        if (str_contains($file, '/phpstan-latte/') || str_contains($file, '\\phpstan-latte\\')) {
            return [];
        }

        foreach ($node->getNodes() as $stmt) {
            if (!$stmt instanceof Declare_) {
                continue;
            }
            foreach ($stmt->declares as $declare) {
                if (
                    $declare->key->name === 'strict_types'
                    && $declare->value instanceof Int_
                    && $declare->value->value === 1
                ) {
                    return [];
                }
            }
        }

        return [
            RuleErrorBuilder::message(
                "File missing 'declare(strict_types=1);' — required in src/, include/, and admin/."
            )
                ->identifier('piwigo.strictTypesRequired')
                ->file($file)
                ->line(1)
                ->build(),
        ];
    }

    private function isInScope(string $file): bool
    {
        foreach (self::SCOPED_DIRS as $dir) {
            if (
                str_contains($file, DIRECTORY_SEPARATOR . $dir . DIRECTORY_SEPARATOR)
                || str_contains($file, '/' . $dir . '/')
            ) {
                return true;
            }
        }

        return false;
    }
}
