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
 * argument: Config::has/override/delete with a string LITERAL key must
 * reference a key that exists in Config::SCHEMA, OR be on the
 * ALLOWED_RUNTIME_KEYS list (parametric/sentinel keys that legitimately
 * live outside the schema).
 *
 * Trimmed from the reference implementation's version: no `persist` method
 * (that's the DB-write path, deferred to P14 -- see ConfigService.php's
 * docblock) and no Plugin-namespace exemption (plugins don't exist until
 * P31). ALLOWED_RUNTIME_KEYS starts empty, grows only when a genuine
 * runtime sentinel/derived-cache key needs it.
 *
 * Calls with a dynamic (non-literal) key argument are skipped -- those are
 * legitimate ConfigService::confGetParam()-style dynamic dispatch.
 *
 * Catches typos at static-analysis time, complementing the runtime
 * UnknownConfigKeyException (which only fires on the private typed-getter
 * helpers, not on the public has/override/delete surface).
 *
 * @implements Rule<StaticCall>
 */
final class ConfigKeyExistsRule implements Rule
{
    private const string TARGET_CLASS = \Piwigo\Config\Config::class;

    private const array VALIDATED_METHODS = ['has', 'override', 'delete'];

    /**
     * Keys that are NOT in SCHEMA but ARE legitimately accessed via literal
     * has/override/delete in first-party code. Adding an entry here should
     * be rare; prefer adding the key to SCHEMA if it's user-configurable.
     *
     * Seeded from P13's own origin-vs-SCHEMA diff (docs/plan/manifest.yaml's
     * P13 commit message) -- the six origin conf keys that exist in
     * include/config_default.inc.php / install/config.sql but were
     * deliberately left out of Config::SCHEMA because they're read
     * dynamically (ConfigService::confGetParam()) or legacy-only:
     * blk_menubar (menubar layout, admin-editable, no SCHEMA-shaped
     * default), c13y_ignore / updates_ignored (legacy serialized-array
     * sentinels; updates_ignored becomes the extension_ignored_updates
     * table in P15), show_mobile_app_banner_in_admin/_in_gallery,
     * use_standard_pages (all three are admin toggles with no SCHEMA entry
     * yet, pending the admin UI that would own them).
     */
    private const array ALLOWED_RUNTIME_KEYS = [
        'blk_menubar',
        'c13y_ignore',
        'updates_ignored',
        'show_mobile_app_banner_in_admin',
        'show_mobile_app_banner_in_gallery',
        'use_standard_pages',
    ];

    public function getNodeType(): string
    {
        return StaticCall::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        if (! $node->class instanceof Name) {
            return [];
        }
        $className = $scope->resolveName($node->class);
        if ($className !== self::TARGET_CLASS) {
            return [];
        }
        if (! $node->name instanceof Identifier) {
            return [];
        }
        $methodName = $node->name->toString();
        if (! in_array($methodName, self::VALIDATED_METHODS, true)) {
            return [];
        }

        $args = $node->getArgs();
        if (count($args) === 0) {
            return [];
        }
        $keyArg = $args[0]->value;
        if (! $keyArg instanceof String_) {
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
                . 'genuine runtime sentinel.'
            )->identifier('piwigo.configKeyExists')
                ->build(),
        ];
    }
}
