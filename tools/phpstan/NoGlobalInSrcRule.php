<?php

declare(strict_types=1);

namespace Piwigo\Tools\PhpStan;

use PhpParser\Node;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Stmt\Global_;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Flags `global $conf`, `global $user`, `global $lang`, `global $template`
 * declarations inside src/ — prevents new typed-service code from pulling
 * in raw globals instead of calling typed accessors / Config::raw(),
 * PageState::current(), etc.
 *
 * @implements Rule<Global_>
 */
final class NoGlobalInSrcRule implements Rule
{
    private const GUARDED = [
        'conf', 'user', 'lang', 'template', 'logger', 'mysqli', 'service',
        'pwg_event_handlers', 'pwg_loaded_plugins', 'env_nbm', 'header_notes', 'themeconfs', 'cache', 'filter',
    ];

    private const REPLACEMENTS = [
        'conf' => 'Config typed accessor / Config::raw()',
        'user' => 'CurrentUser::get()',
        'lang' => 'Lang::t()',
        'template' => 'TemplateRegistry::current()',
        'logger' => 'LoggerRegistry::current()',
        'service' => 'PwgServerRegistry::current()',
        'mysqli' => 'get_dbal_connection() or Kernel::service(Connection::class)',
        'pwg_event_handlers' => 'EventDispatcher::addListener/dispatch/notify()',
        'pwg_loaded_plugins' => 'LoadedPluginRegistry::register/get/all()',
        'env_nbm' => 'MailNotificationContext::current()',
        'header_notes' => '$GLOBALS[\'header_notes\'] reference-bridge',
        'themeconfs' => 'instance property or $GLOBALS[\'themeconfs\'] reference-bridge',
        'cache' => 'RequestCache::remember/get/set/has()',
        'filter' => '$GLOBALS[\'filter\'] read with is_array narrowing',
    ];

    public function getNodeType(): string
    {
        return Global_::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        $file = $scope->getFile();
        if (
            !str_contains($file, DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR)
            && !str_contains($file, '/src/')
        ) {
            return [];
        }

        $errors = [];
        foreach ($node->vars as $var) {
            if (!($var instanceof Variable) || !is_string($var->name)) {
                continue;
            }
            if (!in_array($var->name, self::GUARDED, true)) {
                continue;
            }
            $replacement = self::REPLACEMENTS[$var->name];
            $errors[] = RuleErrorBuilder::message(
                "Avoid 'global \${$var->name}' in src/ — use {$replacement} instead."
            )->identifier('piwigo.noGlobalInSrc')->build();
        }
        return $errors;
    }
}
