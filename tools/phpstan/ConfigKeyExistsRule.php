<?php

declare(strict_types=1);

namespace Piwigo\Tools\PhpStan;

use PhpParser\Node;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use Piwigo\Config\Config;

/**
 * Static-analysis safety net for Piwigo\Config\Config calls that take a key
 * argument:
 *
 *   - Config::raw / has / override / persist / delete with a string LITERAL
 *     key must reference a key that exists in Config::SCHEMA, OR be on the
 *     ALLOWED_RUNTIME_KEYS list (parametric / cache / sentinel keys that
 *     legitimately live outside the schema).
 *
 *   - Calls with a dynamic (non-literal) key argument are skipped — those
 *     are the legitimate dynamic-key escape hatch.
 *
 * Catches typos at static-analysis time, complementing the runtime
 * UnknownConfigKeyException (which only fires on the private typed-getter
 * helpers, not on the public raw/has/override/persist/delete surface).
 *
 * @implements Rule<StaticCall>
 */
final class ConfigKeyExistsRule implements Rule
{
    private const TARGET_CLASS = 'Piwigo\\Config\\Config';

    private const VALIDATED_METHODS = ['raw', 'has', 'override', 'persist', 'delete'];

    /**
     * Keys that are NOT in SCHEMA but ARE legitimately accessed via literal
     * has/override/persist/delete in first-party code. These are runtime
     * sentinels, derived caches, or admin-set path overrides — values that
     * don't belong in user-facing config but live in the conf table for
     * lack of a better store.
     *
     * Adding an entry here should be rare; prefer either (a) adding the key
     * to SCHEMA if it's user-configurable, or (b) moving to a dedicated
     * runtime store if it's a cache/sentinel.
     */
    private const ALLOWED_RUNTIME_KEYS = [
        // Derived caches: array_flip(picture_ext) and array_flip(file_ext),
        // populated lazily by admin/include/functions.php and consumed by
        // image upload validation.
        'flip_picture_ext',
        'flip_file_ext',
        // Sentinel: set once after the data dir is secured by .htaccess.
        'data_dir_checked',
        // Sentinel: set once after the no_photo_yet placeholder is generated.
        'no_photo_yet',
        // Admin path override: when set, points the gallery_url at a sibling
        // site (used by configuration.php's "site" view).
        'local_dir_site',
    ];

    public function getNodeType(): string
    {
        return StaticCall::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        if (!$node->class instanceof Name) {
            return [];
        }
        $className = $scope->resolveName($node->class);
        if ($className !== self::TARGET_CLASS) {
            return [];
        }
        // Per-plugin Config classes (src/Piwigo/Plugins/X/Config.php) own their
        // own SCHEMA and call Piwigo\Config\Config::override() with their plugin
        // keys to keep the reference bridge in sync. Those keys are legitimately
        // outside Piwigo's main SCHEMA — skip the rule for the plugin namespace.
        $file = $scope->getFile();
        if (
            str_contains($file, DIRECTORY_SEPARATOR . 'Plugins' . DIRECTORY_SEPARATOR)
            || str_contains($file, '/Plugins/')
        ) {
            return [];
        }
        if (!$node->name instanceof Identifier) {
            return [];
        }
        $methodName = $node->name->toString();
        if (!in_array($methodName, self::VALIDATED_METHODS, true)) {
            return [];
        }

        $args = $node->getArgs();
        if (count($args) === 0) {
            return [];
        }
        $keyArg = $args[0]->value;
        if (!$keyArg instanceof String_) {
            return []; // dynamic key — legitimate escape-hatch usage
        }
        $key = $keyArg->value;

        if (in_array($key, self::ALLOWED_RUNTIME_KEYS, true)) {
            return [];
        }
        if (array_key_exists($key, Config::SCHEMA)) {
            return [];
        }

        return [
            RuleErrorBuilder::message(
                "Config::{$methodName}('{$key}') uses a key not in Config::SCHEMA. "
                . 'Either add the key to SCHEMA + run tools/build-config-accessors.php, '
                . 'or add it to ConfigKeyExistsRule::ALLOWED_RUNTIME_KEYS if it is a '
                . 'genuine runtime sentinel / derived cache.'
            )->identifier('piwigo.configKeyExists')->build(),
        ];
    }
}
