<?php

declare(strict_types=1);

namespace Piwigo\Template\Latte;

use Latte\Sandbox\SecurityPolicy;

/**
 * Latte sandbox policy for Piwigo, applied to plugin-supplied templates
 * loaded outside `themes/` (e.g. via `PluginInterface::registerTemplate()`
 * once §1.3 lands). Core templates render through `LatteEngine::default()`
 * without a policy — they're trusted code.
 *
 * Two factories:
 * - {@see createPluginPolicy()} — default-deny allowlist for untrusted
 *   plugin templates. Permits structural tags, escape filters, the
 *   translation pair, and read-only Piwigo helpers. Denies anything that
 *   reads or writes the filesystem, mutates engine-side state (asset
 *   pipeline, html_head buffer), runs arbitrary PHP, or evaluates user
 *   expressions ({@see PiwigoExtension::math()}).
 * - {@see createCorePolicy()} — superset that additionally allows the
 *   asset-pipeline functions and the `php` / `include` tags. Used in
 *   tests and for the (rare) core call-site that wants explicit policy
 *   enforcement to catch unknown-filter typos at compile time.
 *
 * The class extends {@see SecurityPolicy} rather than wrapping it so the
 * caller can layer on additional `allow*()` calls when a particular
 * plugin needs an exception.
 */
final class PiwigoPolicy extends SecurityPolicy
{
    /**
     * Tags safe for plugin templates: control flow, output, structural.
     * Excludes `php`, `do`, `include`, `extends`, `layout`, `embed`,
     * `import`, `sandbox`, `snippet*`, `dump`, `debugbreak`,
     * `templatePrint`, `varPrint`, `contentType`.
     */
    private const array PLUGIN_TAGS = [
        '_', '=', 'l', 'r',
        'if', 'else', 'elseif', 'elseifset', 'ifset',
        'for', 'foreach', 'iterateWhile', 'while',
        'breakIf', 'continueIf', 'skipIf', 'first', 'last', 'sep',
        'switch', 'case', 'default',
        'try', 'rollback',
        'var', 'varType', 'templateType',
        'block', 'define', 'capture', 'attr', 'class',
        'spaceless', 'ifchanged', 'ifcontent',
        'translate',
    ];

    /**
     * Filters safe for plugin templates: Latte's escape/format defaults
     * plus Piwigo i18n + read-only helpers. Excludes anything that hits
     * the filesystem (`file_exists`, `is_file`), reflects PHP runtime
     * (`constant`), or decodes opaque payloads (`json_decode`,
     * `stripslashes`).
     */
    private const array PLUGIN_FILTERS = [
        // Latte built-ins (subset of createSafePolicy)
        'batch', 'breaklines', 'breakLines', 'bytes', 'capitalize', 'ceil', 'clamp', 'date',
        'escapeCss', 'escapeHtml', 'escapeHtmlComment', 'escapeICal', 'escapeJs', 'escapeUrl',
        'escapeXml', 'explode', 'first', 'firstUpper', 'floor', 'checkUrl', 'implode', 'indent',
        'join', 'last', 'length', 'lower', 'limit', 'number', 'padLeft', 'padRight', 'query',
        'random', 'repeat', 'replace', 'replaceRe', 'reverse', 'round', 'slice', 'sort',
        'spaceless', 'split', 'strip', 'striphtml', 'stripHtml', 'striptags', 'stripTags',
        'substr', 'trim', 'truncate', 'upper', 'webalize',
        // Piwigo i18n
        'translate', 'translate_dec', 'l10n',
        // Piwigo display / formatting
        'sprintf', 'urlencode', 'htmlspecialchars', 'nl2br', 'ucfirst',
        'number_format', 'count', 'strip_tags', 'str_repeat', 'default', 'date_format',
        // Piwigo read-only helpers
        'is_admin', 'is_classic_user', 'get_device', 'url_is_remote', 'get_extent',
        'get_gallery_home_url', 'explode', 'ternary', 'cat',
        // PHP-passthroughs that don't expose state
        'in_array', 'strstr', 'stristr', 'intval',
    ];

    /**
     * Functions safe for plugin templates: form-rendering helpers and
     * read-only lookups. Excludes the asset-pipeline functions
     * (`combineScript`, `combineCss`, `getCombinedScripts`,
     * `getCombinedCss`, `htmlHead`) — those mutate per-request state
     * owned by the core layout. Excludes `math()` because even though
     * the implementation regex-validates against a function whitelist
     * before eval, exposing it to plugin templates needs a deliberate
     * decision.
     */
    private const array PLUGIN_FUNCTIONS = [
        'htmlOptions', 'htmlRadios',
        'url_is_remote', 'l10n',
    ];

    /**
     * Default-deny allowlist for plugin templates.
     */
    public static function createPluginPolicy(): self
    {
        $policy = new self();
        $policy->allowTags(self::PLUGIN_TAGS);
        $policy->allowFilters(self::PLUGIN_FILTERS);
        $policy->allowFunctions(self::PLUGIN_FUNCTIONS);
        return $policy;
    }

    /**
     * Permissive policy for trusted core templates. Adds `do`, `include`,
     * `extends`, `layout`, `embed`, `import`, plus the asset-pipeline
     * functions and dangerous filters. The `php` tag remains disallowed
     * even for core — Latte expressions cover everything the templates
     * need.
     */
    public static function createCorePolicy(): self
    {
        $policy = new self();
        $policy->allowTags([
            ...self::PLUGIN_TAGS,
            'do', 'include', 'extends', 'layout', 'embed', 'import',
        ]);
        $policy->allowFilters([
            ...self::PLUGIN_FILTERS,
            'json_encode', 'stripslashes',
        ]);
        $policy->allowFunctions([
            ...self::PLUGIN_FUNCTIONS,
            'viteEntry', 'cssLink',
            'combineScript', 'getCombinedScripts',
            'combineCss', 'getCombinedCss',
            'htmlHead',
            'math',
        ]);
        return $policy;
    }
}
